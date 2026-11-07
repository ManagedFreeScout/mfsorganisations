<?php

namespace Modules\MFSOrganisations\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\MFSOrganisations\Entities\AddressLink;
use Modules\MFSOrganisations\Entities\Department;
use Modules\MFSOrganisations\Entities\Domain;
use Modules\MFSOrganisations\Entities\OrgConversation;
use Modules\MFSOrganisations\Entities\Organisation;
use Modules\MFSOrganisations\Providers\MFSOrganisationsServiceProvider as Provider;
use Modules\MFSOrganisations\Services\LicenseService;
use Modules\MFSOrganisations\Services\Linker;

class OrganisationsController extends Controller
{
    const PER_PAGE = 50;

    protected function guard()
    {
        if (!LicenseService::isLicensed()) {
            abort(403, __('MFS Organisations needs a valid licence.'));
        }
    }

    protected function guardManage()
    {
        $this->guard();
        if (!Provider::canManage()) {
            abort(403);
        }
    }

    protected function guardAdmin()
    {
        $this->guard();
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }
    }

    /** The organisation, if this agent may see it. */
    protected function findVisible($id)
    {
        $org = Organisation::findOrFail($id);
        if (!$org->isVisibleTo(Auth::user())) {
            abort(403);
        }
        return $org;
    }

    /** Organisations list with counts, search, filters and sorting (scope §4.1). */
    public function index(Request $request)
    {
        $this->guard();
        $user = Auth::user();
        $p = \DB::getTablePrefix();

        $query = Organisation::visibleTo($user)
            ->select('mfsorg_organisations.*')
            ->selectRaw("(select count(*) from {$p}mfsorg_address_links l where l.organisation_id = {$p}mfsorg_organisations.id) as addresses_count")
            ->selectRaw("(select count(*) from {$p}mfsorg_conversations oc where oc.organisation_id = {$p}mfsorg_organisations.id) as conversations_count")
            ->selectRaw("(select count(*) from {$p}mfsorg_conversations oc join {$p}conversations c on c.id = oc.conversation_id"
                ." where oc.organisation_id = {$p}mfsorg_organisations.id and c.state = ".\App\Conversation::STATE_PUBLISHED
                ." and c.status in (".\App\Conversation::STATUS_ACTIVE.",".\App\Conversation::STATUS_PENDING.")) as open_count")
            ->selectRaw("(select group_concat(d.domain order by d.domain separator ', ') from {$p}mfsorg_domains d where d.organisation_id = {$p}mfsorg_organisations.id) as domain_list");

        $q = trim((string) $request->input('q'));
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(function ($w) use ($like) {
                $w->where('mfsorg_organisations.name', 'like', $like)
                    ->orWhereIn('mfsorg_organisations.id', function ($sub) use ($like) {
                        $sub->select('organisation_id')->from('mfsorg_domains')->where('domain', 'like', $like);
                    });
            });
        }
        if ($request->filled('type') && isset(Organisation::TYPES[$request->input('type')])) {
            $query->where('type', $request->input('type'));
        }
        $status = $request->input('status', 'active');
        if ($status == 'active') {
            $query->where('active', true);
        } elseif ($status == 'inactive') {
            $query->where('active', false);
        }
        if ($request->filled('mailbox')) {
            $mailbox_id = (int) $request->input('mailbox');
            $query->where(function ($w) use ($mailbox_id, $p) {
                $w->whereIn('mfsorg_organisations.id', function ($sub) use ($mailbox_id) {
                    $sub->select('organisation_id')->from('mfsorg_mailboxes')->where('mailbox_id', $mailbox_id);
                })->orWhereNotExists(function ($sub) use ($p) {
                    $sub->select(\DB::raw(1))->from('mfsorg_mailboxes')
                        ->whereRaw("{$p}mfsorg_mailboxes.organisation_id = {$p}mfsorg_organisations.id");
                });
            });
        }

        $sorts = ['name' => 'name', 'type' => 'type', 'addresses' => 'addresses_count', 'open' => 'open_count', 'total' => 'conversations_count'];
        $sort = isset($sorts[$request->input('sort')]) ? $request->input('sort') : 'name';
        $dir = $request->input('dir') == 'desc' ? 'desc' : 'asc';
        $query->orderBy($sorts[$sort], $dir);
        if ($sort != 'name') {
            $query->orderBy('name');
        }

        return view('mfsorganisations::organisations.index', [
            'orgs'      => $query->paginate(self::PER_PAGE)->appends($request->except('page')),
            'q'         => $q,
            'status'    => $status,
            'sort'      => $sort,
            'dir'       => $dir,
            'mailboxes' => $user->mailboxesCanView(),
            'can_manage' => Provider::canManage(),
        ]);
    }

    public function create()
    {
        $this->guardManage();
        return view('mfsorganisations::organisations.create', [
            'org'       => new Organisation(['color' => Organisation::COLORS[array_rand(Organisation::COLORS)], 'active' => true, 'auto_link' => true, 'type' => 'company']),
            'mailboxes' => \App\Mailbox::orderBy('name')->get(['id', 'name']),
            // From the backfill preview's "create organisation" link.
            'prefill_domain' => Domain::normalize(request()->input('domain')),
        ]);
    }

    public function store(Request $request)
    {
        $this->guardManage();
        $request->validate(['name' => 'required|max:191|unique:mfsorg_organisations,name']);
        $org = new Organisation();
        $this->fill($org, $request);
        $org->save();
        $this->saveMailboxes($org, $request);
        $report = $this->addDomains($org, (string) $request->input('domains'));

        \Session::flash('flash_success_floating', __('Organisation created.'));
        $this->flashDomainReport($report);
        return redirect()->to($org->url());
    }

    public function show(Request $request, $id)
    {
        $this->guard();
        $org = $this->findVisible($id);
        $tab = in_array($request->input('tab'), ['addresses', 'conversations', 'departments']) ? $request->input('tab') : 'details';
        $user = Auth::user();
        $data = [
            'org'         => $org,
            'tab'         => $tab,
            'can_manage'  => Provider::canManage(),
            'is_admin'    => $user->isAdmin(),
            'departments' => Department::treeOptions($org->id),
            'counts'      => [
                'addresses'     => AddressLink::where('organisation_id', $org->id)->count(),
                'conversations' => OrgConversation::where('organisation_id', $org->id)->count(),
            ],
        ];

        if ($tab == 'details') {
            $data['mailboxes'] = \App\Mailbox::orderBy('name')->get(['id', 'name']);
            $data['mailbox_ids'] = $org->mailboxIds();
            if ($user->isAdmin()) {
                $data['merge_targets'] = Organisation::where('id', '!=', $org->id)->orderBy('name')->get(['id', 'name']);
            }
        } elseif ($tab == 'addresses') {
            $links = AddressLink::where('organisation_id', $org->id)->orderBy('email')->paginate(self::PER_PAGE, ['*'], 'page')->appends(['tab' => 'addresses']);
            $customers = [];
            foreach (\App\Email::whereIn('email', $links->pluck('email')->all() ?: [''])->with('customer')->get() as $e) {
                $customers[strtolower($e->email)] = $e->customer;
            }
            $data['links'] = $links;
            $data['customers'] = $customers;
            $data['unlinkable'] = Linker::unlinkableCounts($links->pluck('email')->all(), $org->id);
            $data['excluded'] = AddressLink::where('source', AddressLink::SOURCE_EXCLUDED)
                ->where(function ($w) use ($org) {
                    foreach ($org->domains as $d) {
                        $w->orWhere('email', 'like', '%@'.$d->domain);
                    }
                    $w->orWhereRaw('1 = 0');
                })->orderBy('email')->get();
        } elseif ($tab == 'conversations') {
            $query = $this->conversationsQuery($org, $request);
            $data['conversations'] = $query->with('mailbox', 'customer')->orderBy('last_reply_at', 'desc')
                ->paginate(self::PER_PAGE)->appends($request->except('page'));
            $data['mailboxes'] = $user->mailboxesCanView();
        }

        return view('mfsorganisations::organisations.show', $data);
    }

    /** This organisation's conversations in the agent's own mailboxes (scope §6). */
    protected function conversationsQuery(Organisation $org, Request $request)
    {
        $user = Auth::user();
        $query = \App\Conversation::whereIn('id', function ($q) use ($org) {
            $q->select('conversation_id')->from('mfsorg_conversations')->where('organisation_id', $org->id);
        })->whereIn('mailbox_id', $user->mailboxesIdsCanView() ?: [0])
            ->where('state', \App\Conversation::STATE_PUBLISHED);
        if ($request->filled('mailbox')) {
            $query->where('mailbox_id', (int) $request->input('mailbox'));
        }
        $status = $request->input('status');
        if ($status == 'open') {
            $query->whereIn('status', [\App\Conversation::STATUS_ACTIVE, \App\Conversation::STATUS_PENDING]);
        } elseif (in_array($status, ['1', '2', '3'], true)) {
            $query->where('status', (int) $status);
        }
        return $query;
    }

    public function update(Request $request, $id)
    {
        $this->guardManage();
        $org = $this->findVisible($id);
        $request->validate(['name' => 'required|max:191|unique:mfsorg_organisations,name,'.$org->id]);
        $was_auto = $org->active && $org->auto_link;
        $this->fill($org, $request);
        $org->save();
        $this->saveMailboxes($org, $request);

        // Domains: removed ones go; new ones are linked right away.
        $wanted = Linker::parseDomainList((string) $request->input('domains'));
        Domain::where('organisation_id', $org->id)->whereNotIn('domain', $wanted ?: [''])->get()->each->delete();
        $report = $this->addDomains($org, implode("\n", $wanted));
        // Auto-linking switched (back) on: apply all its domains.
        if (!$was_auto && $org->active && $org->auto_link) {
            foreach ($org->domains()->get() as $d) {
                $c = Linker::applyDomain($org, $d->domain);
                $report['linked'] += $c['addresses'];
                $report['recorded'] += $c['conversations'];
            }
        }

        \Session::flash('flash_success_floating', __('Organisation saved.'));
        $this->flashDomainReport($report);
        return redirect()->to($org->url());
    }

    protected function fill(Organisation $org, Request $request)
    {
        $org->name = trim((string) $request->input('name'));
        $org->type = isset(Organisation::TYPES[$request->input('type')]) ? $request->input('type') : 'other';
        $org->active = (bool) $request->input('active');
        $org->auto_link = (bool) $request->input('auto_link');
        $color = (string) $request->input('color');
        $org->color = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : Organisation::COLORS[0];
        foreach (['coc_number' => 50, 'vat_number' => 50, 'phone' => 60, 'website' => 191] as $field => $max) {
            $v = trim((string) $request->input($field));
            $org->$field = $v === '' ? null : mb_substr($v, 0, $max);
        }
        $country = strtoupper(trim((string) $request->input('country')));
        $org->country = preg_match('/^[A-Z]{2}$/', $country) ? $country : null;
        $org->address = trim((string) $request->input('address')) ?: null;
        $org->notes = trim((string) $request->input('notes')) ?: null;
        $org->alert = mb_substr(trim((string) $request->input('alert')), 0, 255) ?: null;
    }

    protected function saveMailboxes(Organisation $org, Request $request)
    {
        self::setMailboxes($org, (array) $request->input('mailboxes', []));
    }

    /** The mailboxes an organisation belongs to; all (or none) given = every mailbox. Also used by the API. */
    public static function setMailboxes(Organisation $org, array $ids)
    {
        $ids = array_map('intval', $ids);
        $before = $org->mailboxIds();
        \DB::table('mfsorg_mailboxes')->where('organisation_id', $org->id)->delete();
        // All mailboxes ticked = no restriction, so new mailboxes are included too.
        if ($ids && count($ids) < \App\Mailbox::count()) {
            foreach (array_unique($ids) as $mailbox_id) {
                \DB::table('mfsorg_mailboxes')->insert(['organisation_id' => $org->id, 'mailbox_id' => $mailbox_id]);
            }
        }
        // A change of mailboxes is a change of the organisation (updated_at, "organisation.updated" webhook).
        $after = $org->mailboxIds();
        sort($before);
        sort($after);
        if ($before != $after) {
            $org->touch();
        }
    }

    /** Adds domains; a domain of another organisation, a free-mail or an own domain is refused. */
    /** Also used by the API, so both apply the same rules. */
    public static function addDomains(Organisation $org, $text)
    {
        $report = ['refused' => [], 'linked' => 0, 'recorded' => 0];
        foreach (Linker::parseDomainList($text) as $domain) {
            $existing = Domain::where('domain', $domain)->first();
            if ($existing) {
                if ($existing->organisation_id != $org->id) {
                    $other = Organisation::find($existing->organisation_id);
                    $report['refused'][] = __(':domain already belongs to :org', ['domain' => $domain, 'org' => $other ? $other->name : '?']);
                }
                continue;
            }
            $reason = Linker::blockedReason($domain);
            if ($reason) {
                $report['refused'][] = $reason == 'freemail'
                    ? __(':domain is a free-mail domain: link its addresses by hand', ['domain' => $domain])
                    : __(':domain is one of your own mailbox domains', ['domain' => $domain]);
                continue;
            }
            Domain::create(['organisation_id' => $org->id, 'domain' => $domain]);
            $c = Linker::applyDomain($org, $domain);
            $report['linked'] += $c['addresses'];
            $report['recorded'] += $c['conversations'];
        }
        return $report;
    }

    protected function flashDomainReport($report)
    {
        if ($report['linked'] || $report['recorded']) {
            \Session::flash('flash_success', __(':a existing addresses linked, :c existing conversations added.', ['a' => $report['linked'], 'c' => $report['recorded']]));
        }
        if ($report['refused']) {
            \Session::flash('flash_warning', __('Not added').': '.implode('; ', $report['refused']));
        }
    }

    /** Delete only an empty organisation; otherwise deactivate it (scope §2.5). */
    public function destroy($id)
    {
        $this->guardAdmin();
        $org = Organisation::findOrFail($id);
        if (AddressLink::where('organisation_id', $org->id)->exists() || OrgConversation::where('organisation_id', $org->id)->exists()) {
            \Session::flash('flash_error_floating', __('This organisation still has addresses or conversations. Deactivate it instead, or merge it into another one.'));
            return redirect()->to($org->url());
        }
        Domain::where('organisation_id', $org->id)->delete();
        Department::where('organisation_id', $org->id)->delete();
        \DB::table('mfsorg_mailboxes')->where('organisation_id', $org->id)->delete();
        $org->delete();
        \Session::flash('flash_success_floating', __('Organisation deleted.'));
        return redirect()->route('mfsorganisations.index');
    }

    /** Merge this organisation into another: addresses, conversations, domains and departments move. */
    public function merge(Request $request, $id)
    {
        $this->guardAdmin();
        $org = Organisation::findOrFail($id);
        $target = Organisation::findOrFail((int) $request->input('target_id'));
        if ($target->id == $org->id) {
            return redirect()->to($org->url());
        }
        \DB::transaction(function () use ($org, $target) {
            AddressLink::where('organisation_id', $org->id)->update(['organisation_id' => $target->id]);
            OrgConversation::where('organisation_id', $org->id)->update(['organisation_id' => $target->id]);
            Domain::where('organisation_id', $org->id)->update(['organisation_id' => $target->id]);
            Department::where('organisation_id', $org->id)->update(['organisation_id' => $target->id]);
            // The target keeps its own mailbox setting.
            \DB::table('mfsorg_mailboxes')->where('organisation_id', $org->id)->delete();
            $org->delete();
        });
        \Session::flash('flash_success_floating', e(__(':from was merged into :to.', ['from' => $org->name, 'to' => $target->name])));
        return redirect()->to($target->url());
    }

    /** Addresses tab actions: add, update (department, active, dates), remove (= exclude). */
    public function addresses(Request $request, $id)
    {
        $this->guardManage();
        $org = $this->findVisible($id);
        $action = $request->input('action');

        if ($action == 'add') {
            $emails = preg_split('/[\s,;]+/', strtolower((string) $request->input('emails')), -1, PREG_SPLIT_NO_EMPTY);
            $added = 0;
            $bad = [];
            foreach ($emails as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
                    $bad[] = $email;
                    continue;
                }
                self::setAddressLink($email, (string) $org->id, (bool) $request->input('move'));
                $added++;
            }
            if ($added) {
                \Session::flash('flash_success_floating', __(':n addresses linked.', ['n' => $added]));
            }
            if ($bad) {
                \Session::flash('flash_warning', __('Not a valid e-mail address').': '.implode(', ', $bad));
            }
        } else {
            $link = AddressLink::where('id', (int) $request->input('link_id'))->where('organisation_id', $org->id)->first();
            if ($link && $action == 'update') {
                $dept = (int) $request->input('department_id');
                $link->department_id = ($dept && Department::where('id', $dept)->where('organisation_id', $org->id)->exists()) ? $dept : null;
                $link->active = (bool) $request->input('active');
                $link->active_from = $this->date($request->input('active_from'));
                $link->active_to = $this->date($request->input('active_to'));
                $link->save();
                \Session::flash('flash_success_floating', __('Saved.'));
            } elseif ($link && $action == 'remove') {
                $n = self::setAddressLink($link->email, 'exclude', false);
                \Session::flash('flash_success_floating', e(__(':email removed from :org', ['email' => $link->email, 'org' => $org->name]).' — '.trans_choice('{0} no past conversations to unlink|{1} 1 past conversation unlinked|[2,*] :n past conversations unlinked', (int) $n, ['n' => (int) $n]).'; '.__('new ones will not be linked automatically.')));
            }
        }
        return redirect()->to($org->url('addresses'));
    }

    protected function date($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->toDateString();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Sets an address's organisation by hand. $value: an organisation id, 'exclude' (doesn't
     * belong: its past conversations leave the organisation, except ones set by hand, and it is
     * never linked automatically again; returns how many were unlinked) or '' (back to automatic:
     * the domain decides). $move: also move its past conversations (asked, default no).
     */
    public static function setAddressLink($email, $value, $move = false)
    {
        $email = strtolower(trim($email));
        $link = AddressLink::where('email', $email)->first();

        if ($value === '') {
            if ($link) {
                $link->delete();
            }
            $link = Linker::linkAddress($email);
        } elseif ($value === 'exclude') {
            $previous = $link ? $link->organisation_id : null;
            $link = $link ?: new AddressLink(['email' => $email]);
            $link->organisation_id = null;
            $link->department_id = null;
            $link->source = AddressLink::SOURCE_EXCLUDED;
            $link->save();
            return $previous ? Linker::unlinkConversationsOfEmail($email, $previous) : 0;
        } else {
            $org = Organisation::find((int) $value);
            if (!$org) {
                return;
            }
            if ($link && $link->organisation_id == $org->id && $link->source != AddressLink::SOURCE_EXCLUDED) {
                $link->source = AddressLink::SOURCE_MANUAL;
                $link->save();
            } else {
                $link = $link ?: new AddressLink(['email' => $email]);
                $link->organisation_id = $org->id;
                $link->department_id = null;
                $link->source = AddressLink::SOURCE_MANUAL;
                $link->active = true;
                $link->save();
            }
        }

        if ($link && $link->organisation_id) {
            if ($move) {
                Linker::moveConversationsOfEmail($email, $link->organisation_id, $link->department_id);
            } else {
                // Conversations without an organisation yet always get it.
                Linker::recordConversationsOfEmail($email, $link->organisation_id, $link->department_id, OrgConversation::SOURCE_BACKFILL);
            }
        }
    }

    /**
     * Kanban's organisation filter: GET lists the organisations and the current choice, POST
     * sets it (empty = all). Kept per agent in the session, see Provider::registerKanbanHooks().
     */
    public function kanbanFilter(Request $request)
    {
        $this->guard();
        $orgs = Organisation::visibleTo(Auth::user())->orderBy('name')->get(['id', 'name']);
        if ($request->isMethod('post')) {
            $id = (int) $request->input('organisation_id');
            if ($id && $orgs->contains('id', $id)) {
                session([Provider::SESSION_KANBAN_ORG => $id]);
            } else {
                session()->forget(Provider::SESSION_KANBAN_ORG);
            }
        }
        $current = (int) session(Provider::SESSION_KANBAN_ORG);
        return response()->json([
            'current' => $orgs->contains('id', $current) ? $current : null,
            'orgs'    => $orgs->map(function ($o) { return ['id' => $o->id, 'name' => $o->name]; })->values(),
            'labels'  => ['all' => __('All organisations'), 'title' => __('Organisation')],
        ]);
    }

    /** Departments tab: add, rename, move, delete. */
    public function departments(Request $request, $id)
    {
        $this->guardManage();
        $org = $this->findVisible($id);
        $action = $request->input('action');
        $name = mb_substr(trim((string) $request->input('name')), 0, 191);
        $parent = (int) $request->input('parent_id');
        $parent = ($parent && Department::where('id', $parent)->where('organisation_id', $org->id)->exists()) ? $parent : null;

        if ($action == 'add' && $name !== '') {
            Department::create(['organisation_id' => $org->id, 'parent_id' => $parent, 'name' => $name]);
        } else {
            $dept = Department::where('id', (int) $request->input('department_id'))->where('organisation_id', $org->id)->first();
            if ($dept && $action == 'update' && $name !== '') {
                // No department under itself or its own children.
                $p = $parent;
                for ($i = 0; $p && $i < 20; $i++) {
                    if ($p == $dept->id) {
                        $parent = $dept->parent_id;
                        break;
                    }
                    $p = Department::where('id', $p)->value('parent_id');
                }
                $dept->update(['name' => $name, 'parent_id' => $parent]);
            } elseif ($dept && $action == 'delete') {
                Department::where('parent_id', $dept->id)->update(['parent_id' => $dept->parent_id]);
                AddressLink::where('department_id', $dept->id)->update(['department_id' => null]);
                OrgConversation::where('department_id', $dept->id)->update(['department_id' => null]);
                $dept->delete();
            }
        }
        return redirect()->to($org->url('departments'));
    }

    /** CSV of this organisation's conversations (in the agent's mailboxes). */
    public function export(Request $request, $id)
    {
        $this->guard();
        $org = $this->findVisible($id);
        $query = $this->conversationsQuery($org, $request)->with('mailbox')->orderBy('id');
        $filename = 'conversations-'.str_slug($org->name).'-'.date('Y-m-d').'.csv';

        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Number', 'Subject', 'Customer e-mail', 'Mailbox', 'Status', 'Created', 'Last reply']);
            $query->chunk(500, function ($convs) use ($out) {
                foreach ($convs as $c) {
                    fputcsv($out, [
                        $c->number, $c->subject, $c->customer_email, $c->mailbox ? $c->mailbox->name : '',
                        $c->getStatusName(), (string) $c->created_at, (string) $c->last_reply_at,
                    ]);
                }
            });
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * CSV import: name, type, domains, coc_number, vat_number, country, phone, website.
     * A row whose name exists updates that organisation. Domains are separated by spaces or ";".
     */
    public function import(Request $request)
    {
        $this->guardManage();
        $file = $request->file('csv');
        if (!$file || !$file->isValid()) {
            \Session::flash('flash_error_floating', __('Choose a CSV file.'));
            return redirect()->route('mfsorganisations.index');
        }
        $handle = fopen($file->getRealPath(), 'r');
        $first = fgets($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(function ($h) {
            return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h)));
        }, str_getcsv(trim($first), $delimiter));
        if (!in_array('name', $header)) {
            fclose($handle);
            \Session::flash('flash_error_floating', __('The first line must hold the column names, with at least "name".'));
            return redirect()->route('mfsorganisations.index');
        }
        $created = $updated = 0;
        $refused = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) == 1 && trim($row[0]) === '') {
                continue;
            }
            $data = [];
            foreach ($header as $i => $col) {
                $data[$col] = trim((string) ($row[$i] ?? ''));
            }
            if ($data['name'] === '') {
                continue;
            }
            $org = Organisation::where('name', $data['name'])->first();
            $is_new = !$org;
            $org = $org ?: new Organisation(['name' => mb_substr($data['name'], 0, 191), 'active' => true, 'auto_link' => true,
                'color' => Organisation::COLORS[array_rand(Organisation::COLORS)]]);
            if (!empty($data['type'])) {
                $type = str_replace(['-', ' '], '_', strtolower($data['type']));
                $org->type = isset(Organisation::TYPES[$type]) ? $type : 'other';
            }
            foreach (['coc_number' => 50, 'vat_number' => 50, 'phone' => 60, 'website' => 191] as $field => $max) {
                if (!empty($data[$field])) {
                    $org->$field = mb_substr($data[$field], 0, $max);
                }
            }
            if (!empty($data['country']) && preg_match('/^[A-Za-z]{2}$/', $data['country'])) {
                $org->country = strtoupper($data['country']);
            }
            $org->save();
            $is_new ? $created++ : $updated++;
            if (!empty($data['domains'])) {
                $report = $this->addDomains($org, $data['domains']);
                $refused = array_merge($refused, $report['refused']);
            }
        }
        fclose($handle);
        \Session::flash('flash_success', __('Import done: :c created, :u updated.', ['c' => $created, 'u' => $updated]));
        if ($refused) {
            \Session::flash('flash_warning', __('Domains not added').': '.implode('; ', array_slice($refused, 0, 20)));
        }
        return redirect()->route('mfsorganisations.index');
    }

    /** Change a conversation's organisation by hand (from the conversation's panel block). */
    public function changeConversation(Request $request, $id)
    {
        $this->guard();
        $conversation = \App\Conversation::findOrFail($id);
        if (!Auth::user()->can('view', $conversation)) {
            abort(403);
        }
        $org_id = (int) $request->input('organisation_id');
        if (!$org_id) {
            OrgConversation::where('conversation_id', $conversation->id)->delete();
        } else {
            $org = Organisation::findOrFail($org_id);
            if (!$org->isVisibleTo(Auth::user())) {
                abort(403);
            }
            $rec = OrgConversation::find($conversation->id) ?: new OrgConversation(['conversation_id' => $conversation->id]);
            $rec->organisation_id = $org->id;
            $rec->department_id = null;
            $rec->source = OrgConversation::SOURCE_AGENT;
            $rec->set_by = Auth::id();
            $rec->save();
        }
        \Session::flash('flash_success_floating', __('Organisation of this conversation changed.'));
        return redirect()->to($conversation->url());
    }
}
