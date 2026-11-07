<?php

namespace Modules\MFSOrganisations\Entities;

use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    protected $table = 'mfsorg_domains';

    protected $fillable = ['organisation_id', 'domain'];

    public function organisation()
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    /** "@Acme.COM " → "acme.com"; null when it isn't a plausible domain. */
    public static function normalize($domain)
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('#^(https?://)?(www\.)?#', '', $domain);
        $domain = ltrim(preg_replace('#[/\s].*$#', '', $domain), '@');
        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain) ? $domain : null;
    }
}
