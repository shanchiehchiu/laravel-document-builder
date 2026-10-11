{{-- 單一欄位的輸入元件；表頭欄位與明細列共用。$name 為送出名稱，$inputId 給勾選框的 label 用，$compact 表示放在明細表格裡 --}}
@php($compact = $compact ?? false)
@switch($field->runtime_renderer)
    @case('field.textarea')
        <textarea name="{{ $name }}" rows="{{ $compact ? 1 : 3 }}" class="form-control" @if(!empty($eventKey)) data-event-key="{{ $eventKey }}" @endif>{{ $value }}</textarea>
        @break
    @case('field.number')
        <input type="number" step="any" name="{{ $name }}" class="form-control @if($compact) text-end @endif" value="{{ $value }}" @if(!empty($eventKey)) data-event-key="{{ $eventKey }}" @endif
            @if($compact && $field->sums()) data-sum="{{ $field->key }}" @endif>
        @break
    @case('field.date')
        <input type="date" name="{{ $name }}" class="form-control" value="{{ $value }}" @if(!empty($eventKey)) data-event-key="{{ $eventKey }}" @endif>
        @break
    @case('field.checkbox')
        {{-- 未勾選時送出 0，勾選時後面的 1 覆蓋前面的 0 --}}
        <input type="hidden" name="{{ $name }}" value="0">
        <div class="form-check form-switch @if($compact) d-flex justify-content-center @endif">
            <input class="form-check-input" type="checkbox" name="{{ $name }}" value="1" id="{{ $inputId }}" @if(!empty($eventKey)) data-event-key="{{ $eventKey }}" @endif @checked($value)>
            @unless($compact)<label class="form-check-label" for="{{ $inputId }}">是</label>@endunless
        </div>
        @break
    @case('field.select')
        {{-- 不走全域 select2 初始化（它沒有 placeholder、寬度依當下元素計算），由單據頁 initSelects() 處理 --}}
        <select name="{{ $name }}" class="js-choice form-select" data-no-select2 data-placeholder="請選擇" @if(!empty($eventKey)) data-event-key="{{ $eventKey }}" @endif>
            <option value=""></option>
            @foreach($field->choices() as $choice)
            <option value="{{ $choice }}" @selected($value === $choice)>{{ $choice }}</option>
            @endforeach
        </select>
        @break
    @case('field.master')
        <select name="{{ $name }}" class="js-select2 form-select" @if(!empty($eventKey)) data-event-key="{{ $eventKey }}" @endif
            data-url="{{ route(document_builder_route_name('document_builder.masters'), $field->masterSource()) }}"
            data-placeholder="搜尋{{ $field->label }}">
            <option></option>
            @if(is_array($value))
            <option value="{{ $value['id'] }}" selected>{{ $value['label'] }}</option>
            @endif
        </select>
        @break
    @default
        <input type="text" name="{{ $name }}" class="form-control" maxlength="255" value="{{ $value }}" @if(!empty($eventKey)) data-event-key="{{ $eventKey }}" @endif>
@endswitch
