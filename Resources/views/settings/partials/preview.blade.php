{{-- Backfill preview (scope §5): nothing is changed until "Link now". --}}
<table class="table table-condensed mfsorg-preview-table">
    <tr><td>{{ __('Addresses in FreeScout') }}</td><td class="text-right">{{ $p['total'] }}</td></tr>
    <tr><td>{{ __('Already linked') }}</td><td class="text-right">{{ $p['linked'] }}</td></tr>
    <tr class="success"><td><strong>{{ __('Will be linked now') }}</strong> {{ trans_choice('(to 1 organisation)|(to :count organisations)', count($p['per_org']), ['count' => count($p['per_org'])]) }}</td><td class="text-right"><strong>{{ $p['match'] }}</strong></td></tr>
    <tr><td>{{ __('Free-mail (link by hand)') }}</td><td class="text-right">{{ $p['freemail'] }}</td></tr>
    <tr><td>{{ __('Your own mailbox domains') }}</td><td class="text-right">{{ $p['own'] }}</td></tr>
    <tr><td>{{ __('Excluded by hand') }}</td><td class="text-right">{{ $p['excluded'] }}</td></tr>
    <tr><td>{{ __('No organisation has this domain') }}</td><td class="text-right">{{ $p['nomatch'] }}</td></tr>
    <tr><td>{{ __('Conversations without an organisation') }}</td><td class="text-right">{{ $p['conversations_without'] }}</td></tr>
</table>

@if ($p['per_org'])
    <p><strong>{{ __('Per organisation') }}</strong></p>
    <ul class="mfsorg-preview-list">
        @foreach ($p['per_org'] as $org_id => $n)
            <li><a href="{{ route('mfsorganisations.show', ['id' => $org_id]) }}">{{ $p['org_names'][$org_id] ?? $org_id }}</a>: {{ $n }}</li>
        @endforeach
    </ul>
@endif

@if ($p['unmatched_domains'])
    <p><strong>{{ __('Most common domains without an organisation') }}</strong> <span class="text-help">{{ __('(create an organisation for a domain to include it; nothing is created automatically)') }}</span></p>
    <ul class="mfsorg-preview-list">
        @foreach ($p['unmatched_domains'] as $domain => $n)
            <li>{{ $domain }}: {{ $n }} <a href="{{ route('mfsorganisations.create', ['domain' => $domain]) }}" class="text-help">{{ __('create organisation') }}</a></li>
        @endforeach
    </ul>
@endif

<button type="button" class="btn btn-primary mfsorg-start-btn" @if (!$p['match'] && !$p['conversations_without']) disabled @endif>{{ __('Link now') }}</button>
