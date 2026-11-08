<?php

namespace Modules\MFSOrganisations\Services;

use Modules\MFSOrganisations\Entities\AddressLink;
use Modules\MFSOrganisations\Entities\Department;
use Modules\MFSOrganisations\Entities\Location;
use Modules\MFSOrganisations\Entities\Organisation;

/** How the API and the webhooks show organisations: one format for both (card #423). */
class ApiFormat
{
    const SOURCES = [
        AddressLink::SOURCE_DOMAIN   => 'domain',
        AddressLink::SOURCE_MANUAL   => 'manual',
        AddressLink::SOURCE_EXCLUDED => 'excluded',
    ];

    public static function date($date)
    {
        return $date ? $date->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z') : null;
    }

    /** $full adds contact details, notes and mailboxes; $departments / $locations add those trees. */
    public static function organisation(Organisation $org, $full = false, $departments = false, $locations = false)
    {
        $data = [
            'id'        => $org->id,
            'name'      => $org->name,
            'type'      => $org->type,
            'active'    => (bool) $org->active,
            'autoLink'  => (bool) $org->auto_link,
            'color'     => $org->color,
            'alert'     => $org->alert,
            'domains'   => $org->domains->pluck('domain')->values()->all(),
            'createdAt' => self::date($org->created_at),
            'updatedAt' => self::date($org->updated_at),
        ];
        if ($full) {
            $data += [
                'cocNumber'  => $org->coc_number,
                'vatNumber'  => $org->vat_number,
                'country'    => $org->country,
                'address'    => $org->address,
                'phone'      => $org->phone,
                'website'    => $org->website,
                'notes'      => $org->notes,
                // Empty = every mailbox.
                'mailboxIds' => array_map('intval', $org->mailboxIds()),
            ];
        }
        if ($departments) {
            $data['_embedded']['departments'] = self::departments($org->id);
        }
        if ($locations) {
            $data['_embedded']['locations'] = self::locations($org->id);
        }
        return $data;
    }

    /** Short form inside other payloads (conversation webhooks). */
    public static function organisationRef(Organisation $org)
    {
        return ['id' => $org->id, 'name' => $org->name, 'type' => $org->type];
    }

    public static function departments($org_id)
    {
        $out = [];
        $all = Department::where('organisation_id', $org_id)->get()->keyBy('id');
        foreach (Department::treeOptions($org_id) as $id => $path) {
            $out[] = ['id' => $id, 'name' => $all[$id]->name, 'parentId' => $all[$id]->parent_id, 'path' => $path];
        }
        return $out;
    }

    /** Locations of an organisation in tree order, each with its path ("HQ › 2nd floor › Room 2.14"). Card #430. */
    public static function locations($org_id)
    {
        $out = [];
        $all = Location::where('organisation_id', $org_id)->get()->keyBy('id');
        foreach (Location::treeOptions($org_id) as $id => $path) {
            $out[] = self::location($all[$id], $path);
        }
        return $out;
    }

    public static function location(Location $l, $path = null)
    {
        return [
            'id'             => $l->id,
            'organisationId' => $l->organisation_id,
            'name'           => $l->name,
            'type'           => $l->type,
            'parentId'       => $l->parent_id,
            'path'           => $path ?? $l->path(),
            'address'        => $l->address,
            'notes'          => $l->notes,
            'createdAt'      => self::date($l->created_at),
            'updatedAt'      => self::date($l->updated_at),
        ];
    }

    public static function address(AddressLink $link)
    {
        return [
            'email'          => $link->email,
            'organisationId' => $link->organisation_id,
            'departmentId'   => $link->department_id,
            'locationId'     => $link->location_id,
            'source'         => self::SOURCES[$link->source] ?? null,
            'active'         => (bool) $link->active,
            'activeFrom'     => $link->active_from ? $link->active_from->toDateString() : null,
            'activeTo'       => $link->active_to ? $link->active_to->toDateString() : null,
            'inForce'        => $link->isInForce(),
            'createdAt'      => self::date($link->created_at),
        ];
    }

    public static function conversation($c)
    {
        $statuses = [1 => 'active', 2 => 'pending', 3 => 'closed', 4 => 'spam'];
        return [
            'id'          => $c->id,
            'number'      => $c->number,
            'subject'     => $c->subject,
            'status'      => $statuses[$c->status] ?? (string) $c->status,
            'mailboxId'   => $c->mailbox_id,
            'customerEmail' => $c->customer_email,
            'assigneeId'  => $c->user_id,
            'createdAt'   => self::date($c->created_at),
            'lastReplyAt' => self::date($c->last_reply_at),
            'webUrl'      => $c->url(),
        ];
    }
}
