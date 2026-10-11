@forelse($rows as $cols)
<div class="row">
    @foreach($cols as $col)
        @include($col['view'], ['col' => $col, 'field' => $col['field'] ?? null, 'width' => $col['width'], 'value' => isset($col['field']) ? ($values[$col['field']->key] ?? null) : null])
    @endforeach
</div>
@empty
<div class="text-muted mb-4">這種單據還沒有設定欄位，請到「單據建置 → 單據設定 → 設計表單」新增。</div>
@endforelse
