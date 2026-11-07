@php
    $licenseStatus = $settings['license_status'] ?? ['valid' => false, 'status' => 'inactive', 'message' => ''];
    $isValid = $licenseStatus['valid'] ?? false;
    $licenseKey = $licenseStatus['license_key'] ?? '';
@endphp

<div class="row">
    <div class="col-xs-12">
        <div class="panel panel-default">
            <div class="panel-heading">{{ __('Licence') }}</div>
            <div class="panel-body">
                <div class="license-status-message">
                    @if($isValid)
                        <div class="alert alert-success">
                            <i class="glyphicon glyphicon-ok"></i> {{ __('Licence is active. All MFS Organisations features are on.') }}
                            @if(!empty($licenseStatus['expires_at']))
                                {{ __('Paid until :date.', ['date' => \Carbon\Carbon::parse($licenseStatus['expires_at'])->format('j M Y')]) }}
                            @endif
                        </div>
                    @else
                        <div class="alert alert-warning">
                            <i class="glyphicon glyphicon-warning-sign"></i>
                            @if(!empty($licenseStatus['message']) && ($licenseStatus['status'] ?? '') !== 'no_license')
                                {{ $licenseStatus['message'] }}
                            @endif
                            {{ __('MFS Organisations is switched off until a valid licence is active. Your organisations and links stay stored and are back as soon as the licence is active.') }}
                            <a href="{{ config('mfsorganisations.buy_url') }}" target="_blank" rel="noopener">{{ __('Buy or renew a licence') }}</a>
                        </div>
                    @endif
                    @if(!empty($licenseStatus['checked_at']))
                        <p class="text-help">{{ __('Last checked with the licence server: :when. Checked automatically every 6 hours.', ['when' => \App\User::dateDiffForHumans($licenseStatus['checked_at'])]) }}</p>
                    @endif
                </div>

                <form id="license-management-form" class="form-horizontal margin-top">
                    {{ csrf_field() }}
                    <input type="hidden" name="action" id="license-action" value="">

                    <div class="form-group">
                        <label for="license_key" class="col-sm-2 control-label">{{ __('Licence key') }}</label>
                        <div class="col-sm-6">
                            <input type="text" class="form-control" name="license_key" id="license_key_input"
                                    value="{{ $licenseKey }}"
                                    placeholder="{{ __('Enter your licence key') }}">
                            <div class="help-block">{{ __('Your licence key: see the confirmation email you received after your purchase.') }}</div>
                            <div class="help-block">{{ __('By using this module you agree to our') }} <a href="{{ config('mfsorganisations.terms_url') }}" target="_blank" rel="noopener">{{ __('terms and conditions') }}</a>.</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-sm-2"></div>
                        <div class="col-sm-10">
                            <button type="button" class="btn btn-success" id="activate-license-btn">
                                <i class="glyphicon glyphicon-ok-circle"></i> {{ __('Activate licence') }}
                            </button>
                            @if($isValid)
                                <button type="button" class="btn btn-danger" id="deactivate-license-btn">
                                    <i class="glyphicon glyphicon-remove-circle"></i> {{ __('Deactivate licence') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </form>

                <div id="license-ajax-response" class="mt-3" style="display: none;"></div>
            </div>

            <script type="text/javascript" {!! \Helper::cspNonceAttr() !!}>
                function performLicenseAction(action) {
                    const licenseKey = document.getElementById('license_key_input').value.trim();
                    const responseDiv = document.getElementById('license-ajax-response');
                    const actionInput = document.getElementById('license-action');

                    responseDiv.style.display = 'none';
                    responseDiv.innerHTML = '';

                    if (action === 'activate' && !licenseKey) {
                        showLicenseError('{{ __("License key is required.") }}');
                        return false;
                    }

                    const buttons = document.querySelectorAll('.btn[data-license-action]');
                    buttons.forEach(button => {
                        button.disabled = true;
                        button.classList.add('disabled');
                    });

                    actionInput.value = action;
                    const formData = new FormData();
                    formData.append('_token', '{{ csrf_token() }}');
                    formData.append('action', action);
                    formData.append('license_key', licenseKey);

                    fetch('{{ route("mfsorganisations.license.manage") }}', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showLicenseSuccess(data.message || '{{ __("Operation completed successfully.") }}');
                            setTimeout(() => {
                                window.location.reload();
                            }, 1500);
                        } else {
                            showLicenseError(data.message || '{{ __("An error occurred during the operation.") }}');
                        }
                    })
                    .catch(error => {
                        console.error('License API Error:', error);
                        showLicenseError('{{ __("An error occurred while processing your request. Please try again.") }}');
                    })
                    .finally(() => {
                        buttons.forEach(button => {
                            button.disabled = false;
                            button.classList.remove('disabled');
                        });
                    });

                    return false;
                }

                function showLicenseSuccess(message) {
                    const responseDiv = document.getElementById('license-ajax-response');
                    responseDiv.innerHTML = `
                        <div class="alert alert-success" role="alert">
                            <i class="glyphicon glyphicon-ok-sign"></i>
                            ${message}
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                        </div>
                    `;
                    responseDiv.style.display = 'block';
                }

                function showLicenseError(message) {
                    const responseDiv = document.getElementById('license-ajax-response');
                    responseDiv.innerHTML = `
                        <div class="alert alert-danger" role="alert">
                            <i class="glyphicon glyphicon-exclamation-sign"></i>
                            ${message}
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                        </div>
                    `;
                    responseDiv.style.display = 'block';
                }

                document.addEventListener('DOMContentLoaded', function() {
                    const activateBtn = document.getElementById('activate-license-btn');
                    const deactivateBtn = document.getElementById('deactivate-license-btn');

                    if (activateBtn) {
                        activateBtn.setAttribute('data-license-action', 'activate');
                        activateBtn.addEventListener('click', function() {
                            performLicenseAction('activate');
                        });
                    }

                    if (deactivateBtn) {
                        deactivateBtn.setAttribute('data-license-action', 'deactivate');
                        deactivateBtn.addEventListener('click', function() {
                            performLicenseAction('deactivate');
                        });
                    }
                });
            </script>
        </div>
    </div>
</div>
