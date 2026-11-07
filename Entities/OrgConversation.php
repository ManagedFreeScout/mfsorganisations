<?php

namespace Modules\MFSOrganisations\Entities;

use Illuminate\Database\Eloquent\Model;

class OrgConversation extends Model
{
    protected $table = 'mfsorg_conversations';

    protected $primaryKey = 'conversation_id';

    public $incrementing = false;

    protected $fillable = ['conversation_id', 'organisation_id', 'department_id', 'source', 'set_by'];

    // How the conversation got its organisation.
    const SOURCE_ADDRESS  = 1; // the address it came from, when it was created
    const SOURCE_CUSTOMER = 2; // no address: the customer's only organisation
    const SOURCE_BACKFILL = 3;
    const SOURCE_AGENT    = 4; // changed by hand

    public function organisation()
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }
}
