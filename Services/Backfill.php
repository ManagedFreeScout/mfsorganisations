<?php

namespace Modules\MFSOrganisations\Services;

use Modules\MFSOrganisations\Entities\AddressLink;
use Modules\MFSOrganisations\Entities\Domain;
use Modules\MFSOrganisations\Entities\OrgConversation;
use Modules\MFSOrganisations\Entities\Organisation;

/**
 * Backfill for existing installs (card #423 §5): first a preview, then linking in batches.
 * It only matches the domains of organisations that already exist; it never creates one.
 * Batches run from the settings page (the page calls step() until done) and from FreeScout's
 * scheduler, so it finishes on shared hosting too, with or without the page open.
 */
class Backfill
{
    const OPTION_STATE = 'mfsorganisations.backfill';
    const EMAILS_PER_STEP = 300;
    const CONVERSATIONS_PER_STEP = 500;

    public static function preview()
    {
        $domains = [];
        foreach (Domain::all() as $d) {
            $domains[$d->domain] = $d->organisation_id;
        }
        $orgs = Organisation::get(['id', 'name', 'active', 'auto_link'])->keyBy('id');
        $links = AddressLink::pluck('source', 'email')->all();

        $p = [
            'total' => 0, 'linked' => 0, 'excluded' => 0, 'match' => 0, 'freemail' => 0, 'own' => 0,
            'nomatch' => 0, 'per_org' => [], 'unmatched_domains' => [],
        ];
        \App\Email::select('id', 'email')->orderBy('id')->chunk(2000, function ($emails) use (&$p, $domains, $orgs, $links) {
            foreach ($emails as $e) {
                $email = strtolower($e->email);
                $p['total']++;
                if (isset($links[$email])) {
                    $links[$email] == AddressLink::SOURCE_EXCLUDED ? $p['excluded']++ : $p['linked']++;
                    continue;
                }
                $domain = Linker::domainOf($email);
                $reason = Linker::blockedReason($domain);
                if ($reason) {
                    $p[$reason]++;
                    continue;
                }
                $org = isset($domains[$domain]) ? ($orgs[$domains[$domain]] ?? null) : null;
                if ($org && $org->active && $org->auto_link) {
                    $p['match']++;
                    $p['per_org'][$org->id] = ($p['per_org'][$org->id] ?? 0) + 1;
                } else {
                    $p['nomatch']++;
                    if (!isset($domains[$domain])) {
                        $p['unmatched_domains'][$domain] = ($p['unmatched_domains'][$domain] ?? 0) + 1;
                    }
                }
            }
        });
        arsort($p['per_org']);
        arsort($p['unmatched_domains']);
        // Domains used by one address only are mostly noise; show the 25 most common.
        $p['unmatched_domains'] = array_slice(array_filter($p['unmatched_domains'], function ($n) {
            return $n > 1;
        }), 0, 25, true);
        $p['org_names'] = $orgs->pluck('name', 'id')->all();
        $p['conversations_without'] = \App\Conversation::whereNotExists(function ($q) {
            $pre = \DB::getTablePrefix();
            $q->select(\DB::raw(1))->from('mfsorg_conversations')
                ->whereRaw("{$pre}mfsorg_conversations.conversation_id = {$pre}conversations.id");
        })->count();
        return $p;
    }

    public static function state()
    {
        $s = \Option::get(self::OPTION_STATE, []);
        return is_array($s) ? $s : [];
    }

    public static function isRunning()
    {
        return (self::state()['status'] ?? '') == 'running';
    }

    public static function start($user_id = null)
    {
        $state = [
            'status'     => 'running',
            'phase'      => 'addresses',
            'cursor'     => 0,
            'emails_total' => \App\Email::count(),
            'convs_total'  => \App\Conversation::count(),
            'emails_done'  => 0,
            'convs_done'   => 0,
            'linked'     => 0,
            'recorded'   => 0,
            'started_at' => now()->toDateTimeString(),
            'started_by' => $user_id,
        ];
        \Option::set(self::OPTION_STATE, $state);
        return $state;
    }

    /** One batch. Returns the new state. */
    public static function step()
    {
        // No webhook per linked address while the backfill runs.
        Webhooks::$muted = true;
        $state = self::state();
        if (($state['status'] ?? '') != 'running') {
            return $state;
        }
        // One step at a time (page and scheduler may both call).
        $lock = 'mfsorganisations_backfill_lock';
        if (!\Cache::add($lock, 1, 2)) {
            return $state;
        }
        try {
            if ($state['phase'] == 'addresses') {
                $emails = \App\Email::where('id', '>', $state['cursor'])->orderBy('id')->limit(self::EMAILS_PER_STEP)->get(['id', 'email']);
                foreach ($emails as $e) {
                    $existed = AddressLink::where('email', strtolower($e->email))->exists();
                    if (!$existed && Linker::linkAddress($e->email)) {
                        $state['linked']++;
                    }
                    $state['cursor'] = $e->id;
                    $state['emails_done']++;
                }
                if (count($emails) < self::EMAILS_PER_STEP) {
                    $state['phase'] = 'conversations';
                    $state['cursor'] = 0;
                }
            } else {
                $convs = \App\Conversation::where('id', '>', $state['cursor'])->orderBy('id')
                    ->limit(self::CONVERSATIONS_PER_STEP)->get(['id', 'customer_email', 'customer_id']);
                foreach ($convs as $c) {
                    if (Linker::recordConversation($c, OrgConversation::SOURCE_BACKFILL)) {
                        $state['recorded']++;
                    }
                    $state['cursor'] = $c->id;
                    $state['convs_done']++;
                }
                if (count($convs) < self::CONVERSATIONS_PER_STEP) {
                    $state['status'] = 'done';
                    $state['finished_at'] = now()->toDateTimeString();
                }
            }
            \Option::set(self::OPTION_STATE, $state);
        } finally {
            \Cache::forget($lock);
        }
        return $state;
    }

    /** 0–100 for the progress bar: addresses and conversations weigh half each. */
    public static function percent($state)
    {
        if (($state['status'] ?? '') == 'done') {
            return 100;
        }
        $a = !empty($state['emails_total']) ? min(1, $state['emails_done'] / $state['emails_total']) : 1;
        $c = !empty($state['convs_total']) ? min(1, $state['convs_done'] / $state['convs_total']) : 1;
        return (int) floor(($a + (($state['phase'] ?? '') == 'conversations' ? $c : 0)) * 50);
    }
}
