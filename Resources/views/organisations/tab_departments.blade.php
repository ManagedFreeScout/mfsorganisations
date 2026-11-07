@if ($can_manage)
    <form method="POST" action="{{ route('mfsorganisations.departments', ['id' => $org->id]) }}" class="form-inline margin-top">
        {{ csrf_field() }}
        <input type="hidden" name="action" value="add">
        <input type="text" name="name" class="form-control" placeholder="{{ __('New department') }}" maxlength="191" required>
        <select name="parent_id" class="form-control">
            <option value="">{{ __('Top level') }}</option>
            @foreach ($departments as $dept_id => $dept_name)
                <option value="{{ $dept_id }}">{{ __('Under') }} {{ $dept_name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-default">{{ __('Add') }}</button>
    </form>
@endif

<div class="table-responsive margin-top">
    <table class="table table-striped mfsorg-table">
        <tbody>
            @forelse ($departments as $dept_id => $dept_name)
                @php
                    $parts = explode(' › ', $dept_name);
                    $own = array_pop($parts);
                    $dept = \Modules\MFSOrganisations\Entities\Department::find($dept_id);
                @endphp
                <tr>
                    <td style="padding-left: {{ 8 + 20 * count($parts) }}px">
                        @if ($can_manage)
                            <form method="POST" action="{{ route('mfsorganisations.departments', ['id' => $org->id]) }}" class="form-inline mfsorg-dept-form">
                                {{ csrf_field() }}
                                <input type="hidden" name="department_id" value="{{ $dept_id }}">
                                <input type="text" name="name" value="{{ $own }}" class="form-control input-sm" maxlength="191" required>
                                <select name="parent_id" class="form-control input-sm">
                                    <option value="">{{ __('Top level') }}</option>
                                    @foreach ($departments as $other_id => $other_name)
                                        @if ($other_id != $dept_id)
                                            <option value="{{ $other_id }}" @if ($dept && $dept->parent_id == $other_id) selected @endif>{{ __('Under') }} {{ $other_name }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <button type="submit" name="action" value="update" class="btn btn-default btn-sm">{{ __('Save') }}</button>
                                <button type="submit" name="action" value="delete" class="btn btn-link btn-sm" data-mfsorg-confirm="{{ __('Delete department :name? Its addresses keep their organisation; sub-departments move up one level.', ['name' => $own]) }}">{{ __('Delete') }}</button>
                            </form>
                        @else
                            {{ $own }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="text-help">{{ __('No departments. They are optional: use them when an organisation has teams or locations you want to tell apart.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
