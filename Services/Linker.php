<?php

namespace Modules\MFSOrganisations\Services;

use Modules\MFSOrganisations\Entities\AddressLink;
use Modules\MFSOrganisations\Entities\Domain;
use Modules\MFSOrganisations\Entities\OrgConversation;
use Modules\MFSOrganisations\Entities\Organisation;

/**
 * The rules of card #423 in one place:
 * - the link is per e-mail address; an address belongs to at most one organisation;
 * - automatic only by an organisation's own domain list, never for free-mail domains or the
 *   helpdesk's own domains, and never for an address that was excluded by hand;
 * - a manual link always wins;
 * - a conversation gets the organisation of its address when it is created, and keeps it.
 */
class Linker
{
    const OPTION_FREEMAIL = 'mfsorganisations.freemail';

    protected static $freemail = null;
    protected static $own_domains = null;

    public static function domainOf($email)
    {
        $email = strtolower(trim((string) $email));
        $at = strrpos($email, '@');
        return $at === false ? '' : substr($email, $at + 1);
    }

    public static function freemailDomains()
    {
        if (self::$freemail === null) {
            $list = \Option::get(self::OPTION_FREEMAIL, null);
            if ($list === null || $list === '') {
                $list = implode("\n", config('mfsorganisations.freemail', []));
            }
            self::$freemail = array_flip(self::parseDomainList($list));
        }
        return self::$freemail;
    }

    public static function parseDomainList($text)
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string) $text) as $d) {
            $d = Domain::normalize($d);
            if ($d) {
                $out[$d] = $d;
            }
        }
        return array_values($out);
    }

    /** Domains of the mailboxes' own addresses and aliases. */
    public static function ownDomains()
    {
        if (self::$own_domains === null) {
            self::$own_domains = [];
            foreach (\App\Mailbox::all() as $mailbox) {
                foreach ($mailbox->getEmails() as $email) {
                    $d = self::domainOf($email);
                    if ($d) {
                        self::$own_domains[$d] = true;
                    }
                }
            }
        }
        return self::$own_domains;
    }

    /** Why a domain can't be linked automatically: 'freemail', 'own' or null. */
    public static function blockedReason($domain)
    {
        if (isset(self::freemailDomains()[$domain])) {
            return 'freemail';
        }
        if (isset(self::ownDomains()[$domain])) {
            return 'own';
        }
        return null;
    }

    /** The organisation whose domain list holds this address's domain (active, auto-link on). */
    public static function organisationForDomain($domain)
    {
        if (!$domain || self::blockedReason($domain)) {
            return null;
        }
        $row = Domain::where('domain', $domain)->first();
        if (!$row) {
            return null;
        }
        $org = Organisation::find($row->organisation_id);
        return ($org && $org->active && $org->auto_link) ? $org : null;
    }

    /**
     * The address's link, creating a domain link when there is none and the domain matches.
     * Returns the AddressLink or null.
     */
    public static function linkAddress($email)
    {
        $email = strtolower(trim((string) $email));
        if (!$email || strlen($email) > 191) {
            return null;
        }
        $link = AddressLink::where('email', $email)->first();
        if ($link) {
            return $link;
        }
        $org = self::organisationForDomain(self::domainOf($email));
        if (!$org) {
            return null;
        }
        try {
            return AddressLink::create([
                'email'           => $email,
                'organisation_id' => $org->id,
                'source'          => AddressLink::SOURCE_DOMAIN,
                'active'          => true,
            ]);
        } catch (\Exception $e) {
            // Created at the same moment by another request (unique e-mail).
            return AddressLink::where('email', $email)->first();
        }
    }

    /** Organisation id for an address today, or null. Links the address by domain if needed. */
    public static function organisationIdForEmail($email, $create_link = true)
    {
        $link = $create_link ? self::linkAddress($email) : AddressLink::where('email', strtolower(trim((string) $email)))->first();
        if ($link) {
            return $link->isInForce() ? (int) $link->organisation_id : null;
        }
        if (!$create_link) {
            $org = self::organisationForDomain(self::domainOf($email));
            return $org ? (int) $org->id : null;
        }
        return null;
    }

    /**
     * What a conversation's organisation would be now: its address's organisation, else (no
     * address) the customer's organisation when the customer has exactly one.
     * Returns [organisation_id, department_id, source] or null.
     */
    public static function resolveConversation($conversation, $create_link = true)
    {
        $email = $conversation->customer_email;
        if ($email) {
            $org_id = self::organisationIdForEmail($email, $create_link);
            if (!$org_id) {
                return null;
            }
            $link = AddressLink::where('email', strtolower($email))->first();
            return [$org_id, $link ? $link->department_id : null, OrgConversation::SOURCE_ADDRESS];
        }
        $customer = $conversation->customer_id ? \App\Customer::find($conversation->customer_id) : null;
        if (!$customer) {
            return null;
        }
        $ids = [];
        foreach ($customer->emails as $e) {
            $id = self::organisationIdForEmail($e->email, $create_link);
            if ($id) {
                $ids[$id] = true;
            }
        }
        return count($ids) == 1 ? [key($ids), null, OrgConversation::SOURCE_CUSTOMER] : null;
    }

    /** Records the organisation of a new conversation. Never overwrites an existing record. */
    public static function recordConversation($conversation, $source = null)
    {
        if (!$conversation || !$conversation->id || OrgConversation::where('conversation_id', $conversation->id)->exists()) {
            return null;
        }
        $resolved = self::resolveConversation($conversation);
        if (!$resolved) {
            return null;
        }
        try {
            return OrgConversation::create([
                'conversation_id' => $conversation->id,
                'organisation_id' => $resolved[0],
                'department_id'   => $resolved[1],
                'source'          => $source ?: $resolved[2],
            ]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /** The organisation id of a conversation: its record, else what it would be now (no writes). */
    public static function conversationOrganisationId($conversation)
    {
        $rec = OrgConversation::where('conversation_id', $conversation->id)->first();
        if ($rec) {
            return (int) $rec->organisation_id;
        }
        $resolved = self::resolveConversation($conversation, false);
        return $resolved ? (int) $resolved[0] : null;
    }

    /**
     * Link existing addresses with this domain to the organisation and record the organisation on
     * their conversations that have none yet. Used when a domain is added. Returns counts.
     */
    public static function applyDomain(Organisation $org, $domain)
    {
        $counts = ['addresses' => 0, 'conversations' => 0];
        if (!$org->active || !$org->auto_link || self::blockedReason($domain)) {
            return $counts;
        }
        \App\Email::where('email', 'like', '%@'.str_replace(['%', '_'], ['\%', '\_'], $domain))
            ->orderBy('id')->chunk(500, function ($emails) use ($org, $domain, &$counts) {
                foreach ($emails as $e) {
                    if (self::domainOf($e->email) !== $domain) {
                        continue;
                    }
                    $link = AddressLink::where('email', strtolower($e->email))->first();
                    if (!$link) {
                        $link = self::linkAddress($e->email);
                        if ($link) {
                            $counts['addresses']++;
                        }
                    }
                    if ($link && $link->isInForce() && $link->organisation_id == $org->id) {
                        $counts['conversations'] += self::recordConversationsOfEmail($e->email, $org->id, $link->department_id, OrgConversation::SOURCE_BACKFILL);
                    }
                }
            });
        return $counts;
    }

    /** Records an organisation on the conversations of an address that have none yet. */
    public static function recordConversationsOfEmail($email, $org_id, $department_id, $source)
    {
        $n = 0;
        \App\Conversation::where('customer_email', $email)
            ->whereNotExists(function ($q) {
                $p = \DB::getTablePrefix();
                $q->select(\DB::raw(1))->from('mfsorg_conversations')
                    ->whereRaw("{$p}mfsorg_conversations.conversation_id = {$p}conversations.id");
            })
            ->select('id')->orderBy('id')->chunk(500, function ($convs) use ($org_id, $department_id, $source, &$n) {
                foreach ($convs as $c) {
                    try {
                        OrgConversation::create([
                            'conversation_id' => $c->id,
                            'organisation_id' => $org_id,
                            'department_id'   => $department_id,
                            'source'          => $source,
                        ]);
                        $n++;
                    } catch (\Exception $e) {
                        // Recorded meanwhile.
                    }
                }
            });
        return $n;
    }

    /** Moves the recorded conversations of an address to another organisation (asked, default no). */
    public static function moveConversationsOfEmail($email, $org_id, $department_id)
    {
        $ids = \App\Conversation::where('customer_email', $email)->pluck('id')->all();
        if (!$ids) {
            return 0;
        }
        $n = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $n += OrgConversation::whereIn('conversation_id', $chunk)
                ->update(['organisation_id' => $org_id, 'department_id' => $department_id, 'updated_at' => now()]);
        }
        return $n + self::recordConversationsOfEmail($email, $org_id, $department_id, OrgConversation::SOURCE_AGENT);
    }

    /**
     * Takes an address's past conversations out of an organisation (the address was removed: it
     * doesn't belong there). Conversations an agent set by hand with "Change" stay as they are.
     */
    public static function unlinkConversationsOfEmail($email, $org_id)
    {
        $ids = \App\Conversation::where('customer_email', $email)->pluck('id')->all();
        $n = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $n += OrgConversation::whereIn('conversation_id', $chunk)
                ->where('organisation_id', $org_id)
                ->where('source', '!=', OrgConversation::SOURCE_AGENT)
                ->delete();
        }
        return $n;
    }

    /** How many past conversations per address unlinkConversationsOfEmail() would take out. */
    public static function unlinkableCounts(array $emails, $org_id)
    {
        if (!$emails) {
            return [];
        }
        return \DB::table('mfsorg_conversations')
            ->join('conversations', 'conversations.id', '=', 'mfsorg_conversations.conversation_id')
            ->where('mfsorg_conversations.organisation_id', $org_id)
            ->where('mfsorg_conversations.source', '!=', OrgConversation::SOURCE_AGENT)
            ->whereIn('conversations.customer_email', $emails)
            ->groupBy('conversations.customer_email')
            ->select('conversations.customer_email', \DB::raw('count(*) as n'))
            ->get()
            ->mapWithKeys(function ($row) { return [strtolower($row->customer_email) => (int) $row->n]; })
            ->all();
    }

    /** Forget the per-request caches (after the free-mail list or mailboxes change). */
    public static function flush()
    {
        self::$freemail = null;
        self::$own_domains = null;
    }
}
