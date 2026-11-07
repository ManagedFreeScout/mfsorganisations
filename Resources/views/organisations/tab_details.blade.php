<div class="row">
    <div class="col-xs-12">
        <form class="form-horizontal margin-top" method="POST" action="{{ route('mfsorganisations.update', ['id' => $org->id]) }}">
            {{ csrf_field() }}
            @include('mfsorganisations::organisations.form', ['editable' => $can_manage])
            @if ($can_manage)
                <div class="form-group">
                    <div class="col-sm-6 col-sm-offset-2">
                        <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                    </div>
                </div>
            @endif
        </form>

        @if ($is_admin)
            <hr>
            <div class="form-horizontal">
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Merge') }}</label>
                    <div class="col-sm-6">
                        <form method="POST" action="{{ route('mfsorganisations.merge', ['id' => $org->id]) }}" class="form-inline"
                              data-mfsorg-confirm="{{ __('Merge :org into the chosen organisation? Its addresses, conversations, domains and departments move over, and :org is deleted. This cannot be undone.', ['org' => $org->name]) }}">
                            {{ csrf_field() }}
                            <select name="target_id" class="form-control mfsorg-select" required>
                                <option value="">{{ __('Merge into…') }}</option>
                                @foreach ($merge_targets as $target)
                                    <option value="{{ $target->id }}">{{ $target->name }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-default">{{ __('Merge') }}</button>
                        </form>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Delete') }}</label>
                    <div class="col-sm-6">
                        @if ($counts['addresses'] || $counts['conversations'])
                            <p class="form-control-static text-help">{{ __('Only an organisation without addresses and conversations can be deleted. Deactivate it instead, or merge it into another one.') }}</p>
                        @else
                            <form method="POST" action="{{ route('mfsorganisations.delete', ['id' => $org->id]) }}"
                                  data-mfsorg-confirm="{{ __('Delete :org?', ['org' => $org->name]) }}">
                                {{ csrf_field() }}
                                <button type="submit" class="btn btn-danger">{{ __('Delete organisation') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
