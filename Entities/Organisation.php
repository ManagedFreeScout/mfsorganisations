<?php

namespace Modules\MFSOrganisations\Entities;

use Illuminate\Database\Eloquent\Model;

class Organisation extends Model
{
    protected $table = 'mfsorg_organisations';

    protected $fillable = [
        'name', 'type', 'active', 'color', 'auto_link', 'coc_number', 'vat_number',
        'country', 'address', 'phone', 'website', 'notes', 'alert',
    ];

    protected $casts = [
        'active'    => 'boolean',
        'auto_link' => 'boolean',
    ];

    const TYPES = [
        'company'     => 'Company',
        'non_profit'  => 'Non-profit',
        'association' => 'Association / club',
        'government'  => 'Government',
        'education'   => 'Education',
        'other'       => 'Other',
    ];

    const COLORS = [
        '#3f8abf', '#2e7d32', '#c62828', '#ef6c00', '#6a1b9a', '#00838f',
        '#ad1457', '#5d4037', '#455a64', '#9e9d24', '#283593', '#00695c',
    ];

    public function domains()
    {
        return $this->hasMany(Domain::class, 'organisation_id')->orderBy('domain');
    }

    public function departments()
    {
        return $this->hasMany(Department::class, 'organisation_id')->orderBy('name');
    }

    public function links()
    {
        return $this->hasMany(AddressLink::class, 'organisation_id');
    }

    public function mailboxIds()
    {
        return \DB::table('mfsorg_mailboxes')->where('organisation_id', $this->id)->pluck('mailbox_id')->all();
    }

    public function typeName()
    {
        return __(self::TYPES[$this->type] ?? ucfirst($this->type));
    }

    public function url($tab = null)
    {
        return route('mfsorganisations.show', array_filter(['id' => $this->id, 'tab' => $tab]));
    }

    /** FreeScout's search, filtered on this organisation: all its conversations in the normal list. */
    public function searchUrl()
    {
        return route('conversations.search', ['q' => '', 'f' => ['organisation' => $this->id]]);
    }

    /**
     * Visible to this user: an admin sees all, an agent sees an organisation that belongs to all
     * mailboxes (no rows) or to at least one mailbox the agent can view.
     */
    public function scopeVisibleTo($query, $user)
    {
        if ($user->isAdmin()) {
            return $query;
        }
        $mailbox_ids = $user->mailboxesIdsCanView();
        return $query->where(function ($q) use ($mailbox_ids) {
            $q->whereNotExists(function ($sub) {
                $sub->select(\DB::raw(1))->from('mfsorg_mailboxes')
                    ->whereRaw(\DB::getTablePrefix().'mfsorg_mailboxes.organisation_id = '.\DB::getTablePrefix().'mfsorg_organisations.id');
            })->orWhereExists(function ($sub) use ($mailbox_ids) {
                $sub->select(\DB::raw(1))->from('mfsorg_mailboxes')
                    ->whereRaw(\DB::getTablePrefix().'mfsorg_mailboxes.organisation_id = '.\DB::getTablePrefix().'mfsorg_organisations.id')
                    ->whereIn('mfsorg_mailboxes.mailbox_id', $mailbox_ids ?: [0]);
            });
        });
    }

    public function isVisibleTo($user)
    {
        if ($user->isAdmin()) {
            return true;
        }
        $ids = $this->mailboxIds();
        return !$ids || array_intersect($ids, $user->mailboxesIdsCanView());
    }

    /** Text colour that stays readable on the badge colour. */
    public function textColor()
    {
        $hex = ltrim((string) $this->color, '#');
        if (strlen($hex) != 6) {
            return '#fff';
        }
        $l = (0.299 * hexdec(substr($hex, 0, 2)) + 0.587 * hexdec(substr($hex, 2, 2)) + 0.114 * hexdec(substr($hex, 4, 2))) / 255;
        return $l > 0.6 ? '#222' : '#fff';
    }
}
