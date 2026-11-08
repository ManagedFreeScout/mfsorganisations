@extends('layouts.app')

@section('title_full', $org->name.' - '.__('Organisations'))

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    <div class="sidebar-title mfsorg-sidebar-title">
        <span class="mfsorg-badge" style="background:{{ $org->color }};color:{{ $org->textColor() }}">{{ $org->name }}</span>
        @if (!$org->active)<div class="text-help">{{ __('Inactive') }}</div>@endif
        @if ($org->alert)<div class="mfsorg-alert">{{ $org->alert }}</div>@endif
    </div>
    <ul class="sidebar-menu">
        <li @if ($tab == 'details') class="active" @endif><i class="glyphicon glyphicon-briefcase"></i> <a href="{{ $org->url() }}">{{ __('Details') }}</a></li>
        <li @if ($tab == 'addresses') class="active" @endif><i class="glyphicon glyphicon-envelope"></i> <a href="{{ $org->url('addresses') }}">{{ __('Addresses') }} <small>({{ $counts['addresses'] }})</small></a></li>
        <li @if ($tab == 'conversations') class="active" @endif><i class="glyphicon glyphicon-comment"></i> <a href="{{ $org->url('conversations') }}">{{ __('Conversations') }} <small>({{ $counts['conversations'] }})</small></a></li>
        <li @if ($tab == 'departments') class="active" @endif><i class="glyphicon glyphicon-tree-conifer"></i> <a href="{{ $org->url('departments') }}">{{ __('Departments') }} <small>({{ count($departments) }})</small></a></li>
        <li @if ($tab == 'locations') class="active" @endif><i class="glyphicon glyphicon-map-marker"></i> <a href="{{ $org->url('locations') }}">{{ __('Locations') }} <small>({{ count($locations) }})</small></a></li>
        @foreach ($extra_tabs as $extra_key => $extra)
            <li @if ($tab == $extra_key) class="active" @endif><i class="glyphicon glyphicon-{{ $extra['icon'] ?? 'th-list' }}"></i> <a href="{{ $org->url($extra_key) }}">{{ $extra['title'] }}@if (isset($extra['count'])) <small>({{ $extra['count'] }})</small>@endif</a></li>
        @endforeach
        <li><i class="glyphicon glyphicon-arrow-left"></i> <a href="{{ route('mfsorganisations.index') }}">{{ __('All organisations') }}</a></li>
    </ul>
@endsection

@section('content')
    <div class="section-heading">
        {{ $org->name }} <small class="text-help">{{ $org->typeName() }}</small>
    </div>

    @include('partials/flash_messages')

    <div class="container form-container mfsorg-page">
        @if (isset($extra_tabs[$tab]))
            @action('mfsorganisations.org_tab', $tab, $org)
        @else
            @include('mfsorganisations::organisations.tab_'.$tab)
        @endif
    </div>
@endsection
