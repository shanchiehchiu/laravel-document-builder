{{--
    明細表：表頭一列欄位名稱、每列一筆明細、可增刪列。
    新列由 <template> 複製（template 內容不在 DOM 裡，不會被送出、也不會被全域 select2 初始化）；
    合計在檢視頁由後端依存檔資料算，填單時由下方 JS 即時重算。
--}}
@php
    $tableKey = $col['key'];
    $fields = $col['fields'];
    $list = $items->get($tableKey, collect())->values();
    // 新增時先給一列空白列，直接就能填；全空的列存檔時會略過
    $startRows = $list->isEmpty() && $record === null ? 1 : 0;
    $sumFields = $fields->filter->sums();
@endphp
<div class="col-12 mb-4 doc-items" data-table="{{ $tableKey }}" data-next="{{ $list->count() + $startRows }}">
    <h4 class="fs-5 fw-semibold border-bottom pb-2 mb-3">{{ $col['title'] }}</h4>
    @if($fields->isEmpty())
    <div class="text-muted">這個明細表還沒有欄位，請到單據建置的設計器把欄位拖進明細表。</div>
    @else
    <div class="table-responsive">
        <table class="table table-bordered table-vcenter table-sm mb-2">
            <thead>
                <tr>
                    <th class="text-center" style="width: 3rem; min-width: 3rem">#</th>
                    @foreach($fields as $field)
                    <th @class(['text-end' => $field->type === 'number', 'text-center' => $field->type === 'checkbox'])
                        style="min-width: {{ in_array($field->type, ['master', 'textarea'], true) ? '12rem' : '7rem' }}">
                        {{ $field->label }}
                        @if($field->required && $field->type !== 'checkbox')<span class="text-danger">*</span>@endif
                    </th>
                    @endforeach
                    @unless($show)<th style="width: 3.5rem; min-width: 3.5rem"></th>@endunless
                </tr>
            </thead>
            <tbody>
                @foreach($list as $index => $item)
                    @include('document-builder::records._item_row', ['index' => $index, 'item' => $item])
                @endforeach
                @for($i = 0; $i < $startRows; $i++)
                    @include('document-builder::records._item_row', ['index' => $i, 'item' => null])
                @endfor
                @if($show && $list->isEmpty())
                <tr><td colspan="{{ $fields->count() + 1 }}" class="text-center text-muted">沒有明細</td></tr>
                @endif
            </tbody>
            @if($sumFields->isNotEmpty())
            <tfoot>
                <tr class="fw-semibold">
                    <td class="text-center">合計</td>
                    @foreach($fields as $field)
                    <td class="text-end" @if($field->sums()) data-sum-total="{{ $field->key }}" data-decimals="{{ $field->decimals() }}" @endif>
                        @if($field->sums())
                        {{ number_format($list->sum(fn ($item) => (float) $item->value($field->key)), $field->decimals()) }}
                        @endif
                    </td>
                    @endforeach
                    @unless($show)<td></td>@endunless
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
    @unless($show)
    <button type="button" class="btn btn-sm btn-outline-primary doc-item-add">＋ {{ __('document-builder::ui.add_row') }}</button>
    <template class="doc-item-template">
        @include('document-builder::records._item_row', ['index' => '__INDEX__', 'item' => null])
    </template>
    @endunless
    @endif
</div>
