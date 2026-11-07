@php
    $mfsorgHideBadge = array_map('intval', (array) \Option::get(\Modules\MFSOrganisations\Providers\MFSOrganisationsServiceProvider::OPTION_HIDE_BADGE, []));
    $mfsorgHidePanel = array_map('intval', (array) \Option::get(\Modules\MFSOrganisations\Providers\MFSOrganisationsServiceProvider::OPTION_HIDE_PANEL, []));
    $mfsorgFreemail = \Option::get(\Modules\MFSOrganisations\Services\Linker::OPTION_FREEMAIL, '') ?: implode("\n", config('mfsorganisations.freemail', []));
    $mfsorgState = \Modules\MFSOrganisations\Services\Backfill::state();
@endphp

<div class="row">
    <div class="col-xs-12">
        <div class="alert alert-info">
            <strong>{{ __('MFS Organisations') }}</strong> &mdash; {{ __('Group customers into organisations: e-mail addresses are linked by an organisation\'s domains or by hand, and every conversation shows its organisation.') }}
            <a href="{{ route('mfsorganisations.index') }}">{{ __('Go to the organisations') }}</a>
        </div>
    </div>
</div>

@if (config('mfsorganisations.enforce_license'))
    @include('mfsorganisations::settings.partials.license')
@endif

<form class="form-horizontal" method="POST" action="{{ route('mfsorganisations.settings.save') }}">
    {{ csrf_field() }}
    <div class="row">
        <div class="col-xs-12">
            <div class="panel panel-default">
                <div class="panel-heading">{{ __('Free-mail domains') }}</div>
                <div class="panel-body">
                    <p class="text-help">{{ __('Addresses at these domains are never linked to an organisation by domain: one gmail.com address says nothing about where someone works. You can still link them by hand. Your own mailbox domains are always left out.') }}</p>
                    <textarea name="freemail" class="form-control" rows="8">{{ $mfsorgFreemail }}</textarea>
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading">{{ __('Display per mailbox') }}</div>
                <div class="panel-body">
                    <p class="text-help">{{ __('For example an internal IT mailbox where everyone is from the same organisation: hide the badge there.') }}</p>
                    <table class="table table-condensed">
                        <thead><tr><th>{{ __('Mailbox') }}</th><th>{{ __('Hide badge in the list') }}</th><th>{{ __('Hide block on the conversation') }}</th></tr></thead>
                        <tbody>
                            @foreach (\App\Mailbox::orderBy('name')->get() as $mailbox)
                                <tr>
                                    <td>{{ $mailbox->name }}</td>
                                    <td><input type="checkbox" name="hide_badge[]" value="{{ $mailbox->id }}" @if (in_array($mailbox->id, $mfsorgHideBadge)) checked @endif></td>
                                    <td><input type="checkbox" name="hide_panel[]" value="{{ $mailbox->id }}" @if (in_array($mailbox->id, $mfsorgHidePanel)) checked @endif></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    </div>
</form>

<div class="row">
    <div class="col-xs-12">
        <div class="panel panel-default" id="mfsorg-backfill"
             data-preview-url="{{ route('mfsorganisations.backfill.preview') }}"
             data-start-url="{{ route('mfsorganisations.backfill.start') }}"
             data-step-url="{{ route('mfsorganisations.backfill.step') }}"
             data-running="{{ ($mfsorgState['status'] ?? '') == 'running' ? 1 : 0 }}">
            <div class="panel-heading">{{ __('Link existing addresses (backfill)') }}</div>
            <div class="panel-body">
                <p class="text-help">{{ __('Links the addresses already in FreeScout to the organisations you created, by their domains, and gives existing conversations their organisation. It never creates organisations. New addresses and conversations are linked automatically; run this after creating organisations or importing them.') }}</p>
                <button type="button" class="btn btn-default mfsorg-preview-btn">{{ __('Preview') }}</button>
                <div class="mfsorg-preview margin-top"></div>
                <div class="mfsorg-progress margin-top" @if (($mfsorgState['status'] ?? '') != 'running') style="display:none" @endif>
                    <div class="progress"><div class="progress-bar" role="progressbar" style="width: {{ \Modules\MFSOrganisations\Services\Backfill::percent($mfsorgState) }}%"></div></div>
                    <div class="mfsorg-progress-text text-help"></div>
                </div>
                @if (($mfsorgState['status'] ?? '') == 'done')
                    <p class="text-help margin-top">{{ __('Last run: :when, :a addresses linked, :c conversations.', ['when' => $mfsorgState['finished_at'] ?? '', 'a' => $mfsorgState['linked'] ?? 0, 'c' => $mfsorgState['recorded'] ?? 0]) }}</p>
                @endif
            </div>
        </div>
    </div>
</div>
