<?php

namespace Modules\MFSOrganisations\Entities;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $table = 'mfsorg_departments';

    protected $fillable = ['organisation_id', 'parent_id', 'name'];

    /** Departments of an organisation as a flat list in tree order: [id => "Sales › Inside sales"]. */
    public static function treeOptions($organisation_id)
    {
        $all = self::where('organisation_id', $organisation_id)->orderBy('name')->get();
        $by_parent = [];
        foreach ($all as $d) {
            $by_parent[(int) $d->parent_id][] = $d;
        }
        $out = [];
        $walk = function ($parent_id, $prefix, $depth) use (&$walk, &$out, $by_parent) {
            if ($depth > 10) {
                return;
            }
            foreach ($by_parent[$parent_id] ?? [] as $d) {
                $label = $prefix === '' ? $d->name : $prefix.' › '.$d->name;
                $out[$d->id] = $label;
                $walk($d->id, $label, $depth + 1);
            }
        };
        $walk(0, '', 0);
        return $out;
    }
}
