<?php

namespace Modules\MFSOrganisations\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * A location of an organisation (card #430): site › building › floor › room, as a tree like the
 * departments. Used by MFS Organisations itself (default location per address) and by the other
 * MFS modules (MFS Assets: where an asset is; MFS Portal and MFS SLA later).
 */
class Location extends Model
{
    protected $table = 'mfsorg_locations';

    protected $fillable = ['organisation_id', 'parent_id', 'name', 'type', 'address', 'notes'];

    const TYPES = ['site', 'building', 'floor', 'room', 'other'];

    public static function types()
    {
        return [
            'site'     => __('Site'),
            'building' => __('Building'),
            'floor'    => __('Floor'),
            'room'     => __('Room'),
            'other'    => __('Other'),
        ];
    }

    public function typeName()
    {
        return self::types()[$this->type] ?? $this->type;
    }

    public static function cleanType($type)
    {
        return in_array($type, self::TYPES) ? $type : 'other';
    }

    /** Locations of an organisation as a flat list in tree order: [id => "HQ › 2nd floor › Room 2.14"]. */
    public static function treeOptions($organisation_id)
    {
        $all = self::where('organisation_id', $organisation_id)->orderBy('name')->get();
        $by_parent = [];
        foreach ($all as $l) {
            $by_parent[(int) $l->parent_id][] = $l;
        }
        $out = [];
        $walk = function ($parent_id, $prefix, $depth) use (&$walk, &$out, $by_parent) {
            if ($depth > 10) {
                return;
            }
            foreach ($by_parent[$parent_id] ?? [] as $l) {
                $label = $prefix === '' ? $l->name : $prefix.' › '.$l->name;
                $out[$l->id] = $label;
                $walk($l->id, $label, $depth + 1);
            }
        };
        $walk(0, '', 0);
        return $out;
    }

    /** "HQ › 2nd floor › Room 2.14" for one location. */
    public function path()
    {
        $parts = [$this->name];
        $p = $this->parent_id;
        for ($i = 0; $p && $i < 10; $i++) {
            $parent = self::find($p);
            if (!$parent) {
                break;
            }
            array_unshift($parts, $parent->name);
            $p = $parent->parent_id;
        }
        return implode(' › ', $parts);
    }

    /**
     * A valid parent for $location (null = top level): it must belong to the same organisation and
     * may not be the location itself or one of its own sub-locations (same rule as departments).
     */
    public static function validParent($organisation_id, $parent_id, $location = null)
    {
        $parent_id = (int) $parent_id;
        if (!$parent_id || !self::where('id', $parent_id)->where('organisation_id', $organisation_id)->exists()) {
            return null;
        }
        if ($location) {
            $p = $parent_id;
            for ($i = 0; $p && $i < 20; $i++) {
                if ($p == $location->id) {
                    return $location->parent_id;
                }
                $p = self::where('id', $p)->value('parent_id');
            }
        }
        return $parent_id;
    }

    /** Delete: sub-locations move up one level; addresses keep their organisation, without location. */
    public function deleteKeepingChildren()
    {
        self::where('parent_id', $this->id)->update(['parent_id' => $this->parent_id]);
        AddressLink::where('location_id', $this->id)->update(['location_id' => null]);
        \Eventy::action('mfsorganisations.location.deleting', $this);
        $this->delete();
    }
}
