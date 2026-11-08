{{-- Organisation block in the conversation's right-hand panel (scope §4.4). --}}
<div class="conv-sidebar-block mfsorg-conv-block">
    <div class="mfsorg-conv-title">{{ __('Organisation') }}</div>
    @if ($org)
        <a href="{{ $org->searchUrl() }}" class="mfsorg-badge" title="{{ __('All conversations of :name', ['name' => $org->name]) }}" style="background:{{ $org->color }};color:{{ $org->textColor() }}">{{ $org->name }}</a>
        @if (!$org->active) <small class="text-help">({{ __('inactive') }})</small> @endif
        @if ($org->alert)<div class="mfsorg-alert">{{ $org->alert }}</div>@endif
        <ul class="mfsorg-conv-meta">
            <li><a href="{{ $org->url() }}" class="text-help">{{ __('Organisation page') }}</a></li>
            @if ($department)<li>{{ __('Department') }}: {{ $department->name }}</li>@endif
            @if ($location)<li>{{ __('Location') }}: {{ $location->path() }}</li>@endif
            @if ($rec && $rec->source == \Modules\MFSOrganisations\Entities\OrgConversation::SOURCE_AGENT)
                <li class="text-help">{{ __('Set by hand') }}</li>
            @endif
            <li>
                @if ($open)
                    <a href="{{ $org->url('conversations') }}&status=open">{{ trans_choice('{1} 1 other open conversation|[2,*] :count other open conversations', $open, ['count' => $open]) }}</a>
                @else
                    <span class="text-help">{{ __('No other open conversations') }}</span>
                @endif
            </li>
        </ul>
    @else
        <span class="text-help">{{ __('None') }}</span>
        @if ($create_domain)
            <div><a href="{{ route('mfsorganisations.create', ['domain' => $create_domain]) }}" class="mfsorg-create-link">{{ __('Create organisation for :domain', ['domain' => $create_domain]) }}</a></div>
        @endif
    @endif
    @if ($can_change)
        <a href="#" class="mfsorg-change-toggle text-help" data-toggle="collapse" data-target="#mfsorg-change-{{ $conversation->id }}">{{ __('Change') }}</a>
        <form id="mfsorg-change-{{ $conversation->id }}" method="POST" action="{{ route('mfsorganisations.conversation', ['id' => $conversation->id]) }}" class="collapse mfsorg-change-form">
            {{ csrf_field() }}
            <select name="organisation_id" class="form-control input-sm mfsorg-select">
                <option value="">{{ __('No organisation') }}</option>
                @foreach ($orgs as $o)
                    <option value="{{ $o->id }}" @if ($org && $org->id == $o->id) selected @endif>{{ $o->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-default btn-xs">{{ __('Save') }}</button>
            <p class="text-help">{{ __('Only this conversation. To change it for all conversations of an address, use the organisation\'s Addresses tab.') }}</p>
        </form>
    @endif
</div>
