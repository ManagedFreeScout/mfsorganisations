@extends('layouts.app')

@section('title', __('New organisation'))

@section('content')
    <div class="section-heading">
        {{ __('New organisation') }}
    </div>

    @include('partials/flash_messages')

    <div class="container form-container">
        <div class="row">
            <div class="col-xs-12">
                <form class="form-horizontal margin-top" method="POST" action="{{ route('mfsorganisations.store') }}">
                    {{ csrf_field() }}
                    @include('mfsorganisations::organisations.form', ['mailbox_ids' => [], 'editable' => true])
                    <div class="form-group">
                        <div class="col-sm-6 col-sm-offset-2">
                            <button type="submit" class="btn btn-primary">{{ __('Create') }}</button>
                            <a href="{{ route('mfsorganisations.index') }}" class="btn btn-link">{{ __('Cancel') }}</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
