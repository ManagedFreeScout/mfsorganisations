@extends('layouts.app')

@section('title', __('Organisations'))

@section('content')
<div class="container mfsorg-page">
    <div class="flexy-container">
        <div class="flexy-item">
            <span class="heading">{{ __('Organisations') }} <small>({{ $orgs->total() }})</small></span>
        </div>
        @if ($can_manage)
            <div class="flexy-item margin-left">
                <a href="{{ route('mfsorganisations.create') }}" class="btn btn-bordered">{{ __('New organisation') }}</a>
                <a href="#" class="btn btn-link" data-toggle="collapse" data-target="#mfsorg-import">{{ __('Import CSV') }}</a>
            </div>
        @endif
        <div class="flexy-block"></div>
    </div>

    @include('partials/flash_messages')

    @if ($can_manage)
        <div id="mfsorg-import" class="collapse well margin-top">
            <form method="POST" action="{{ route('mfsorganisations.import') }}" enctype="multipart/form-data" class="form-inline">
                {{ csrf_field() }}
                <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required>
                <button type="submit" class="btn btn-primary">{{ __('Import') }}</button>
                <p class="help-block">{{ __('First line: column names. Columns: name (required), type, domains, coc_number, vat_number, country, phone, website. Several domains: separate them with spaces or ";". An existing name updates that organisation.') }}</p>
            </form>
        </div>
    @endif

    <form method="GET" action="{{ route('mfsorganisations.index') }}" class="form-inline margin-top mfsorg-filters">
        <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="{{ __('Name or domain') }}">
        <select name="type" class="form-control">
            <option value="">{{ __('All types') }}</option>
            @foreach (\Modules\MFSOrganisations\Entities\Organisation::TYPES as $key => $label)
                <option value="{{ $key }}" @if (request('type') == $key) selected @endif>{{ __($label) }}</option>
            @endforeach
        </select>
        <select name="status" class="form-control">
            <option value="active" @if ($status == 'active') selected @endif>{{ __('Active') }}</option>
            <option value="inactive" @if ($status == 'inactive') selected @endif>{{ __('Inactive') }}</option>
            <option value="all" @if ($status == 'all') selected @endif>{{ __('Active and inactive') }}</option>
        </select>
        @if (count($mailboxes) > 1)
            <select name="mailbox" class="form-control">
                <option value="">{{ __('All mailboxes') }}</option>
                @foreach ($mailboxes as $mailbox)
                    <option value="{{ $mailbox->id }}" @if (request('mailbox') == $mailbox->id) selected @endif>{{ $mailbox->name }}</option>
                @endforeach
            </select>
        @endif
        <button type="submit" class="btn btn-default">{{ __('Filter') }}</button>
    </form>

    @php
        $sortLink = function ($key, $label) use ($sort, $dir) {
            $newDir = ($sort == $key && $dir == 'asc') ? 'desc' : 'asc';
            $arrow = $sort == $key ? ($dir == 'asc' ? ' ▲' : ' ▼') : '';
            return '<a href="'.e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => $newDir, 'page' => null])).'">'.e($label).$arrow.'</a>';
        };
    @endphp

    <div class="table-responsive margin-top">
        <table class="table table-striped mfsorg-table">
            <thead>
                <tr>
                    <th>{!! $sortLink('name', __('Name')) !!}</th>
                    <th>{!! $sortLink('type', __('Type')) !!}</th>
                    <th>{{ __('Domains') }}</th>
                    <th class="text-right">{!! $sortLink('addresses', __('Addresses')) !!}</th>
                    <th class="text-right">{!! $sortLink('open', __('Open')) !!}</th>
                    <th class="text-right">{!! $sortLink('total', __('Conversations')) !!}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orgs as $org)
                    <tr @if (!$org->active) class="text-muted" @endif>
                        <td>
                            <a href="{{ $org->url() }}" class="mfsorg-badge" style="background:{{ $org->color }};color:{{ $org->textColor() }}">{{ $org->name }}</a>
                            @if (!$org->active) <small>({{ __('inactive') }})</small> @endif
                        </td>
                        <td>{{ $org->typeName() }}</td>
                        <td class="mfsorg-domains">{{ $org->domain_list }}</td>
                        <td class="text-right">{{ $org->addresses_count }}</td>
                        <td class="text-right">@if ($org->open_count)<a href="{{ $org->url('conversations') }}&status=open">{{ $org->open_count }}</a>@else 0 @endif</td>
                        <td class="text-right"><a href="{{ $org->url('conversations') }}">{{ $org->conversations_count }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-help">
                        @if ($q !== '' || request('type') || request('mailbox') || $status != 'active')
                            {{ __('No organisations match these filters.') }}
                        @else
                            {{ __('No organisations yet.') }}
                            @if ($can_manage) <a href="{{ route('mfsorganisations.create') }}">{{ __('Create the first one') }}</a>. @endif
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $orgs->links() }}
</div>
@endsection
