<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Alert note on an organisation, shown on every conversation of it (card #423, 6 Nov 2026).
class AddAlertToMfsorgOrganisations extends Migration
{
    public function up()
    {
        Schema::table('mfsorg_organisations', function (Blueprint $table) {
            $table->string('alert', 255)->nullable()->after('notes');
        });
    }

    public function down()
    {
        Schema::table('mfsorg_organisations', function (Blueprint $table) {
            $table->dropColumn('alert');
        });
    }
}
