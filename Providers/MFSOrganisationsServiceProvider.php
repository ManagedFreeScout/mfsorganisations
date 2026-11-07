<?php

namespace Modules\MFSOrganisations\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Modules\MFSOrganisations\Entities\AddressLink;
use Modules\MFSOrganisations\Entities\OrgConversation;
use Modules\MFSOrganisations\Entities\Organisation;
use Modules\MFSOrganisations\Services\Backfill;
use Modules\MFSOrganisations\Services\LicenseService;
use Modules\MFSOrganisations\Services\Linker;

defined('MFSORGANISATIONS_MODULE') || define('MFSORGANISATIONS_MODULE', 'mfsorganisations');

/**
 * MFS Organisations (card freescout-modules #423): group customers into organisations by
 * e-mail address. Scope: Downloads/MFS-Organisations-v1-scope.md.
 */
class MFSOrganisationsServiceProvider extends ServiceProvider
{
    const MODULE_ALIAS = 'mfsorganisations';

    // String id, as core asks of modules (User::$user_permissions).
    const PERM_MANAGE = 'mfsorganisations.manage';

    // Per-mailbox display switches: mailboxes listed here hide the badge / the panel block.
    const OPTION_HIDE_BADGE = 'mfsorganisations.hide_badge';
    const OPTION_HIDE_PANEL = 'mfsorganisations.hide_panel';

    protected $defer = false;

    /** @var array conversation_id => Organisation, filled once per list page. */
    public static $list_orgs = [];

    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->registerLicenseHooks();
        $this->registerMenuAndPermissions();
        $this->registerLinkingHooks();
        $this->registerListHooks();
        $this->registerConversationHooks();
        $this->registerCustomerHooks();
        $this->registerSearchHooks();
        $this->registerWorkflowsHooks();
        $this->registerKanbanHooks();
        $this->registerApiHooks();
        $this->registerAssets();
    }

    public function register()
    {
        $this->app->singleton(LicenseService::class, function () {
            return new LicenseService();
        });
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/config.php', self::MODULE_ALIAS);
    }

    protected function registerViews()
    {
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', self::MODULE_ALIAS);
    }

    public static function canManage($user = null)
    {
        $user = $user ?: Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission(self::PERM_MANAGE));
    }

    /**
     * Whether the current agent may see this organisation (its mailboxes). An organisation they
     * can't see shows nowhere: no badge, no block (Rutger, 6 Nov 2026). Cached per request.
     */
    public static function canSee($org)
    {
        static $visible = null;
        $user = Auth::user();
        if (!$org || !$user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        if ($visible === null) {
            $visible = array_flip(Organisation::visibleTo($user)->pluck('id')->all());
        }
        return isset($visible[$org->id]);
    }

    public static function hiddenFor($option, $mailbox_id)
    {
        $ids = \Option::get($option, []);
        return is_array($ids) && in_array((int) $mailbox_id, array_map('intval', $ids));
    }

    /**
     * Licence: same set-up as MFS Essentials (card #194). The settings page is our own view in
     * core's settings layout; the module's own options are saved by SettingsController.
     */
    protected function registerLicenseHooks()
    {
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections[self::MODULE_ALIAS] = [
                'title'       => __('MFS Organisations'),
                'icon'        => 'briefcase',
                'order'       => 320,
                'description' => __('Licence, free-mail domains, display per mailbox and the backfill.'),
            ];
            return $sections;
        }, 15);

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section === self::MODULE_ALIAS) {
                $settings['license_status'] = app(LicenseService::class)->getLicenseStatus();
            }
            return $settings;
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section === self::MODULE_ALIAS ? self::MODULE_ALIAS . '::settings.mfsorganisations' : $view;
        }, 20, 2);

        \Eventy::addFilter('modules.show_license', function ($show, $module) {
            return (isset($module['alias']) && $module['alias'] === self::MODULE_ALIAS) ? true : $show;
        }, 20, 2);

        \Eventy::addFilter('modules.license_info', function ($license_info, $module_alias) {
            if ($module_alias !== self::MODULE_ALIAS) {
                return $license_info;
            }
            $status = app(LicenseService::class)->getLicenseStatus();
            return [
                'license'      => $status['license_key'] ?? '',
                'activated'    => $status['valid'] ?? false,
                'status'       => $status['status'] ?? 'inactive',
                'expires_at'   => $status['expires_at'] ?? null,
                'license_type' => $status['license_type'] ?? null,
            ];
        }, 20, 2);

        \Eventy::addFilter('module.requires_license', function ($requires, $module) {
            return (isset($module['alias']) && $module['alias'] === self::MODULE_ALIAS) ? true : $requires;
        }, 20, 2);

        \Eventy::addFilter('schedule', function ($schedule) {
            // Licence re-check every 6 hours (as MFS Essentials).
            $schedule->call(function () {
                $service = app(LicenseService::class);
                $status  = $service->getLicenseStatus();
                if (!empty($status['license_key'])) {
                    $service->validateLicense($status['license_key']);
                }
            })->cron('0 */6 * * *');
            // Backfill: a few batches a minute while one is running, also with the page closed.
            $schedule->call(function () {
                for ($i = 0; $i < 5 && Backfill::isRunning(); $i++) {
                    Backfill::step();
                }
            })->everyMinute();
            return $schedule;
        }, 20, 1);
    }

    /** Manage → Organisations (Rutger, 3 Nov 2026) and the "Manage organisations" permission. */
    protected function registerMenuAndPermissions()
    {
        // Highlights Manage while on our pages.
        \App\Misc\Helper::$menu['manage']['mfsorganisations'] = [
            'mfsorganisations.index',
            'mfsorganisations.show',
            'mfsorganisations.create',
        ];

        // Every agent can look organisations up (read-only without the permission), so Manage
        // is shown to agents too.
        \Eventy::addFilter('menu.manage.can_view', function ($can) {
            return $can || (LicenseService::isLicensed() && Auth::check());
        });

        \Eventy::addAction('menu.manage.after_mailboxes', function () {
            if (!LicenseService::isLicensed() || !Auth::check()) {
                return;
            }
            echo '<li class="'.\App\Misc\Helper::menuSelectedHtml('mfsorganisations').'"><a href="'
                .route('mfsorganisations.index').'">'.e(__('Organisations')).'</a></li>';
        });

        \Eventy::addFilter('user_permissions.list', function ($list) {
            $list[] = self::PERM_MANAGE;
            return $list;
        });

        \Eventy::addFilter('user_permissions.name', function ($name, $permission) {
            return $permission === self::PERM_MANAGE ? __('Users are allowed to manage organisations') : $name;
        }, 20, 2);
    }

    /**
     * Linking: a new address is linked by domain the moment FreeScout stores it; a new
     * conversation gets its organisation recorded right away and keeps it.
     */
    protected function registerLinkingHooks()
    {
        \App\Email::created(function ($email) {
            if (LicenseService::isLicensed()) {
                try {
                    Linker::linkAddress($email->email);
                } catch (\Exception $e) {
                    \Log::error('MFSOrganisations: linking '.$email->email.' failed: '.$e->getMessage());
                }
            }
        });

        $record = function ($conversation) {
            if (LicenseService::isLicensed()) {
                try {
                    Linker::recordConversation($conversation);
                } catch (\Exception $e) {
                    \Log::error('MFSOrganisations: recording conversation '.$conversation->id.' failed: '.$e->getMessage());
                }
            }
        };
        \Eventy::addAction('conversation.created_by_customer', $record, 20, 1);
        // Fires right away; core's conversation.created_by_user only runs after the undo delay.
        \Eventy::addAction('conversation.created_by_user_can_undo', $record, 20, 1);

        \App\Conversation::deleted(function ($conversation) {
            OrgConversation::where('conversation_id', $conversation->id)->delete();
        });

        // GDPR: deleting a customer removes the links of their addresses. Core's deleteCustomer()
        // deletes the customer's emails rows with a plain query before this fires, so the
        // addresses come from the customer's conversations, and domain links whose address is no
        // longer in FreeScout go too (a domain link only ever exists for a FreeScout address).
        \Eventy::addAction('customer.deleting', function ($customer) {
            $emails = array_merge(
                $customer->emails()->pluck('email')->all(),
                \App\Conversation::where('customer_id', $customer->id)->whereNotNull('customer_email')->distinct()->pluck('customer_email')->all()
            );
            $emails = array_unique(array_map('strtolower', $emails));
            if ($emails) {
                AddressLink::whereIn('email', $emails)->delete();
            }
            $p = \DB::getTablePrefix();
            AddressLink::where('source', AddressLink::SOURCE_DOMAIN)->whereNotExists(function ($q) use ($p) {
                $q->select(\DB::raw(1))->from('emails')->whereRaw("{$p}emails.email = {$p}mfsorg_address_links.email");
            })->delete();
        });

        // The mailboxes' own domains are never linked: forget the cached list when they change.
        \App\Mailbox::saved(function () {
            Linker::flush();
        });
    }

    /** The organisation badge before the subject in conversation lists. */
    protected function registerListHooks()
    {
        \Eventy::addFilter('conversations_table.preload_table_data', function ($conversations) {
            if (!LicenseService::isLicensed() || !count($conversations)) {
                return $conversations;
            }
            $ids = [];
            foreach ($conversations as $c) {
                $ids[] = $c->id;
            }
            $recs = OrgConversation::whereIn('conversation_id', $ids)->get(['conversation_id', 'organisation_id']);
            $orgs = Organisation::whereIn('id', $recs->pluck('organisation_id')->unique()->all())->get()->keyBy('id');
            foreach ($recs as $r) {
                if (isset($orgs[$r->organisation_id])) {
                    self::$list_orgs[$r->conversation_id] = $orgs[$r->organisation_id];
                }
            }
            return $conversations;
        });

        \Eventy::addAction('conversations_table.before_subject', function ($conversation) {
            $org = self::$list_orgs[$conversation->id] ?? null;
            if (!$org || !self::canSee($org) || self::hiddenFor(self::OPTION_HIDE_BADGE, $conversation->mailbox_id)) {
                return;
            }
            // The badge sits inside the row's link, so it can't be an <a>; the JS opens data-mfsorg-href.
            echo '<span class="mfsorg-badge mfsorg-badge-link" data-mfsorg-href="'.e($org->searchUrl()).'" style="background:'.e($org->color).';color:'.e($org->textColor()).'" title="'
                .e(__('All conversations of :name', ['name' => $org->name])).'">'.e($org->name).'</span>';
        });
    }

    /** The organisation block in the conversation's right-hand panel, with a "Change" option. */
    protected function registerConversationHooks()
    {
        // Right under the customer, above the previous conversations; without a customer that
        // hook doesn't fire, so then below the customer panel.
        \Eventy::addAction('conversation.before_prev_convs', function ($customer, $conversation, $mailbox) {
            self::renderConversationBlock($conversation);
        }, 20, 3);
        \Eventy::addAction('conversation.after_customer_sidebar', function ($conversation) {
            self::renderConversationBlock($conversation);
        });
    }

    public static function renderConversationBlock($conversation)
    {
        static $done = [];
        if (isset($done[$conversation->id])) {
            return;
        }
        $done[$conversation->id] = true;
        if (!LicenseService::isLicensed() || !Auth::check()
            || self::hiddenFor(self::OPTION_HIDE_PANEL, $conversation->mailbox_id)
        ) {
            return;
        }
        $rec = OrgConversation::where('conversation_id', $conversation->id)->first();
        $org = $rec ? Organisation::find($rec->organisation_id) : null;
        if ($org && !self::canSee($org)) {
            // Its organisation is not one this agent may see: no block at all.
            return;
        }
        $user = Auth::user();
        $open = 0;
        if ($org) {
            $open = \App\Conversation::whereIn('id', function ($q) use ($org) {
                $q->select('conversation_id')->from('mfsorg_conversations')->where('organisation_id', $org->id);
            })
                ->whereIn('mailbox_id', $user->mailboxesIdsCanView())
                ->where('state', \App\Conversation::STATE_PUBLISHED)
                ->whereIn('status', [\App\Conversation::STATUS_ACTIVE, \App\Conversation::STATUS_PENDING])
                ->where('id', '!=', $conversation->id)
                ->count();
        }
        $link = $conversation->customer_email ? AddressLink::where('email', strtolower($conversation->customer_email))->first() : null;
        $department = ($rec && $rec->department_id) ? \Modules\MFSOrganisations\Entities\Department::find($rec->department_id) : null;
        // No organisation yet and a business domain nobody has: offer to create one for it.
        $create_domain = null;
        if (!$org && $conversation->customer_email && self::canManage()) {
            $domain = Linker::domainOf($conversation->customer_email);
            if ($domain && !Linker::blockedReason($domain) && !\Modules\MFSOrganisations\Entities\Domain::where('domain', $domain)->exists()) {
                $create_domain = $domain;
            }
        }
        echo view(self::MODULE_ALIAS.'::partials.conversation_block', [
            'conversation' => $conversation,
            'org'          => $org,
            'rec'          => $rec,
            'link'         => $link,
            'department'   => $department,
            'open'         => $open,
            'create_domain' => $create_domain,
            'can_change'   => $user->can('view', $conversation),
            'orgs'         => Organisation::visibleTo($user)->where('active', true)->orderBy('name')->get(['id', 'name']),
        ])->render();
    }

    /** Per address on the customer edit form: organisation, department, or excluded. */
    protected function registerCustomerHooks()
    {
        \Eventy::addAction('customer.edit.after_fields', function ($customer, $errors) {
            if (!LicenseService::isLicensed() || !self::canManage()) {
                return;
            }
            $emails = $customer->emails()->pluck('email')->all();
            if (!$emails) {
                return;
            }
            $links = AddressLink::whereIn('email', array_map('strtolower', $emails))->get()->keyBy('email');
            echo view(self::MODULE_ALIAS.'::partials.customer_fields', [
                'emails' => $emails,
                'links'  => $links,
                'orgs'   => Organisation::visibleTo(Auth::user())->orderBy('name')->get(['id', 'name', 'active']),
            ])->render();
        }, 20, 2);

        \Eventy::addAction('customer.updated', function ($customer) {
            $posted = request()->input('mfsorg');
            if (!LicenseService::isLicensed() || !self::canManage() || !is_array($posted)) {
                return;
            }
            $own = array_map('strtolower', $customer->emails()->pluck('email')->all());
            foreach ($posted as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if (!$email || !in_array($email, $own)) {
                    continue;
                }
                \Modules\MFSOrganisations\Http\Controllers\OrganisationsController::setAddressLink(
                    $email,
                    (string) ($row['organisation_id'] ?? ''),
                    !empty($row['move'])
                );
            }
        });

        // Customer page (not inside a conversation, where the panel block shows it already).
        \Eventy::addAction('customer.profile_data', function ($customer, $conversation) {
            if ($conversation || !LicenseService::isLicensed() || !Auth::check()) {
                return;
            }
            $emails = array_map('strtolower', $customer->emails()->pluck('email')->all());
            $links = AddressLink::whereIn('email', $emails ?: [''])->whereNotNull('organisation_id')->get();
            $orgs = Organisation::visibleTo(Auth::user())->whereIn('id', $links->pluck('organisation_id')->unique()->all() ?: [0])->get();
            foreach ($orgs as $org) {
                echo '<div class="customer-section mfsorg-customer-org"><a href="'.e($org->searchUrl()).'" class="mfsorg-badge" title="'
                    .e(__('All conversations of :name', ['name' => $org->name])).'" style="background:'
                    .e($org->color).';color:'.e($org->textColor()).'">'.e($org->name).'</a></div>';
            }
        }, 20, 2);
    }

    /** An "organisation" filter in FreeScout's search. */
    protected function registerSearchHooks()
    {
        \Eventy::addFilter('search.filters_list', function ($list) {
            if (LicenseService::isLicensed()) {
                $list[] = 'organisation';
            }
            return $list;
        });

        \Eventy::addAction('search.display_filters', function ($filters, $filters_data, $mode) {
            if (!LicenseService::isLicensed() || $mode != \App\Conversation::SEARCH_MODE_CONV || !Auth::check()) {
                return;
            }
            echo view(self::MODULE_ALIAS.'::partials.search_filter', [
                'filters' => $filters,
                'orgs'    => Organisation::visibleTo(Auth::user())->orderBy('name')->get(['id', 'name']),
            ])->render();
        }, 20, 3);

        \Eventy::addFilter('search.conversations.apply_filters', function ($query, $filters, $q) {
            if (!empty($filters['organisation']) && LicenseService::isLicensed()) {
                $org_id = (int) $filters['organisation'];
                $query->whereIn('conversations.id', function ($sub) use ($org_id) {
                    $sub->select('conversation_id')->from('mfsorg_conversations')->where('organisation_id', $org_id);
                });
            }
            return $query;
        }, 20, 3);

        // The Customers tab of the same search: customers with an address linked to the organisation.
        \Eventy::addFilter('search.customers.apply_filters', function ($query, $filters, $q) {
            if (!empty($filters['organisation']) && LicenseService::isLicensed()) {
                $org_id = (int) $filters['organisation'];
                $query->whereIn('emails.email', function ($sub) use ($org_id) {
                    $sub->select('email')->from('mfsorg_address_links')->where('organisation_id', $org_id);
                });
            }
            return $query;
        }, 20, 3);
    }

    /**
     * Workflows (official module) conditions "Organisation" and "Organisation type". Routing
     * itself (assignee, account manager, holiday cover) stays in Workflows (Rutger, 2 Nov 2026).
     */
    protected function registerWorkflowsHooks()
    {
        \Eventy::addFilter('workflows.conditions_config', function ($config, $mailbox_id) {
            if (!LicenseService::isLicensed()) {
                return $config;
            }
            $triggers = [
                'conversation.created_by_user',
                'conversation.user_forwarded',
                'conversation.created_by_customer',
                'conversation.moved',
            ];
            $operators = ['equal' => __('Is equal to'), 'not_equal' => __('Is not equal to')];
            $orgs = [];
            foreach (Organisation::orderBy('name')->get(['id', 'name']) as $org) {
                $orgs[$org->id] = $org->name;
            }
            $types = [];
            foreach (Organisation::TYPES as $key => $label) {
                $types[$key] = __($label);
            }
            $config['mfsorganisations'] = [
                'title' => __('Organisation'),
                'items' => [
                    'mfsorg_organisation' => [
                        'title'     => __('Organisation'),
                        'operators' => $operators + ['set' => __('Is set'), 'not_set' => __('Is not set')],
                        'values'    => $orgs,
                        'triggers'  => $triggers,
                    ],
                    'mfsorg_type' => [
                        'title'     => __('Organisation type'),
                        'operators' => $operators,
                        'values'    => $types,
                        'triggers'  => $triggers,
                    ],
                ],
            ];
            return $config;
        }, 20, 2);

        // Workflows may check before our own hook has recorded the conversation, so this resolves
        // the organisation live when there is no record yet.
        \Eventy::addFilter('workflow.check_condition', function ($result, $type, $operator, $value, $conversation, $workflow) {
            if ($type != 'mfsorg_organisation' && $type != 'mfsorg_type') {
                return $result;
            }
            if (!LicenseService::isLicensed()) {
                return false;
            }
            $org_id = Linker::conversationOrganisationId($conversation);
            if ($type == 'mfsorg_organisation') {
                switch ($operator) {
                    case 'set':
                        return (bool) $org_id;
                    case 'not_set':
                        return !$org_id;
                    case 'not_equal':
                        return $org_id != (int) $value;
                    default:
                        return $org_id && $org_id == (int) $value;
                }
            }
            $org = $org_id ? Organisation::find($org_id) : null;
            $org_type = $org ? $org->type : null;
            return $operator == 'not_equal' ? $org_type !== (string) $value : $org_type === (string) $value;
        }, 20, 6);
    }

    /**
     * Official API & Webhooks module: organisation webhooks, and an organisationId filter on its
     * conversations list. The /api/organisations endpoints are in Http/routes.php.
     */
    protected function registerApiHooks()
    {
        \Modules\MFSOrganisations\Services\Webhooks::register();

        \Eventy::addFilter('api.conversations.query', function ($query, $request) {
            if ($request->filled('organisationId') && LicenseService::isLicensed()) {
                $org_id = (int) $request->input('organisationId');
                $query->whereIn('conversations.id', function ($sub) use ($org_id) {
                    $sub->select('conversation_id')->from('mfsorg_conversations')->where('organisation_id', $org_id);
                });
            }
            return $query;
        }, 20, 2);
    }

    /** Session key of the organisation an agent filters Kanban boards on (null = all). */
    const SESSION_KANBAN_ORG = 'mfsorganisations.kanban_org';

    /**
     * Kanban (official module): the organisation badge on cards, and an organisation filter.
     * Kanban has no hook for its own filters, so the filter is a global scope on conversations,
     * only while Kanban loads a board or its cards; column counts and "more" stay right.
     */
    protected function registerKanbanHooks()
    {
        if (!\Module::isActive('kanban')) {
            return;
        }

        // Kanban only calls the list hooks after the subject; there the badge is plain text, so a
        // click opens the card as usual.
        // Where the JS finds the filter's endpoint (no route list in the page).
        \Eventy::addAction('layout.head', function () {
            if (request()->route() && request()->route()->getName() == 'kanban.show' && LicenseService::isLicensed()) {
                echo '<meta name="mfsorg-kanban-filter-url" content="'.e(route('mfsorganisations.kanban_filter')).'">';
            }
        });

        \Eventy::addAction('conversations_table.after_subject', function ($conversation) {
            if (!self::isKanbanRequest() || !LicenseService::isLicensed()
                || self::hiddenFor(self::OPTION_HIDE_BADGE, $conversation->mailbox_id)
            ) {
                return;
            }
            if (!array_key_exists($conversation->id, self::$list_orgs)) {
                // Kanban preloads only when Tags or Custom Fields is active.
                $rec = OrgConversation::where('conversation_id', $conversation->id)->first();
                self::$list_orgs[$conversation->id] = $rec ? Organisation::find($rec->organisation_id) : null;
            }
            $org = self::$list_orgs[$conversation->id];
            if ($org && self::canSee($org)) {
                echo ' <span class="mfsorg-badge mfsorg-kn-badge" style="background:'.e($org->color).';color:'.e($org->textColor()).'">'.e($org->name).'</span>';
            }
        });

        \App\Conversation::addGlobalScope('mfsorg_kanban', function ($query) {
            $org_id = self::kanbanFilterOrgId();
            if ($org_id) {
                $query->whereIn('conversations.id', function ($sub) use ($org_id) {
                    $sub->select('conversation_id')->from('mfsorg_conversations')->where('organisation_id', $org_id);
                });
            }
        });
    }

    /** A Kanban page or one of its card loads. */
    public static function isKanbanRequest()
    {
        if (app()->runningInConsole()) {
            return false;
        }
        $route = request()->route();
        $name = $route ? $route->getName() : '';
        return $name == 'kanban.show'
            || ($name == 'kanban.ajax' && in_array(request()->input('action'), ['show', 'more']));
    }

    /** The organisation the current agent filters this Kanban request on, or null. */
    public static function kanbanFilterOrgId()
    {
        if (!self::isKanbanRequest() || !Auth::check() || !LicenseService::isLicensed()) {
            return null;
        }
        static $checked = [];
        $org_id = (int) session(self::SESSION_KANBAN_ORG);
        if (!$org_id) {
            return null;
        }
        // Deleted or no longer visible to this agent: ignore it, or the board would be empty.
        if (!isset($checked[$org_id])) {
            $checked[$org_id] = Organisation::visibleTo(Auth::user())->where('id', $org_id)->exists();
        }
        return $checked[$org_id] ? $org_id : null;
    }

    protected function registerAssets()
    {
        \Eventy::addFilter('stylesheets', function ($items) {
            if (LicenseService::isLicensed()) {
                $items[] = \Module::getPublicPath(self::MODULE_ALIAS).'/css/mfsorganisations.css';
            }
            return $items;
        });

        \Eventy::addFilter('javascripts', function ($items) {
            if (LicenseService::isLicensed()) {
                $items[] = \Module::getPublicPath(self::MODULE_ALIAS).'/js/mfsorganisations.js';
            }
            return $items;
        });
    }
}
