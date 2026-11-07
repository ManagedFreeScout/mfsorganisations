<?php

namespace Modules\MFSOrganisations\Services;

use Modules\MFSOrganisations\Models\MFSOrganisationsLicense;

// Licence activate/validate/deactivate for MFSOrganisations, copied from MFS Essentials (card #194).
// The module never talks to invAIse itself: a self-hosted FreeScout must not hold StackPros'
// invAIse credentials (msteamsfs card #285). It asks the Managed FreeScout hub with only the
// licence key and this install's domain:
//   POST {hub_url}/modules/mfsorganisations/license/{activate|validate|deactivate}
//   {"license_key": "...", "domain": "..."}
// The hub calls invAIse with its own credentials, limited to this module's product, and
// passes invAIse's answer through unchanged (same contract as MSTeamsFS's /teams/license).
// A key for another product is answered like an unknown key (404 not_found).
//
// No licence, an expired subscription or no successful check for 14 days = every feature off
// (Rutger, 8 Oct 2026). The local modules_licenses row is the only thing features check.
class LicenseService
{
    const MODULE_ALIAS = 'mfsorganisations';

    /** @var \GuzzleHttp\Client|null injectable for tests */
    protected $httpClient;

    public function __construct($httpClient = null)
    {
        $this->httpClient = $httpClient;
    }

    private function statusMessage(string $status): string
    {
        switch ($status) {
            case 'active':
                return __('Licence is active.');
            case 'expired':
                return __('This licence has expired. Renew your subscription to use MFS Organisations again.');
            case 'suspended':
                return __('This licence has been suspended.');
            case 'revoked':
                return __('This licence has been revoked.');
            case 'not_activated_for_domain':
                return __('This licence is valid but not activated for this FreeScout installation. Click Activate.');
            case 'not_found':
                return __('Licence key not found. Check the key, and that it is a key for MFS Organisations.');
            case 'no_activations_left':
                return __('This licence is already in use on another FreeScout installation. Deactivate it there first, or buy another licence.');
            case 'unreachable':
                return __('Could not reach the Managed FreeScout licence server. Please try again later.');
            default:
                return __('Licence check failed.');
        }
    }

    /**
     * POST to the hub. Returns ['status' => int, 'data' => array] when the hub answered with
     * JSON (also for 4xx: invAIse's 404 not_found carries a normal JSON body), or null when the
     * hub could not be reached, answered without JSON, or could not reach invAIse (502).
     */
    protected function hubRequest(string $action, array $body): ?array
    {
        $hubUrl = rtrim((string) config('mfsorganisations.hub_url', ''), '/');
        if (!$hubUrl) {
            \Log::error('MFSOrganisations: mfsorganisations.hub_url not configured, cannot check the licence.');
            return null;
        }

        try {
            $client = $this->httpClient ?: new \GuzzleHttp\Client(['timeout' => 15]);
            $response = $client->request('POST', $hubUrl . '/modules/mfsorganisations/license/' . $action, [
                'headers'     => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'json'        => $body,
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            $data   = json_decode((string) $response->getBody(), true);
            if (!is_array($data) || $status >= 500) {
                \Log::error('MFSOrganisations: licence server answered ' . $status . ' for ' . $action);
                return null;
            }
            return ['status' => $status, 'data' => $data];
        } catch (\Exception $e) {
            \Log::error('MFSOrganisations: licence request failed: ' . $e->getMessage());
            return null;
        }
    }

    private function mapResponse(?array $raw): array
    {
        if ($raw === null) {
            return ['success' => false, 'valid' => false, 'status' => 'unreachable', 'message' => $this->statusMessage('unreachable')];
        }
        $data   = $raw['data'];
        $valid  = (bool) ($data['valid'] ?? false);
        $status = (string) ($data['status'] ?? ($valid ? 'active' : 'error'));

        return [
            'success'           => $valid,
            'valid'             => $valid,
            'status'            => $status,
            'message'           => $this->statusMessage($status),
            'data'              => $data,
            'expires_at'        => $data['expires_at'] ?? null,
            'activations_used'  => $data['activations_used'] ?? null,
            'activations_limit' => $data['activations_limit'] ?? null,
        ];
    }

    private function domain($domain = null): string
    {
        return $domain ?: parse_url(config('app.url'), PHP_URL_HOST) ?: request()->getHttpHost();
    }

    /**
     * Stores the outcome of a check. Always touches updated_at, also when nothing changed,
     * so the 14-day staleness backstop measures the last successful check (MSTeamsFS #258).
     */
    private function persist(string $licenseKey, string $domain, array $mapped, bool $keepExpiry = false): void
    {
        $license = MFSOrganisationsLicense::first() ?: new MFSOrganisationsLicense();
        $license->license_key   = $licenseKey;
        $license->is_valid      = $mapped['valid'];
        $license->status        = $mapped['status'];
        $license->domain        = $domain;
        $license->response_data = $mapped['data'] ?? null;
        if (!$keepExpiry) {
            $license->expires_at = null;
            if (!empty($mapped['expires_at'])) {
                try {
                    $license->expires_at = \Carbon\Carbon::parse($mapped['expires_at']);
                } catch (\Exception $e) {
                    $license->expires_at = null;
                }
            }
        }
        $license->save();
        $license->touch();
    }

    public function activateLicense($licenseKey, $domain = null)
    {
        $licenseKey = trim((string) $licenseKey);
        $domain     = $this->domain($domain);

        // A key belongs to one module (same guard as MSTeamsFS).
        $other = \DB::table('modules_licenses')->where('license_key', $licenseKey)
            ->where('module_alias', '!=', self::MODULE_ALIAS)->first();
        if ($other) {
            return ['success' => false, 'valid' => false, 'status' => 'in_use',
                'message' => __("This licence key is already used by the ':module' module.", ['module' => $other->module_alias])];
        }

        $mapped = $this->mapResponse($this->hubRequest('activate', ['license_key' => $licenseKey, 'domain' => $domain]));
        if (!$mapped['valid']) {
            return $mapped;
        }
        // invAIse's activate answer has no expiry date; validate right away to store it.
        $checked = $this->mapResponse($this->hubRequest('validate', ['license_key' => $licenseKey, 'domain' => $domain]));
        if (isset($checked['data'])) {
            $this->persist($licenseKey, $domain, $checked);
            return $checked['valid'] ? $mapped : $checked;
        }
        $this->persist($licenseKey, $domain, $mapped, true);
        return $mapped;
    }

    public function validateLicense($licenseKey, $domain = null)
    {
        $domain = $this->domain($domain);
        $mapped = $this->mapResponse($this->hubRequest('validate', ['license_key' => $licenseKey, 'domain' => $domain]));
        // Only a real answer changes the stored state; an unreachable server leaves it as is
        // (and the staleness backstop takes over after 14 days without a successful check).
        if (isset($mapped['data'])) {
            $this->persist($licenseKey, $domain, $mapped);
        }
        return $mapped;
    }

    public function deactivateLicense($licenseKey, $domain = null)
    {
        $domain = $this->domain($domain);
        $raw = $this->hubRequest('deactivate', ['license_key' => $licenseKey, 'domain' => $domain]);
        if ($raw === null) {
            return ['success' => false, 'status' => 'unreachable', 'message' => $this->statusMessage('unreachable')];
        }
        $success = (bool) ($raw['data']['success'] ?? false);
        if ($success) {
            $license = MFSOrganisationsLicense::where('license_key', $licenseKey)->first();
            if ($license) {
                $license->update(['is_valid' => false, 'status' => 'inactive', 'response_data' => $raw['data']]);
            }
        }
        return [
            'success' => $success,
            'message' => $success ? __('Licence deactivated. You can now activate it on another FreeScout installation.') : __('Licence deactivation failed.'),
        ];
    }

    /** Used by FreeScout core's own licence button on Manage → Modules. */
    public function performAction($action, $licenseKey)
    {
        switch ($action) {
            case 'activate':
                return $this->activateLicense($licenseKey);
            case 'deactivate':
                return $this->deactivateLicense($licenseKey);
            case 'validate':
                return $this->validateLicense($licenseKey);
            default:
                return ['success' => false, 'message' => __('Invalid action')];
        }
    }

    /** The one gate every feature checks: a local read, no remote call. Cached per request. */
    public static function isLicensed(): bool
    {
        static $cached = null;
        if (!config('mfsorganisations.enforce_license')) {
            return true;
        }
        if ($cached === null) {
            try {
                $cached = (bool) ((new self())->getLicenseStatus()['valid'] ?? false);
            } catch (\Exception $e) {
                $cached = false;
            }
        }
        return $cached;
    }

    /** Current licence state, local read only. */
    public function getLicenseStatus()
    {
        if (!$this->tableExists('modules_licenses')) {
            return ['valid' => false, 'status' => 'no_table', 'message' => __('Licence table missing. Run: php artisan migrate')];
        }
        try {
            $license = MFSOrganisationsLicense::first();
            if (!$license || !$license->license_key) {
                return ['valid' => false, 'status' => 'no_license', 'message' => __('No licence key entered yet.')];
            }
            $valid = $license->isValid();
            $stale = !$valid && $license->is_valid && $license->status === 'active' && !$license->isExpired();
            return [
                'valid'        => $valid,
                'status'       => $stale ? 'stale' : ($license->isExpired() ? 'expired' : $license->status),
                'message'      => $stale
                    ? __('The licence could not be checked for more than :days days. MFS Organisations is off until the next successful check.', ['days' => MFSOrganisationsLicense::MAX_STALE_DAYS])
                    : $this->statusMessage($license->isExpired() ? 'expired' : (string) $license->status),
                'license_key'  => $license->license_key,
                'expires_at'   => $license->expires_at,
                'is_expired'   => $license->isExpired(),
                'license_type' => $license->license_type,
                'checked_at'   => $license->updated_at,
            ];
        } catch (\Exception $e) {
            return ['valid' => false, 'status' => 'error', 'message' => __('Error reading the licence: ') . $e->getMessage()];
        }
    }

    private function tableExists($table)
    {
        try {
            return \Schema::hasTable($table);
        } catch (\Exception $e) {
            return false;
        }
    }
}
