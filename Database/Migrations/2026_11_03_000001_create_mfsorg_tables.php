<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MFS Organisations v1 (card #423). Own tables only: nothing is added to FreeScout's tables,
// so deactivating the module or updating FreeScout leaves core exactly as it was.
class CreateMfsorgTables extends Migration
{
    public function up()
    {
        Schema::create('mfsorg_organisations', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 191)->unique();
            $table->string('type', 50)->default('company');
            $table->boolean('active')->default(true);
            $table->string('color', 7)->default('#3f8abf');
            // New addresses with one of this organisation's domains are linked automatically.
            $table->boolean('auto_link')->default(true);
            $table->string('coc_number', 50)->nullable();
            $table->string('vat_number', 50)->nullable();
            $table->string('country', 2)->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('website', 191)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('mfsorg_domains', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('organisation_id')->unsigned()->index();
            $table->string('domain', 191)->unique();
            $table->timestamps();
        });

        Schema::create('mfsorg_departments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('organisation_id')->unsigned()->index();
            $table->integer('parent_id')->unsigned()->nullable();
            $table->string('name', 191);
            $table->timestamps();
        });

        // Keyed on the e-mail address itself, not FreeScout's emails.id: survives customer merges
        // and addresses moving between customers. organisation_id is null for an excluded address.
        Schema::create('mfsorg_address_links', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email', 191)->unique();
            $table->integer('organisation_id')->unsigned()->nullable()->index();
            $table->integer('department_id')->unsigned()->nullable();
            $table->unsignedTinyInteger('role')->default(1);
            $table->unsignedTinyInteger('source')->default(1);
            $table->boolean('active')->default(true);
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->timestamps();
        });

        // Which mailboxes an organisation belongs to. No rows = all mailboxes.
        Schema::create('mfsorg_mailboxes', function (Blueprint $table) {
            $table->integer('organisation_id')->unsigned();
            $table->integer('mailbox_id')->unsigned()->index();
            $table->primary(['organisation_id', 'mailbox_id']);
        });

        // A conversation's organisation, recorded when the conversation is created and fixed after
        // that, so history stays right when an address later moves to another organisation.
        Schema::create('mfsorg_conversations', function (Blueprint $table) {
            $table->integer('conversation_id')->unsigned()->primary();
            $table->integer('organisation_id')->unsigned()->index();
            $table->integer('department_id')->unsigned()->nullable();
            $table->unsignedTinyInteger('source')->default(1);
            $table->integer('set_by')->unsigned()->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('mfsorg_conversations');
        Schema::dropIfExists('mfsorg_mailboxes');
        Schema::dropIfExists('mfsorg_address_links');
        Schema::dropIfExists('mfsorg_departments');
        Schema::dropIfExists('mfsorg_domains');
        Schema::dropIfExists('mfsorg_organisations');
    }
}
