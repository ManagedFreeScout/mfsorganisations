<?php

namespace Modules\MFSOrganisations\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\MFSOrganisations\Providers\MFSOrganisationsServiceProvider as Provider;
use Modules\MFSOrganisations\Services\Backfill;
use Modules\MFSOrganisations\Services\LicenseService;
use Modules\MFSOrganisations\Services\Linker;

/** Manage → Settings → MFS Organisations (admins only, see routes.php). */
class SettingsController extends Controller
{
    public function save(Request $request)
    {
        $list = Linker::parseDomainList((string) $request->input('freemail'));
        \Option::set(Linker::OPTION_FREEMAIL, implode("\n", $list));
        \Option::set(Provider::OPTION_HIDE_BADGE, array_values(array_map('intval', (array) $request->input('hide_badge', []))));
        \Option::set(Provider::OPTION_HIDE_PANEL, array_values(array_map('intval', (array) $request->input('hide_panel', []))));
        Linker::flush();

        \Session::flash('flash_success_floating', __('Settings saved.'));
        return redirect()->route('settings', ['section' => Provider::MODULE_ALIAS]);
    }

    public function preview()
    {
        if (!LicenseService::isLicensed()) {
            abort(403);
        }
        return view('mfsorganisations::settings.partials.preview', ['p' => Backfill::preview()]);
    }

    public function start()
    {
        if (!LicenseService::isLicensed()) {
            abort(403);
        }
        if (!Backfill::isRunning()) {
            Backfill::start(Auth::id());
        }
        return response()->json($this->progress(Backfill::state()));
    }

    public function step()
    {
        if (!LicenseService::isLicensed()) {
            abort(403);
        }
        return response()->json($this->progress(Backfill::step()));
    }

    protected function progress($state)
    {
        return [
            'status'   => $state['status'] ?? '',
            'percent'  => Backfill::percent($state),
            'linked'   => $state['linked'] ?? 0,
            'recorded' => $state['recorded'] ?? 0,
            'text'     => ($state['status'] ?? '') == 'done'
                ? __('Done: :a addresses linked, :c conversations given an organisation.', ['a' => $state['linked'] ?? 0, 'c' => $state['recorded'] ?? 0])
                : __(':phase… :a addresses linked, :c conversations so far.', [
                    'phase' => ($state['phase'] ?? '') == 'conversations' ? __('Conversations') : __('Addresses'),
                    'a'     => $state['linked'] ?? 0,
                    'c'     => $state['recorded'] ?? 0,
                ]),
        ];
    }

    // Licence, same as MFS Essentials (card #194).

    public function manageLicense(Request $request)
    {
        $service = app(LicenseService::class);
        if ($service->getLicenseStatus()['status'] === 'no_table') {
            return response()->json(['status' => 'error', 'message' => __('License table does not exist. Please run migrations first.')]);
        }
        $action = $request->input('action');
        $key = $request->input('license_key');
        if (empty($key) && $action !== 'deactivate') {
            return response()->json(['status' => 'error', 'message' => __('License key is required.')]);
        }
        if ($action === 'activate') {
            $result = $service->activateLicense($key);
        } elseif ($action === 'deactivate') {
            $result = $service->deactivateLicense($service->getLicenseStatus()['license_key'] ?? $key);
        } else {
            return response()->json(['status' => 'error', 'message' => __('Invalid action.')]);
        }
        return response()->json(['status' => $result['success'] ? 'success' : 'error', 'message' => $result['message']]);
    }

    public function handleModuleLicenseAction(Request $request)
    {
        if ($request->input('module_alias') !== Provider::MODULE_ALIAS) {
            return response()->json(['success' => false, 'message' => __('Invalid module')]);
        }
        $key = $request->input('license_key');
        if (empty($key) && $request->input('action') !== 'deactivate') {
            return response()->json(['success' => false, 'message' => __('License key is required')]);
        }
        return response()->json(app(LicenseService::class)->performAction($request->input('action'), $key));
    }
}
