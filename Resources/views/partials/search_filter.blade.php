<div class="col-sm-6 form-group @if (isset($filters['organisation'])) active @endif" data-filter="organisation">
    <label>{{ __('Organisation') }} <b class="remove" data-toggle="tooltip" title="{{ __('Remove filter') }}">×</b></label>
    <select name="f[organisation]" class="form-control mfsorg-select" @if (empty($filters['organisation'])) disabled @endif>
        <option value=""></option>
        @foreach ($orgs as $org)
            <option value="{{ $org->id }}" @if (!empty($filters['organisation']) && $filters['organisation'] == $org->id) selected @endif>{{ $org->name }}</option>
        @endforeach
    </select>
</div>
