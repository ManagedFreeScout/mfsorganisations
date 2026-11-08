@if ($can_manage)
    <form method="POST" action="{{ route('mfsorganisations.addresses', ['id' => $org->id]) }}" class="margin-top mfsorg-add-address">
        {{ csrf_field() }}
        <input type="hidden" name="action" value="add">
        <div class="input-group">
            <input type="text" name="emails" class="form-control" placeholder="{{ __('Add addresses by hand, e.g. secretary@gmail.com (several: separate with spaces)') }}" required>
            <span class="input-group-btn"><button type="submit" class="btn btn-default">{{ __('Link') }}</button></span>
        </div>
        <label class="checkbox-inline text-help"><input type="checkbox" name="move" value="1"> {{ __('If an address belonged to another organisation: also move its past conversations here') }}</label>
    </form>
@endif

<div class="table-responsive margin-top">
    <table class="table table-striped mfsorg-table">
        <thead>
            <tr>
                <th>{{ __('Address') }}</th>
                <th>{{ __('Customer') }}</th>
                <th>{{ __('Linked') }}</th>
                @if ($can_manage)
                    <th>{{ __('Department, location and link period') }}</th>
                @else
                    <th>{{ __('Department') }}</th>
                    <th>{{ __('Location') }}</th>
                    <th>{{ __('Active') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($links as $link)
                @php $customer = $customers[$link->email] ?? null; @endphp
                <tr @if (!$link->isInForce()) class="text-muted" @endif>
                    <td>{{ $link->email }}</td>
                    <td>@if ($customer)<a href="{{ route('customers.update', ['id' => $customer->id]) }}">{{ $customer->getFullName(true) }}</a>@else<span class="text-help">{{ __('not a customer yet') }}</span>@endif</td>
                    <td>{{ \Modules\MFSOrganisations\Entities\AddressLink::sourceName($link->source) }} <small class="text-help">{{ \App\User::dateFormat($link->created_at, 'j M Y') }}</small></td>
                    @if ($can_manage)
                        <td>
                            <form method="POST" action="{{ route('mfsorganisations.addresses', ['id' => $org->id]) }}" class="form-inline mfsorg-link-form">
                                {{ csrf_field() }}
                                <input type="hidden" name="link_id" value="{{ $link->id }}">
                                <select name="department_id" class="form-control input-sm" aria-label="{{ __('Department') }}">
                                    <option value="">–</option>
                                    @foreach ($departments as $dept_id => $dept_name)
                                        <option value="{{ $dept_id }}" @if ($link->department_id == $dept_id) selected @endif>{{ $dept_name }}</option>
                                    @endforeach
                                </select>
                                @if (count($locations))
                                    <select name="location_id" class="form-control input-sm" aria-label="{{ __('Location') }}" title="{{ __('Default location (where this person usually works)') }}">
                                        <option value="">{{ __('No location') }}</option>
                                        @foreach ($locations as $loc_id => $loc_name)
                                            <option value="{{ $loc_id }}" @if ($link->location_id == $loc_id) selected @endif>{{ $loc_name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <label class="checkbox-inline"><input type="checkbox" name="active" value="1" @if ($link->active) checked @endif> {{ __('Active') }}</label>
                                <span class="mfsorg-period">
                                    <span class="text-help">{{ __('Linked from') }}</span>
                                    <input type="date" name="active_from" class="form-control input-sm" value="{{ $link->active_from ? $link->active_from->toDateString() : '' }}" aria-label="{{ __('Linked from (optional)') }}">
                                    <span class="text-help">{{ __('until') }}</span>
                                    <input type="date" name="active_to" class="form-control input-sm" value="{{ $link->active_to ? $link->active_to->toDateString() : '' }}" aria-label="{{ __('Linked until (optional)') }}">
                                </span>
                                <button type="submit" name="action" value="update" class="btn btn-default btn-sm">{{ __('Save') }}</button>
                                <button type="submit" name="action" value="remove" class="btn btn-link btn-sm" data-mfsorg-confirm="{{ __('Remove :email from :org?', ['email' => $link->email, 'org' => $org->name]) }} {{ trans_choice('{0} It has no past conversations here.|{1} Its 1 past conversation will no longer be linked to it.|[2,*] Its :n past conversations will no longer be linked to it.', $unlinkable[strtolower($link->email)] ?? 0, ['n' => $unlinkable[strtolower($link->email)] ?? 0]) }} {{ __('Conversations changed by hand stay as they are. New ones will not be linked automatically.') }}">{{ __('Remove') }}</button>
                            </form>
                        </td>
                    @else
                        <td>{{ $departments[$link->department_id] ?? '' }}</td>
                        <td>{{ $locations[$link->location_id] ?? '' }}</td>
                        <td>@if ($link->active) {{ __('Yes') }} @else {{ __('No') }} @endif
                            @if ($link->active_from || $link->active_to)<small class="text-help">{{ __('Linked') }} {{ $link->active_from ? $link->active_from->toDateString() : '…' }} – {{ $link->active_to ? $link->active_to->toDateString() : '…' }}</small>@endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="5" class="text-help">{{ __('No addresses yet. Add a domain under Details, or link addresses by hand.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
{{ $links->links() }}
@if (count($links))
    <p class="text-help">{{ __('Active and "Linked from / until" (both optional) decide when an address counts for this organisation. Outside that period, or when Active is off, new conversations from the address are not put under the organisation; its earlier conversations stay here (use this when someone leaves). Remove means the address doesn\'t belong here: its past conversations are unlinked too. Greyed-out rows are not in force today.') }}</p>
@endif

@if (count($excluded))
    <h4 class="margin-top">{{ __('Excluded') }}</h4>
    <p class="text-help">{{ __('These addresses match a domain of this organisation but were removed by hand, so they are not linked automatically.') }}</p>
    <ul class="mfsorg-excluded">
        @foreach ($excluded as $link)
            <li>{{ $link->email }}
                @if ($can_manage)
                    <form method="POST" action="{{ route('mfsorganisations.addresses', ['id' => $org->id]) }}" class="inline-form">
                        {{ csrf_field() }}
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="emails" value="{{ $link->email }}">
                        <button type="submit" class="btn btn-link btn-xs">{{ __('Link again') }}</button>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>
@endif
