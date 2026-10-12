@foreach($rows as $cols)
<div class="row">
    @foreach($cols as $col)
        @include($col['view'], ['col' => $col, 'field' => $col['field'] ?? null, 'width' => $col['width'], 'value' => isset($col['field']) ? ($values[$col['field']->key] ?? null) : null])
    @endforeach
</div>
@endforeach
