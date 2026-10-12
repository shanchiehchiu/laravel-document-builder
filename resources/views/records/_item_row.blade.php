{{-- 明細表的一列；$index 為列索引（範本列為 __INDEX__），$item 為既有明細（新列為 null） --}}
@php($prefix = "items[{$tableKey}][{$index}]")
<tr class="doc-item-row">
    <td class="text-center text-muted doc-item-no">{{ is_int($index) ? $index + 1 : '' }}</td>
    @foreach($fields as $field)
    <td>
        @include('document-builder::records._input', [
            'name' => "{$prefix}[{$field->key}]",
            'inputId' => "item-{$tableKey}-{$index}-{$field->key}",
            'value' => $item?->value($field->key),
            'compact' => true,
            // 明細列內的欄位用獨立的標記屬性，列內連動只在同一列內查找，不會碰到表頭或其他列
            'eventKey' => $field->key,
            'eventAttr' => 'data-row-event-key',
        ])
    </td>
    @endforeach
    @unless($show)
    <td class="text-center">
        <input type="hidden" name="{{ $prefix }}[_id]" value="{{ $item?->id }}">
        <button type="button" class="btn btn-sm btn-outline-danger doc-item-remove" title="{{ __('document-builder::ui.remove_row') }}" aria-label="{{ __('document-builder::ui.remove_row') }}">×</button>
    </td>
    @endunless
</tr>
