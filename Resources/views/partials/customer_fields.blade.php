{{-- Customer edit form: the organisation per e-mail address (scope §4.3) and its default location (card #430). Saved on customer.updated. --}}
<div class="form-group mfsorg-customer-fields">
    <label class="col-sm-2 control-label">{{ __('Organisation') }}</label>
    <div class="col-sm-6">
        @foreach ($emails as $i => $email)
            @php
                $link = $links[strtolower($email)] ?? null;
                $value = !$link ? '' : ($link->source == \Modules\MFSOrganisations\Entities\AddressLink::SOURCE_EXCLUDED ? 'exclude'
                    : ($link->source == \Modules\MFSOrganisations\Entities\AddressLink::SOURCE_MANUAL ? (string) $link->organisation_id : ''));
                $auto = ($link && $link->source == \Modules\MFSOrganisations\Entities\AddressLink::SOURCE_DOMAIN) ? $link->organisation : null;
            @endphp
            <div class="mfsorg-customer-row">
                <input type="hidden" name="mfsorg[{{ $i }}][email]" value="{{ $email }}">
                @if (count($emails) > 1)<div class="text-help">{{ $email }}</div>@endif
                <select name="mfsorg[{{ $i }}][organisation_id]" class="form-control input-sized-lg mfsorg-select">
                    <option value="" @if ($value === '') selected @endif>
                        {{ __('Automatic') }}: {{ $auto ? $auto->name : __('by domain (none)') }}
                    </option>
                    <option value="exclude" @if ($value === 'exclude') selected @endif>{{ __('None: unlink past conversations, never link automatically') }}</option>
                    <optgroup label="{{ __('Link by hand to') }}">
                        @foreach ($orgs as $org)
                            <option value="{{ $org->id }}" @if ($value === (string) $org->id) selected @endif>{{ $org->name }}@if (!$org->active) ({{ __('inactive') }})@endif</option>
                        @endforeach
                    </optgroup>
                </select>
                <label class="checkbox-inline text-help"><input type="checkbox" name="mfsorg[{{ $i }}][move]" value="1"> {{ __('Also move past conversations of this address') }}</label>
                @php $loc_options = ($link && $link->organisation_id) ? ($locations[$link->organisation_id] ?? []) : []; @endphp
                @if (count($loc_options))
                    {{-- Card #430: default location within the address's current organisation. --}}
                    <input type="hidden" name="mfsorg[{{ $i }}][location_org]" value="{{ $link->organisation_id }}">
                    <select name="mfsorg[{{ $i }}][location_id]" class="form-control input-sized-lg mfsorg-customer-location" aria-label="{{ __('Location') }}">
                        <option value="">{{ __('Location') }}: {{ __('none') }}</option>
                        @foreach ($loc_options as $loc_id => $loc_name)
                            <option value="{{ $loc_id }}" @if ($link->location_id == $loc_id) selected @endif>{{ __('Location') }}: {{ $loc_name }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        @endforeach
    </div>
</div>
