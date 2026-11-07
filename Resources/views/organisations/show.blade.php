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
        <li><i class="glyphicon glyphicon-arrow-left"></i> <a href="{{ route('mfsorganisations.index') }}">{{ __('All organisations') }}</a></li>
    </ul>
@endsection

@section('content')
    <div class="section-heading">
        {{ $org->name }} <small class="text-help">{{ $org->typeName() }}</small>
    </div>

    @include('partials/flash_messages')

    <div class="container form-container mfsorg-page">
        @include('mfsorganisations::organisations.tab_'.$tab)
    </div>
@endsection
