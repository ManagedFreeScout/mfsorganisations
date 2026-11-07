<?php

// Organisations: every agent can look them up; changes are checked in the controller
// (permission "Manage organisations", some actions admin only).
Route::group(['middleware' => ['web', 'auth'], 'namespace' => 'Modules\MFSOrganisations\Http\Controllers'], function () {
    Route::get('/mfsorganisations', 'OrganisationsController@index')->name('mfsorganisations.index');
    Route::get('/mfsorganisations/new', 'OrganisationsController@create')->name('mfsorganisations.create');
    Route::post('/mfsorganisations/new', 'OrganisationsController@store')->name('mfsorganisations.store');
    Route::post('/mfsorganisations/import', 'OrganisationsController@import')->name('mfsorganisations.import');
    Route::match(['get', 'post'], '/mfsorganisations/kanban-filter', 'OrganisationsController@kanbanFilter')->name('mfsorganisations.kanban_filter');
    Route::get('/mfsorganisations/{id}', 'OrganisationsController@show')->name('mfsorganisations.show');
    Route::post('/mfsorganisations/{id}', 'OrganisationsController@update')->name('mfsorganisations.update');
    Route::post('/mfsorganisations/{id}/delete', 'OrganisationsController@destroy')->name('mfsorganisations.delete');
    Route::post('/mfsorganisations/{id}/merge', 'OrganisationsController@merge')->name('mfsorganisations.merge');
    Route::post('/mfsorganisations/{id}/addresses', 'OrganisationsController@addresses')->name('mfsorganisations.addresses');
    Route::post('/mfsorganisations/{id}/departments', 'OrganisationsController@departments')->name('mfsorganisations.departments');
    Route::get('/mfsorganisations/{id}/export', 'OrganisationsController@export')->name('mfsorganisations.export');
    Route::post('/mfsorganisations/conversation/{id}', 'OrganisationsController@changeConversation')->name('mfsorganisations.conversation');
});

// Settings, backfill and licence: admins only.
Route::group(['middleware' => ['web', 'auth', 'roles'], 'roles' => ['admin'], 'namespace' => 'Modules\MFSOrganisations\Http\Controllers'], function () {
    Route::post('/admin/mfsorganisations/settings', 'SettingsController@save')->name('mfsorganisations.settings.save');
    Route::get('/admin/mfsorganisations/backfill/preview', 'SettingsController@preview')->name('mfsorganisations.backfill.preview');
    Route::post('/admin/mfsorganisations/backfill/start', 'SettingsController@start')->name('mfsorganisations.backfill.start');
    Route::post('/admin/mfsorganisations/backfill/step', 'SettingsController@step')->name('mfsorganisations.backfill.step');
    Route::post('/admin/mfsorganisations/license/manage', 'SettingsController@manageLicense')->name('mfsorganisations.license.manage');
    Route::post('/admin/mfsorganisations/module-license-action', 'SettingsController@handleModuleLicenseAction')->name('mfsorganisations.module.license.action');
});

// REST API (card #423): only with the official API & Webhooks module, behind its API key check.
// Static paths before {id}.
if (\Module::isActive('apiwebhooks') && class_exists('\Modules\ApiWebhooks\Http\Middleware\ApiAuth')) {
    Route::group(['middleware' => ['bindings', \Modules\ApiWebhooks\Http\Middleware\ApiAuth::class], 'prefix' => \Helper::getSubdirectory(true).'api/', 'namespace' => 'Modules\MFSOrganisations\Http\Controllers'], function () {
        Route::get('/organisations/lookup', 'ApiController@lookup');
        Route::get('/organisations', 'ApiController@index');
        Route::post('/organisations', 'ApiController@store');
        Route::get('/organisations/{id}', 'ApiController@show')->where('id', '[0-9]+');
        Route::match(['put', 'patch'], '/organisations/{id}', 'ApiController@update')->where('id', '[0-9]+');
        Route::delete('/organisations/{id}', 'ApiController@destroy')->where('id', '[0-9]+');
        Route::post('/organisations/{id}/domains', 'ApiController@addDomain')->where('id', '[0-9]+');
        Route::delete('/organisations/{id}/domains/{domain}', 'ApiController@removeDomain')->where('id', '[0-9]+');
        Route::get('/organisations/{id}/addresses', 'ApiController@addresses')->where('id', '[0-9]+');
        Route::post('/organisations/{id}/addresses', 'ApiController@linkAddress')->where('id', '[0-9]+');
        Route::delete('/organisations/{id}/addresses/{email}', 'ApiController@removeAddress')->where(['id' => '[0-9]+', 'email' => '[^/]+']);
        Route::get('/organisations/{id}/conversations', 'ApiController@conversations')->where('id', '[0-9]+');
        Route::get('/organisations/{id}/departments', 'ApiController@departments')->where('id', '[0-9]+');
        Route::post('/organisations/{id}/departments', 'ApiController@addDepartment')->where('id', '[0-9]+');
    });
}
