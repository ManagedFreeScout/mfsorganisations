{{-- The organisation's fields (scope §3). $editable = false shows them read-only. --}}
@php $ro = empty($editable) ? 'disabled' : ''; @endphp

<div class="form-group{{ $errors->has('name') ? ' has-error' : '' }}">
    <label for="mfsorg-name" class="col-sm-2 control-label">{{ __('Name') }}</label>
    <div class="col-sm-6">
        <input id="mfsorg-name" type="text" class="form-control input-sized-lg" name="name" value="{{ old('name', $org->name) }}" maxlength="191" required {{ $ro }}>
        @include('partials/field_error', ['field' => 'name'])
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Type') }}</label>
    <div class="col-sm-6">
        <select name="type" class="form-control input-sized-lg" {{ $ro }}>
            @foreach (\Modules\MFSOrganisations\Entities\Organisation::TYPES as $key => $label)
                <option value="{{ $key }}" @if (old('type', $org->type) == $key) selected @endif>{{ __($label) }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Status') }}</label>
    <div class="col-sm-6">
        <div class="controls">
            <label class="checkbox-inline"><input type="checkbox" name="active" value="1" @if (old('active', $org->active)) checked @endif {{ $ro }}> {{ __('Active') }}</label>
        </div>
        <p class="block-help">{{ __('An inactive organisation keeps its history, but new addresses and conversations are no longer linked to it.') }}</p>
    </div>
</div>

<div class="form-group">
    <label for="mfsorg-alert" class="col-sm-2 control-label">{{ __('Alert') }}</label>
    <div class="col-sm-6">
        <input id="mfsorg-alert" type="text" class="form-control input-sized-lg" name="alert" value="{{ old('alert', $org->alert) }}" maxlength="255" placeholder="{{ __('e.g. Contract ends 31 Dec, or: Phone support only') }}" {{ $ro }}>
        <p class="block-help">{{ __('Optional. Shown to agents on every conversation of this organisation.') }}</p>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Colour') }}</label>
    <div class="col-sm-6 mfsorg-colors">
        @foreach (\Modules\MFSOrganisations\Entities\Organisation::COLORS as $color)
            <label class="mfsorg-color" style="background:{{ $color }}">
                <input type="radio" name="color" value="{{ $color }}" @if (strtolower(old('color', $org->color)) == $color) checked @endif {{ $ro }}>
            </label>
        @endforeach
    </div>
</div>

<div class="form-group">
    <label for="mfsorg-domains" class="col-sm-2 control-label">{{ __('Domains') }}</label>
    <div class="col-sm-6">
        <textarea id="mfsorg-domains" name="domains" class="form-control input-sized-lg" rows="3" placeholder="acme.com" {{ $ro }}>{{ old('domains', $org->exists ? $org->domains->pluck('domain')->implode("\n") : ($prefill_domain ?? '')) }}</textarea>
        <label class="checkbox-inline margin-top-10"><input type="checkbox" name="auto_link" value="1" @if (old('auto_link', $org->auto_link)) checked @endif {{ $ro }}> {{ __('Link addresses with these domains automatically') }}</label>
        <p class="block-help">{{ __('One per line. A domain belongs to one organisation only. Free-mail domains (gmail.com and the like) and your own mailbox domains are never linked automatically: link those addresses by hand.') }}</p>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Mailboxes') }}</label>
    <div class="col-sm-6">
        @php $allMailboxes = empty($mailbox_ids); @endphp
        @foreach ($mailboxes as $mailbox)
            <label class="checkbox-inline mfsorg-mailbox"><input type="checkbox" name="mailboxes[]" value="{{ $mailbox->id }}" @if ($allMailboxes || in_array($mailbox->id, $mailbox_ids)) checked @endif {{ $ro }}> {{ $mailbox->name }}</label>
        @endforeach
        <p class="block-help">{{ __('Agents see this organisation if they can see at least one of these mailboxes. All ticked = every mailbox, also new ones.') }}</p>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Registration') }}</label>
    <div class="col-sm-6 mfsorg-inline-fields">
        <input type="text" name="coc_number" class="form-control" value="{{ old('coc_number', $org->coc_number) }}" placeholder="{{ __('Chamber of Commerce no.') }}" maxlength="50" {{ $ro }}>
        <input type="text" name="vat_number" class="form-control" value="{{ old('vat_number', $org->vat_number) }}" placeholder="{{ __('VAT no.') }}" maxlength="50" {{ $ro }}>
        <input type="text" name="country" class="form-control mfsorg-country" value="{{ old('country', $org->country) }}" placeholder="{{ __('Country (NL)') }}" maxlength="2" {{ $ro }}>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Contact') }}</label>
    <div class="col-sm-6 mfsorg-inline-fields">
        <input type="text" name="phone" class="form-control" value="{{ old('phone', $org->phone) }}" placeholder="{{ __('Phone') }}" maxlength="60" {{ $ro }}>
        <input type="text" name="website" class="form-control" value="{{ old('website', $org->website) }}" placeholder="{{ __('Website') }}" maxlength="191" {{ $ro }}>
    </div>
</div>

<div class="form-group">
    <label for="mfsorg-address" class="col-sm-2 control-label">{{ __('Address') }}</label>
    <div class="col-sm-6">
        <textarea id="mfsorg-address" name="address" class="form-control input-sized-lg" rows="2" {{ $ro }}>{{ old('address', $org->address) }}</textarea>
    </div>
</div>

<div class="form-group">
    <label for="mfsorg-notes" class="col-sm-2 control-label">{{ __('Notes') }}</label>
    <div class="col-sm-6">
        <textarea id="mfsorg-notes" name="notes" class="form-control input-sized-lg" rows="3" {{ $ro }}>{{ old('notes', $org->notes) }}</textarea>
        <p class="block-help">{{ __('Internal: only agents see this.') }}</p>
    </div>
</div>
