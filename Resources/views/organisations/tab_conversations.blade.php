<form method="GET" action="{{ $org->url() }}" class="form-inline margin-top mfsorg-filters">
    <input type="hidden" name="tab" value="conversations">
    @if (count($mailboxes) > 1)
        <select name="mailbox" class="form-control">
            <option value="">{{ __('All mailboxes') }}</option>
            @foreach ($mailboxes as $mailbox)
                <option value="{{ $mailbox->id }}" @if (request('mailbox') == $mailbox->id) selected @endif>{{ $mailbox->name }}</option>
            @endforeach
        </select>
    @endif
    <select name="status" class="form-control">
        <option value="">{{ __('All statuses') }}</option>
        <option value="open" @if (request('status') == 'open') selected @endif>{{ __('Active and pending') }}</option>
        <option value="{{ \App\Conversation::STATUS_ACTIVE }}" @if (request('status') == \App\Conversation::STATUS_ACTIVE) selected @endif>{{ __('Active') }}</option>
        <option value="{{ \App\Conversation::STATUS_PENDING }}" @if (request('status') == \App\Conversation::STATUS_PENDING) selected @endif>{{ __('Pending') }}</option>
        <option value="{{ \App\Conversation::STATUS_CLOSED }}" @if (request('status') == \App\Conversation::STATUS_CLOSED) selected @endif>{{ __('Closed') }}</option>
    </select>
    <button type="submit" class="btn btn-default">{{ __('Filter') }}</button>
    <a href="{{ route('mfsorganisations.export', array_merge(['id' => $org->id], request()->only('mailbox', 'status'))) }}" class="btn btn-link"><i class="glyphicon glyphicon-download-alt"></i> {{ __('Export CSV') }}</a>
</form>

<div class="table-responsive margin-top">
    <table class="table table-striped mfsorg-table">
        <thead>
            <tr>
                <th>#</th>
                <th>{{ __('Subject') }}</th>
                <th>{{ __('Customer') }}</th>
                <th>{{ __('Mailbox') }}</th>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Last reply') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($conversations as $conversation)
                <tr>
                    <td><a href="{{ $conversation->url() }}">{{ $conversation->number }}</a></td>
                    <td><a href="{{ $conversation->url() }}">{{ $conversation->getSubject() }}</a></td>
                    <td>{{ $conversation->customer ? $conversation->customer->getFullName(true) : $conversation->customer_email }}</td>
                    <td>{{ $conversation->mailbox ? $conversation->mailbox->name : '' }}</td>
                    <td>{{ $conversation->getStatusName() }}</td>
                    <td>{{ $conversation->last_reply_at ? \App\User::dateDiffForHumans($conversation->last_reply_at) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-help">{{ __('No conversations in your mailboxes.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
{{ $conversations->links() }}
