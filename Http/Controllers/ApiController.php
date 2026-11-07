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
use Modules\MFSOrganisations\Services\ApiFormat;
use Modules\MFSOrganisations\Services\LicenseService;
use Modules\MFSOrganisations\Services\Linker;

/**
 * REST API for organisations (card #423). Runs behind the official API & Webhooks module's key
 * check and follows its conventions: camelCase, _embedded lists with a page block, Resource-ID on
 * create, the same error body. A personal API key acts as its owner (visibility by mailbox,
 * "Manage organisations" to change, admin to delete); the module's main API key acts as admin.
 */
class ApiController extends Controller
{
    const PAGE_SIZE = 50;
    const MAX_PAGE_SIZE = 200;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            return LicenseService::isLicensed() ? $next($request) : self::unlicensed();
        });
    }

    /* ---------- access ---------- */

    protected function user()
    {
        return Auth::user();
    }

    protected function isAdmin()
    {
        $user = $this->user();
        return !$user || $user->isAdmin();
    }

    protected function canManage()
    {
        return !$this->user() || Provider::canManage($this->user());
    }

    protected function visibleQuery()
    {
        $query = Organisation::query();
        return $this->user() ? $query->visibleTo($this->user()) : $query;
    }

    /** The organisation if this key may see it, else null (the caller answers 404). */
    protected function find($id)
    {
        return $this->visibleQuery()->where('id', (int) $id)->first();
    }

    /** Mailboxes whose conversations this key may see; null = all. */
    protected function mailboxIds()
    {
        return $this->user() ? ($this->user()->mailboxesIdsCanView() ?: [0]) : null;
    }

    /* ---------- responses ---------- */

    protected function json($data, $code = 200)
    {
        return response()->json($data, $code);
    }

    protected function error($message, $code = 400, $path = '')
    {
        $errors = $path !== '' ? [['path' => $path, 'message' => $message, 'source' => 'JSON']] : [];
        return $this->json(['message' => $message, '_embedded' => ['errors' => $errors]], $code);
    }

    protected function notFound()
    {
        return $this->error('Organisation not found', 404);
    }

    protected function forbidden($what = 'This action requires the permission "Manage organisations"')
    {
        return $this->error('Forbidden: '.$what, 403);
    }

    protected function created($id, $data)
    {
        return $this->json($data, 201)->header('Resource-ID', $id);
    }

    protected function paged($query, Request $request, $key, callable $format)
    {
        $size = min(self::MAX_PAGE_SIZE, max(1, (int) ($request->input('pageSize') ?: self::PAGE_SIZE)));
        $page = $query->paginate($size, ['*'], 'page', max(1, (int) $request->input('page', 1)));
        return $this->json([
            '_embedded' => [$key => array_map($format, $page->items())],
            'page'      => [
                'size'          => $page->perPage(),
                'totalElements' => $page->total(),
                'totalPages'    => $page->lastPage(),
                'number'        => $page->currentPage(),
            ],
        ]);
    }

    /** Request body as an array (JSON or form). */
    protected function body(Request $request)
    {
        $data = $request->json()->all();
        return $data ?: $request->all();
    }

    /* ---------- organisations ---------- */

    /** GET /api/organisations?search=&domain=&type=&active=&page=&pageSize= */
    public function index(Request $request)
    {
        $query = $this->visibleQuery()->with('domains')->orderBy('name');
        if ($request->filled('search')) {
            $like = '%'.mb_strtolower(trim($request->input('search'))).'%';
            $query->where('name', 'like', $like);
        }
        if ($request->filled('domain')) {
            $domain = Domain::normalize($request->input('domain'));
            $query->whereIn('id', Domain::where('domain', $domain ?: '-')->pluck('organisation_id')->all() ?: [0]);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('active')) {
            $query->where('active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }
        return $this->paged($query, $request, 'organisations', function ($org) {
            return ApiFormat::organisation($org);
        });
    }

    /** GET /api/organisations/{id}?embed=departments */
    public function show(Request $request, $id)
    {
        $org = $this->find($id);
        if (!$org) {
            return $this->notFound();
        }
        $embed = array_map('trim', explode(',', (string) $request->input('embed')));
        return $this->json(ApiFormat::organisation($org, true, in_array('departments', $embed)));
    }

    /** POST /api/organisations */
    public function store(Request $request)
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }
        $data = $this->body($request);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->error('name is required', 400, 'name');
        }
        if (Organisation::where('name', $name)->exists()) {
            return $this->error('An organisation with this name already exists', 409, 'name');
        }
        $org = new Organisation(['active' => true, 'auto_link' => true, 'type' => 'company', 'color' => Organisation::COLORS[array_rand(Organisation::COLORS)]]);
        if ($error = $this->fill($org, $data)) {
            return $error;
        }
        $org->save();
        if (isset($data['mailboxIds']) && is_array($data['mailboxIds'])) {
            OrganisationsController::setMailboxes($org, $data['mailboxIds']);
        }
        $report = OrganisationsController::addDomains($org, implode("\n", (array) ($data['domains'] ?? [])));
        return $this->created($org->id, ApiFormat::organisation($org->fresh(), true) + ['_report' => $this->report($report)]);
    }

    /** PUT/PATCH /api/organisations/{id}: only the fields sent change; "domains" replaces the list. */
    public function update(Request $request, $id)
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }
        $org = $this->find($id);
        if (!$org) {
            return $this->notFound();
        }
        $data = $this->body($request);
        if (isset($data['name']) && Organisation::where('name', trim((string) $data['name']))->where('id', '!=', $org->id)->exists()) {
            return $this->error('An organisation with this name already exists', 409, 'name');
        }
        $was_auto = $org->active && $org->auto_link;
        if ($error = $this->fill($org, $data)) {
            return $error;
        }
        $org->save();
        if (isset($data['mailboxIds']) && is_array($data['mailboxIds'])) {
            OrganisationsController::setMailboxes($org, $data['mailboxIds']);
        }
        $report = ['refused' => [], 'linked' => 0, 'recorded' => 0];
        if (array_key_exists('domains', $data)) {
            $wanted = Linker::parseDomainList(implode("\n", (array) $data['domains']));
            Domain::where('organisation_id', $org->id)->whereNotIn('domain', $wanted ?: [''])->get()->each->delete();
            $report = OrganisationsController::addDomains($org, implode("\n", $wanted));
        }
        if (!$was_auto && $org->active && $org->auto_link) {
            foreach ($org->domains()->get() as $d) {
                $c = Linker::applyDomain($org, $d->domain);
                $report['linked'] += $c['addresses'];
                $report['recorded'] += $c['conversations'];
            }
        }
        return $this->json(ApiFormat::organisation($org->fresh(), true) + ['_report' => $this->report($report)]);
    }

    /** DELETE /api/organisations/{id}: only an empty organisation (no addresses, no conversations). */
    public function destroy($id)
    {
        if (!$this->isAdmin()) {
            return $this->forbidden('This action requires an API key owned by an administrator');
        }
        $org = $this->find($id);
        if (!$org) {
            return $this->notFound();
        }
        if (AddressLink::where('organisation_id', $org->id)->exists() || OrgConversation::where('organisation_id', $org->id)->exists()) {
            return $this->error('This organisation still has addresses or conversations. Deactivate it instead (active: false).', 409);
        }
        Domain::where('organisation_id', $org->id)->delete();
        Department::where('organisation_id', $org->id)->delete();
        \DB::table('mfsorg_mailboxes')->where('organisation_id', $org->id)->delete();
        $org->delete();
        return response('', 204);
    }

    /** Fields from the API body; returns an error response or null. */
    protected function fill(Organisation $org, array $data)
    {
        if (array_key_exists('name', $data)) {
            $name = mb_substr(trim((string) $data['name']), 0, 191);
            if ($name === '') {
                return $this->error('name cannot be empty', 400, 'name');
            }
            $org->name = $name;
        }
        if (array_key_exists('type', $data)) {
            if (!isset(Organisation::TYPES[$data['type']])) {
                return $this->error('type must be one of: '.implode(', ', array_keys(Organisation::TYPES)), 400, 'type');
            }
            $org->type = $data['type'];
        }
        if (array_key_exists('color', $data)) {
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $data['color'])) {
                return $this->error('color must look like #3f8abf', 400, 'color');
            }
            $org->color = strtolower($data['color']);
        }
        if (array_key_exists('country', $data)) {
            $country = strtoupper(trim((string) $data['country']));
            if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) {
                return $this->error('country must be a two-letter code (NL)', 400, 'country');
            }
            $org->country = $country ?: null;
        }
        foreach (['active' => 'active', 'autoLink' => 'auto_link'] as $key => $field) {
            if (array_key_exists($key, $data)) {
                $org->$field = filter_var($data[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $texts = ['cocNumber' => ['coc_number', 50], 'vatNumber' => ['vat_number', 50], 'phone' => ['phone', 60],
            'website' => ['website', 191], 'alert' => ['alert', 255], 'address' => ['address', 5000], 'notes' => ['notes', 20000]];
        foreach ($texts as $key => list($field, $max)) {
            if (array_key_exists($key, $data)) {
                $v = mb_substr(trim((string) $data[$key]), 0, $max);
                $org->$field = $v === '' ? null : $v;
            }
        }
        return null;
    }

    protected function report(array $report)
    {
        return [
            'addressesLinked'    => (int) $report['linked'],
            'conversationsAdded' => (int) $report['recorded'],
            'domainsRefused'     => array_values($report['refused']),
        ];
    }

    /* ---------- domains ---------- */

    /** POST /api/organisations/{id}/domains {"domain": "acme.com"} */
    public function addDomain(Request $request, $id)
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }
        $org = $this->find($id);
        if (!$org) {
            return $this->notFound();
        }
        $domain = Domain::normalize($this->body($request)['domain'] ?? '');
        if (!$domain) {
            return $this->error('domain is missing or not valid', 400, 'domain');
        }
        $report = OrganisationsController::addDomains($org, $domain);
        if ($report['refused']) {
            return $this->error($report['refused'][0], 409, 'domain');
        }
        return $this->json(ApiFormat::organisation($org->fresh(), true) + ['_report' => $this->report($report)], 201);
    }

    /** DELETE /api/organisations/{id}/domains/{domain}: new addresses aren't linked by it any more. */
    public function removeDomain($id, $domain)
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }
        $org = $this->find($id);
        if (!$org) {
            return $this->notFound();
        }
        $d = Domain::where('organisation_id', $org->id)->where('domain', Domain::normalize($domain) ?: '-')->first();
        if (!$d) {
            return $this->error('This organisation does not have that domain', 404);
        }
        $d->delete();
        return response('', 204);
    }

    /* ---------- addresses ---------- */

    /** GET /api/organisations/{id}/addresses */
    public function addresses(Request $request, $id)
    {
        $org = $this->find($id);
        if (!$org) {
            return $this->notFound();
        }
        $query = AddressLink::where('organisation_id', $org->id)->orderBy('email');
        return $this->paged($query, $request, 'addresses', function ($link) {
            return ApiFormat::address($link);
        });
    }

    /** POST /api/organisations/{id}/addresses {"email": "...", "movePastConversations": false} */
    public function linkAddress(Request $request, $id)
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }
        $org = $this->find($id);
        if (!$org) {
            return $this->notFound();
        }
        $data = $this->body($request);
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('email is missing or not valid', 400, 'email');
        }
        OrganisationsController::setAddressLink($email, (string) $org->id, filter_var($data['movePastConversations'] ?? false, FILTER_VALIDATE_BOOLEAN));
        return $this->json(ApiFormat::address(AddressLink::where('email', $email)->first()), 201);
    }

    /**
     * DELETE /api/organisations/{id}/addresses/{email}: the address doesn't belong here. Its past
     * conversations are unlinked (except ones an agent set by hand) and it is never linked again
     * automatically, as "Remove" in the screens.
     */
    public function removeAddress($id, $email)
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }
        $org = $this->find($id);
        if (!$org) {
            return $this->notFound();
        }
        $email = strtolower(trim(urldecode($email)));
        if (!AddressLink::where('email', $email)->where('organisation_id', $org->id)->exists()) {
            return $this->error('This address is not linked to this organisation', 404);
        }
        $n = OrganisationsController::setAddressLink($email, 'exclude', false);
        return $this->json(['email' => $email, 'conversationsUnlinked' => (int) $n]);
    }

    /* ---------- conversations, departments, lookup ---------- */

    /** GET /api/organisations/{id}/conversations?status=open|active|pending|closed&mailboxId= */
    public function conversations(Request $request, $id)
    {
        $org = $this->find($id);
        if (!$org) {
            return $this->notFound();
        }
        $query = \App\Conversation::whereIn('id', function ($q) use ($org) {
            $q->select('conversation_id')->from('mfsorg_conversations')->where('organisation_id', $org->id);
        })->where('state', \App\Conversation::STATE_PUBLISHED)->orderBy('last_reply_at', 'desc');
        if (($ids = $this->mailboxIds()) !== null) {
            $query->whereIn('mailbox_id', $ids);
        }
        if ($request->filled('mailboxId')) {
            $query->where('mailbox_id', (int) $request->input('mailboxId'));
        }
        $statuses = ['active' => [1], 'pending' => [2], 'closed' => [3], 'open' => [1, 2]];
        $status = $request->input('status');
        if ($status && isset($statuses[$status])) {
            $query->whereIn('status', $statuses[$status]);
        }
        return $this->paged($query, $request, 'conversations', function ($c) {
            return ApiFormat::conversation($c);
        });
    }

    /** GET /api/organisations/{id}/departments */
    public function departments($id)
    {
        $org = $this->find($id);
        if (!$org) {
            return $this->notFound();
        }
        return $this->json(['_embedded' => ['departments' => ApiFormat::departments($org->id)]]);
    }

    /** POST /api/organisations/{id}/departments {"name": "...", "parentId": null} */
    public function addDepartment(Request $request, $id)
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }
        $org = $this->find($id);
        if (!$org) {
            return $this->notFound();
        }
        $data = $this->body($request);
        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 191);
        if ($name === '') {
            return $this->error('name is required', 400, 'name');
        }
        $parent = (int) ($data['parentId'] ?? 0);
        if ($parent && !Department::where('id', $parent)->where('organisation_id', $org->id)->exists()) {
            return $this->error('parentId is not a department of this organisation', 400, 'parentId');
        }
        $dept = Department::create(['organisation_id' => $org->id, 'parent_id' => $parent ?: null, 'name' => $name]);
        return $this->created($dept->id, ['id' => $dept->id, 'name' => $dept->name, 'parentId' => $dept->parent_id]);
    }

    /**
     * GET /api/organisations/lookup?email=jan@acme.com or ?domain=acme.com: the organisation it
     * belongs to. For an address: its link (by hand or by domain), else what its domain would give.
     */
    public function lookup(Request $request)
    {
        $email = strtolower(trim((string) $request->input('email')));
        $domain = $request->filled('domain') ? Domain::normalize($request->input('domain')) : Linker::domainOf($email);
        if (!$email && !$domain) {
            return $this->error('Pass email or domain', 400);
        }
        $org = null;
        $how = null;
        if ($email) {
            $link = AddressLink::where('email', $email)->first();
            if ($link && $link->source == AddressLink::SOURCE_EXCLUDED) {
                return $this->json(['organisation' => null, 'matchedBy' => 'excluded']);
            }
            if ($link && $link->organisation_id) {
                $org = Organisation::find($link->organisation_id);
                $how = $link->source == AddressLink::SOURCE_MANUAL ? 'address' : 'domain';
            }
        }
        if (!$org && $domain) {
            $d = Domain::where('domain', $domain)->first();
            $org = $d ? Organisation::find($d->organisation_id) : null;
            $how = $org ? 'domain' : null;
        }
        if ($org && !$this->find($org->id)) {
            $org = null;
        }
        if (!$org) {
            return $this->json(['organisation' => null, 'matchedBy' => null, 'blocked' => $domain ? Linker::blockedReason($domain) : null], 404);
        }
        return $this->json(['organisation' => ApiFormat::organisation($org), 'matchedBy' => $how]);
    }

    /** Licence missing: every endpoint answers this. */
    public static function unlicensed()
    {
        return response()->json(['message' => 'MFS Organisations needs a valid licence', '_embedded' => ['errors' => []]], 402);
    }
}
