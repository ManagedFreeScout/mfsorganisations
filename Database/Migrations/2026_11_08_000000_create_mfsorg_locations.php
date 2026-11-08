<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Locations per organisation (card #430, 8 Nov 2026): a tree like site › building › floor › room,
// and an optional default location per address (where the person usually works). Own tables only.
class CreateMfsorgLocations extends Migration
{
    public function up()
    {
        Schema::create('mfsorg_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('organisation_id')->unsigned()->index();
            $table->integer('parent_id')->unsigned()->nullable();
            $table->string('name', 191);
            $table->string('type', 20)->default('site');
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('mfsorg_address_links', function (Blueprint $table) {
            $table->integer('location_id')->unsigned()->nullable()->after('department_id');
        });
    }

    public function down()
    {
        Schema::table('mfsorg_address_links', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });
        Schema::dropIfExists('mfsorg_locations');
    }
}
