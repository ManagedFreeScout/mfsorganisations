<?php

namespace Modules\MFSOrganisations\Services;

use Modules\MFSOrganisations\Entities\AddressLink;
use Modules\MFSOrganisations\Entities\Domain;
use Modules\MFSOrganisations\Entities\Location;
use Modules\MFSOrganisations\Entities\OrgConversation;
use Modules\MFSOrganisations\Entities\Organisation;

/**
 * Organisation events for the official API & Webhooks module (card #423). The events show up in
 * its webhook screen; it sends them in the background, signed, with its retry and log. Conversation
 * webhooks it already sends get an extra "organisation" field.
 */
class Webhooks
{
    const EVENTS = [
        'organisation.created',
        'organisation.updated',
        'organisation.deleted',
        'organisation.address_linked',
        'organisation.address_removed',
        // Card #430: own events, so other systems (and MFS Assets) can follow the location tree.
        'organisation.location_created',
        'organisation.location_updated',
        'organisation.location_deleted',
    ];

    /** True while the backfill runs: thousands of links would mean thousands of calls. */
    public static $muted = false;

    /** Organisations already reported as updated in this request (one call per request). */
    protected static $updated = [];

    public static function available()
    {
        return \Module::isActive('apiwebhooks')
            && class_exists('\Modules\ApiWebhooks\Providers\ApiWebhooksServiceProvider');
    }

    public static function fire($event, array $data)
    {
        if (self::$muted || !self::available() || !LicenseService::isLicensed()) {
            return;
        }
        \Modules\ApiWebhooks\Providers\ApiWebhooksServiceProvider::maybeRunWebhook($event, $data);
    }

    public static function register()
    {
        if (!self::available()) {
            return;
        }

        \Eventy::addFilter('webhooks.events', function ($events) {
            return array_values(array_unique(array_merge($events, self::EVENTS)));
        });

        // Conversation webhooks: add the conversation's organisation (null when it has none).
        \Eventy::addFilter('apiwebhooks.webhook.before_run', function ($params, $event, $data) {
            if (strpos((string) $event, 'convo.') === 0 && $data instanceof \App\Conversation && is_array($params)) {
                $rec = OrgConversation::where('conversation_id', $data->id)->first();
                $org = $rec ? Organisation::find($rec->organisation_id) : null;
                $params['organisation'] = $org ? ApiFormat::organisationRef($org) : null;
            }
            return $params;
        }, 20, 3);

        Organisation::created(function ($org) {
            // One "created" with the domains added right after; no "updated" in the same request.
            self::$updated[$org->id] = true;
            self::afterRequest('organisation.created', $org->id);
        });
        Organisation::updated(function ($org) {
            self::updated($org);
        });
        Organisation::deleted(function ($org) {
            self::fire('organisation.deleted', ['id' => $org->id, 'name' => $org->name]);
        });
        // Domains are part of the organisation.
        Domain::saved(function ($domain) {
            self::updated(Organisation::find($domain->organisation_id));
        });
        Domain::deleted(function ($domain) {
            self::updated(Organisation::find($domain->organisation_id));
        });

        Location::created(function ($loc) {
            self::locationEvent('organisation.location_created', $loc);
        });
        Location::updated(function ($loc) {
            self::locationEvent('organisation.location_updated', $loc);
        });
        Location::deleted(function ($loc) {
            self::locationEvent('organisation.location_deleted', $loc);
        });

        AddressLink::saved(function ($link) {
            $before = $link->getOriginal('organisation_id');
            $now = $link->organisation_id;
            if ($before && $before != $now) {
                self::addressEvent('organisation.address_removed', $link, $before);
            }
            if ($now && $now != $before) {
                self::addressEvent('organisation.address_linked', $link, $now);
            }
        });
        AddressLink::deleted(function ($link) {
            if ($link->organisation_id) {
                self::addressEvent('organisation.address_removed', $link, $link->organisation_id);
            }
        });
    }

    protected static function updated($org)
    {
        if (!$org || isset(self::$updated[$org->id])) {
            return;
        }
        self::$updated[$org->id] = true;
        self::afterRequest('organisation.updated', $org->id);
    }

    protected static function afterRequest($event, $org_id)
    {
        $send = function () use ($event, $org_id) {
            $fresh = Organisation::find($org_id);
            if ($fresh) {
                self::fire($event, ApiFormat::organisation($fresh, true));
            }
        };
        // In a web request: after its other changes (domains, mailboxes), so the payload is
        // complete. Elsewhere (scheduler, scripts) there is no end of request: send now.
        if (app()->runningInConsole()) {
            $send();
        } else {
            app()->terminating($send);
        }
    }

    protected static function locationEvent($event, Location $loc)
    {
        $org = Organisation::find($loc->organisation_id);
        if ($org) {
            $data = $event == 'organisation.location_deleted'
                ? ['id' => $loc->id, 'name' => $loc->name, 'type' => $loc->type, 'parentId' => $loc->parent_id]
                : ApiFormat::location($loc);
            self::fire($event, $data + ['organisation' => ApiFormat::organisationRef($org)]);
        }
    }

    protected static function addressEvent($event, AddressLink $link, $org_id)
    {
        $org = Organisation::find($org_id);
        if ($org) {
            self::fire($event, ApiFormat::address($link) + ['organisation' => ApiFormat::organisationRef($org)]);
        }
    }
}
