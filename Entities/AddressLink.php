<?php

namespace Modules\MFSOrganisations\Entities;

use Illuminate\Database\Eloquent\Model;

class AddressLink extends Model
{
    protected $table = 'mfsorg_address_links';

    protected $fillable = [
        'email', 'organisation_id', 'department_id', 'location_id', 'role', 'source', 'active', 'active_from', 'active_to',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    protected $dates = ['active_from', 'active_to'];

    // How the address got its organisation.
    const SOURCE_DOMAIN   = 1;
    const SOURCE_MANUAL   = 2;
    // Removed by hand: never linked again automatically. organisation_id is null.
    const SOURCE_EXCLUDED = 3;

    // Stored now for MFS Portal; no effect in v1.
    const ROLE_MEMBER  = 1;
    const ROLE_DEPT_MANAGER = 2;
    const ROLE_MANAGER = 3;

    /** Not shown in v1 (Rutger, 3 Nov 2026): the classification is still to be decided. */
    public static function roles()
    {
        return [
            self::ROLE_MEMBER       => __('Member'),
            self::ROLE_DEPT_MANAGER => __('Department manager'),
            self::ROLE_MANAGER      => __('Manager'),
        ];
    }

    public static function sourceName($source)
    {
        $names = [
            self::SOURCE_DOMAIN   => __('Domain'),
            self::SOURCE_MANUAL   => __('By hand'),
            self::SOURCE_EXCLUDED => __('Excluded'),
        ];
        return $names[$source] ?? '';
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    /** Linked and in force today: active, within its optional dates, organisation active. */
    public function isInForce()
    {
        if ($this->source == self::SOURCE_EXCLUDED || !$this->organisation_id || !$this->active) {
            return false;
        }
        $today = now()->startOfDay();
        if ($this->active_from && $this->active_from->gt($today)) {
            return false;
        }
        if ($this->active_to && $this->active_to->lt($today)) {
            return false;
        }
        return $this->organisation && $this->organisation->active;
    }
}
