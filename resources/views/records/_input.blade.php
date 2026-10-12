{{-- 單一欄位的輸入元件；表頭欄位與明細列共用。$name 為送出名稱，$inputId 給勾選框的 label 用，$compact 表示放在明細表格裡 --}}
{{-- $eventAttr 決定事件標記屬性：表頭用 data-event-key，明細列用 data-row-event-key，兩者互不干擾 --}}
@php($compact = $compact ?? false)
@php($eventAttr = $eventAttr ?? 'data-event-key')
@switch($field->runtime_renderer)
    @case('field.textarea')
        <textarea name="{{ $name }}" rows="{{ $compact ? 1 : 3 }}" class="form-control" @if(!empty($eventKey)) {{ $eventAttr }}="{{ $eventKey }}" @endif>{{ $value }}</textarea>
        @break
    @case('field.number')
        <input type="number" step="any" name="{{ $name }}" class="form-control @if($compact) text-end @endif" value="{{ $value }}" @if(!empty($eventKey)) {{ $eventAttr }}="{{ $eventKey }}" @endif
            @if($compact && $field->sums()) data-sum="{{ $field->key }}" @endif>
        @break
    @case('field.date')
        <input type="date" name="{{ $name }}" class="form-control" value="{{ $value }}" @if(!empty($eventKey)) {{ $eventAttr }}="{{ $eventKey }}" @endif>
        @break
    @case('field.checkbox')
        {{-- 未勾選時送出 0，勾選時後面的 1 覆蓋前面的 0 --}}
        <input type="hidden" name="{{ $name }}" value="0">
        <div class="form-check form-switch @if($compact) d-flex justify-content-center @endif">
            <input class="form-check-input" type="checkbox" name="{{ $name }}" value="1" id="{{ $inputId }}" @if(!empty($eventKey)) {{ $eventAttr }}="{{ $eventKey }}" @endif @checked($value)>
            @unless($compact)<label class="form-check-label" for="{{ $inputId }}">是</label>@endunless
        </div>
        @break
    @case('field.amount')
        {{-- 金額：送出的是隱藏欄位裡的純數字；畫面上的輸入框只負責顯示千分位與幣別，沒有 name，不會被送出 --}}
        @php($decimals = $field->decimals())
        @php($raw = ($value === null || $value === '') ? '' : number_format((float) $value, $decimals, '.', ''))
        <div class="input-group @if($compact) input-group-sm @endif" data-amount data-decimals="{{ $decimals }}">
            @if($field->currency() !== '')<span class="input-group-text">{{ $field->currency() }}</span>@endif
            <input type="text" inputmode="decimal" autocomplete="off" class="form-control text-end" data-amount-display
                value="{{ $raw === '' ? '' : number_format((float) $raw, $decimals) }}">
            <input type="hidden" name="{{ $name }}" value="{{ $raw }}" data-amount-raw @if(!empty($eventKey)) {{ $eventAttr }}="{{ $eventKey }}" @endif>
        </div>
        @break
    @case('field.radio')
        {{-- 未選時送出空字串（與勾選框同一套作法），確保欄位一定會送出；選了之後，後面的單選值會覆蓋前面的空值 --}}
        <input type="hidden" name="{{ $name }}" value="">
        <div class="d-flex flex-wrap gap-3 @if($field->layout() === 'vertical') flex-column @endif {{ $compact ? '' : 'pt-1' }}">
            @foreach($field->choices() as $index => $choice)
            <div class="form-check mb-0">
                <input class="form-check-input" type="radio" name="{{ $name }}" value="{{ $choice }}" id="{{ $inputId }}-{{ $index }}"
                    @if(!$field->required) data-clearable @endif
                    @if(!empty($eventKey)) {{ $eventAttr }}="{{ $eventKey }}" @endif @checked((string) $value === (string) $choice)>
                <label class="form-check-label" for="{{ $inputId }}-{{ $index }}">{{ $choice }}</label>
            </div>
            @endforeach
        </div>
        @break
    @case('field.checkgroup')
        {{-- 複選：name 加上 [] 送出陣列；沒勾任何一項時瀏覽器不送出此欄位，儲存時轉為空陣列 --}}
        @php($selected = array_map('strval', (array) ($value ?? [])))
        <div class="d-flex flex-wrap gap-3 @if($field->layout() === 'vertical') flex-column @endif {{ $compact ? '' : 'pt-1' }}">
            @foreach($field->choices() as $index => $choice)
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="{{ $name }}[]" value="{{ $choice }}" id="{{ $inputId }}-{{ $index }}"
                    @if(!empty($eventKey)) {{ $eventAttr }}="{{ $eventKey }}" @endif @checked(in_array((string) $choice, $selected, true))>
                <label class="form-check-label" for="{{ $inputId }}-{{ $index }}">{{ $choice }}</label>
            </div>
            @endforeach
        </div>
        @break
    @case('field.multiselect')
        {{-- 多選下拉：由單據頁 initSelects() 套上 select2 多選；送出名稱加 []，沒選任何一項時不送出，儲存時轉為空陣列 --}}
        @php($selected = array_map('strval', (array) ($value ?? [])))
        <select name="{{ $name }}[]" multiple class="js-choice-multi form-select" data-no-select2 data-placeholder="輸入關鍵字搜尋…" @if(!empty($eventKey)) {{ $eventAttr }}="{{ $eventKey }}" @endif>
            @foreach($field->choices() as $choice)
            <option value="{{ $choice }}" @selected(in_array((string) $choice, $selected, true))>{{ $choice }}</option>
            @endforeach
        </select>
        @break
    @case('field.select')
        {{-- 不走全域 select2 初始化（它沒有 placeholder、寬度依當下元素計算），由單據頁 initSelects() 處理 --}}
        <select name="{{ $name }}" class="js-choice form-select" data-no-select2 data-placeholder="請選擇" @if(!empty($eventKey)) {{ $eventAttr }}="{{ $eventKey }}" @endif>
            <option value=""></option>
            @foreach($field->choices() as $choice)
            <option value="{{ $choice }}" @selected($value === $choice)>{{ $choice }}</option>
            @endforeach
        </select>
        @break
    @case('field.master')
        @php($extraKeys = app(\DocumentBuilder\Services\MasterSourceRegistry::class)->extraKeys((string) $field->masterSource()))
        <select name="{{ $name }}" class="js-select2 form-select" @if(!empty($eventKey)) {{ $eventAttr }}="{{ $eventKey }}" @endif
            @if($extraKeys !== []) data-extra-keys="{{ json_encode($extraKeys, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}" @endif
            data-url="{{ route(document_builder_route_name('document_builder.masters'), $field->masterSource()) }}"
            data-placeholder="搜尋{{ $field->label }}">
            <option></option>
            @if(is_array($value))
            <option value="{{ $value['id'] }}" selected>{{ $value['label'] }}</option>
            @endif
        </select>
        @break
    @default
        <input type="text" name="{{ $name }}" class="form-control" maxlength="255" value="{{ $value }}" @if(!empty($eventKey)) {{ $eventAttr }}="{{ $eventKey }}" @endif>
@endswitch
