{{-- Locations tab (card #430): a tree like the departments, e.g. site › building › floor › room. --}}
@php $types = \Modules\MFSOrganisations\Entities\Location::types(); @endphp
@if ($can_manage)
    <form method="POST" action="{{ route('mfsorganisations.locations', ['id' => $org->id]) }}" class="form-inline margin-top mfsorg-loc-add">
        {{ csrf_field() }}
        <input type="hidden" name="action" value="add">
        <input type="text" name="name" class="form-control" placeholder="{{ __('New location, e.g. Head office') }}" maxlength="191" required>
        <select name="type" class="form-control" aria-label="{{ __('Type') }}">
            @foreach ($types as $type_key => $type_name)
                <option value="{{ $type_key }}">{{ $type_name }}</option>
            @endforeach
        </select>
        <select name="parent_id" class="form-control" aria-label="{{ __('Where') }}">
            <option value="">{{ __('Top level') }}</option>
            @foreach ($locations as $loc_id => $loc_name)
                <option value="{{ $loc_id }}">{{ __('Under') }} {{ $loc_name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-default">{{ __('Add') }}</button>
    </form>
@endif

<div class="table-responsive margin-top">
    <table class="table table-striped mfsorg-table mfsorg-loc-table">
        <tbody>
            @forelse ($locations as $loc_id => $loc_name)
                @php
                    $parts = explode(' › ', $loc_name);
                    $own = array_pop($parts);
                    $loc = \Modules\MFSOrganisations\Entities\Location::find($loc_id);
                @endphp
                @if ($loc)
                <tr>
                    <td style="padding-left: {{ 8 + 20 * count($parts) }}px">
                        @if ($can_manage)
                            <form method="POST" action="{{ route('mfsorganisations.locations', ['id' => $org->id]) }}" class="mfsorg-loc-form">
                                {{ csrf_field() }}
                                <input type="hidden" name="location_id" value="{{ $loc_id }}">
                                <div class="form-inline">
                                    <input type="text" name="name" value="{{ $own }}" class="form-control input-sm" maxlength="191" required aria-label="{{ __('Name') }}">
                                    <select name="type" class="form-control input-sm" aria-label="{{ __('Type') }}">
                                        @foreach ($types as $type_key => $type_name)
                                            <option value="{{ $type_key }}" @if ($loc->type == $type_key) selected @endif>{{ $type_name }}</option>
                                        @endforeach
                                    </select>
                                    <select name="parent_id" class="form-control input-sm" aria-label="{{ __('Where') }}">
                                        <option value="">{{ __('Top level') }}</option>
                                        @foreach ($locations as $other_id => $other_name)
                                            @if ($other_id != $loc_id)
                                                <option value="{{ $other_id }}" @if ($loc->parent_id == $other_id) selected @endif>{{ __('Under') }} {{ $other_name }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    <a href="#" class="btn btn-link btn-sm" data-toggle="collapse" data-target="#mfsorg-loc-more-{{ $loc_id }}">{{ __('Address and notes') }}@if ($loc->address || $loc->notes) ✓@endif</a>
                                    <button type="submit" name="action" value="update" class="btn btn-default btn-sm">{{ __('Save') }}</button>
                                    <button type="submit" name="action" value="delete" class="btn btn-link btn-sm" data-mfsorg-confirm="{{ __('Delete location :name? Sub-locations move up one level; addresses keep their organisation, without this location.', ['name' => $own]) }}">{{ __('Delete') }}</button>
                                </div>
                                <div class="collapse mfsorg-loc-more" id="mfsorg-loc-more-{{ $loc_id }}">
                                    <textarea name="address" class="form-control input-sm" rows="2" placeholder="{{ __('Postal address (optional, e.g. for a site)') }}">{{ $loc->address }}</textarea>
                                    <textarea name="notes" class="form-control input-sm" rows="2" placeholder="{{ __('Notes (optional, only agents see them)') }}">{{ $loc->notes }}</textarea>
                                </div>
                            </form>
                        @else
                            <strong>{{ $own }}</strong> <small class="text-help">{{ $loc->typeName() }}</small>
                            @if ($loc->address)<div class="text-help mfsorg-pre">{{ $loc->address }}</div>@endif
                            @if ($loc->notes)<div class="text-help mfsorg-pre">{{ $loc->notes }}</div>@endif
                        @endif
                    </td>
                </tr>
                @endif
            @empty
                <tr><td class="text-help">{{ __('No locations yet. Add the places of this organisation, e.g. a site with its buildings, floors and rooms. You can then give each address a default location.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
