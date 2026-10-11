@extends(config('document_builder.layout'))

@php
    $settings = (array) $source->settings;
    $locked = $usage > 0;
    $routeName = fn (string $name): string => document_builder_route_name('document_builder.sources.'.$name);
@endphp

@section('content')
<div class="block block-rounded">
    <div class="block-header block-header-default">
        <h3 class="block-title">{{ $source->exists ? '修改來源：'.$source->label : '新增來源' }}</h3>
    </div>
    <div class="block-content block-content-full">
        @if($locked)
            <div class="alert alert-info small">已有 {{ $usage }} 個欄位使用這個來源，不得換類型或資料來源，以防舊單據對到別的資料。顯示欄位與條件仍可以調整；不再使用這個來源時，改成停用。</div>
        @endif

        <form id="form-source" data-method="{{ $source->exists ? 'PUT' : 'POST' }}"
              action="{{ $source->exists ? route($routeName('update'), $source) : route($routeName('store')) }}">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">名稱<span class="text-danger">*</span></label>
                    <input type="text" name="label" class="form-control" maxlength="50" value="{{ $source->label }}" placeholder="例如：專案">
                    <div class="form-text">設計表單時顯示在「主檔下拉」裡的名稱</div>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">來源代碼<span class="text-danger">*</span></label>
                    <input type="text" name="code" class="form-control" maxlength="50" value="{{ $source->code }}" placeholder="例如：project" @disabled($source->exists)>
                    <div class="form-text">小寫英文、數字、底線，建立後不得修改</div>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">{{ __('document-builder::ui.status') }}</label>
                    <select name="status" class="form-select">
                        <option value="1" @selected($source->status)>{{ __('document-builder::ui.active') }}</option>
                        <option value="0" @selected(! $source->status)>{{ __('document-builder::ui.inactive') }}</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">類型<span class="text-danger">*</span></label>
                    <select name="driver" class="form-select" @disabled($locked)>
                        @foreach($drivers as $key => $label)
                            <option value="{{ $key }}" @selected($source->driver === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- 資料模型：宿主專案的資料表 --}}
            <fieldset class="js-driver border rounded p-3 mb-3" data-driver="model">
                <legend class="fs-6 fw-semibold float-none w-auto px-2 mb-0">資料模型</legend>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">資料模型<span class="text-danger">*</span></label>
                        <select name="settings[model]" class="form-select" data-value="{{ $settings['model'] ?? '' }}" @disabled($locked)>
                            <option value="">請選擇</option>
                            @foreach($models as $class => $name)
                                <option value="{{ $class }}" @selected(($settings['model'] ?? null) === $class)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">只列出專案裡的 Eloquent model；密碼這類隱藏欄位不會出現</div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">代碼欄位<span class="text-danger">*</span></label>
                        <select name="settings[code]" class="form-select js-column" data-value="{{ $settings['code'] ?? '' }}"></select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">名稱欄位<span class="text-danger">*</span></label>
                        <select name="settings[text]" class="form-select js-column" data-value="{{ $settings['text'] ?? '' }}"></select>
                    </div>
                </div>
                <div class="row">
                    @foreach(['where' => ['身分條件', '搜尋與存檔都套用，例如「類型 = 客戶」，客戶欄位就不得存廠商'], 'active' => ['搜尋條件', '只在搜尋時套用，例如「狀態 = 1」，停用的資料不出現在下拉，但舊單據引用到的資料仍可以存檔']] as $group => [$title, $hint])
                        <div class="col-md-6 mb-2 js-conditions" data-group="{{ $group }}" data-value="{{ json_encode((object) ($settings[$group] ?? []), JSON_UNESCAPED_UNICODE) }}">
                            <label class="form-label mb-1">{{ $title }}</label>
                            <div class="form-text mt-0 mb-2">{{ $hint }}</div>
                            <div class="js-condition-rows"></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary js-add-condition">＋ 加條件</button>
                        </div>
                    @endforeach
                </div>
            </fieldset>

            {{-- 單據：單據工廠自己建立的單據 --}}
            <fieldset class="js-driver border rounded p-3 mb-3" data-driver="document">
                <legend class="fs-6 fw-semibold float-none w-auto px-2 mb-0">單據</legend>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">單據<span class="text-danger">*</span></label>
                        <select name="settings[doc_type]" class="form-select" @disabled($locked)>
                            <option value="">請選擇</option>
                            @foreach($types as $type)
                                <option value="{{ $type->code }}" @selected(($settings['doc_type'] ?? null) === $type->code)>{{ $type->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">顯示欄位</label>
                        <select name="settings[text_field]" class="form-select" data-value="{{ $settings['text_field'] ?? '' }}"></select>
                        <div class="form-text">下拉顯示為「單號＋這個欄位」，搜尋也會比對它</div>
                    </div>
                </div>
            </fieldset>

            {{-- 預覽：用目前的設定試搜尋 --}}
            <div class="border rounded p-3 mb-3 bg-light">
                <div class="d-flex gap-2 align-items-center">
                    <input type="text" class="form-control form-control-sm js-preview-term" placeholder="輸入關鍵字試搜尋（留空列出前 10 筆）" style="max-width: 22rem">
                    <button type="button" class="btn btn-sm btn-outline-primary js-preview">試搜尋</button>
                </div>
                <ul class="js-preview-results small mb-0 mt-2"></ul>
            </div>

            <button type="submit" class="btn btn-primary">{{ __('document-builder::ui.save') }}</button>
            <a href="{{ route($routeName('index')) }}" class="btn btn-secondary">{{ __('document-builder::ui.back_list') }}</a>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    var $form = $('#form-source');
    var columnsUrl = '{{ route($routeName('columns')) }}';
    var fieldsUrl = '{{ route($routeName('document_fields'), '___CODE___') }}';
    var previewUrl = '{{ route($routeName('preview')) }}';
    var indexUrl = '{{ route($routeName('index')) }}';
    var columns = [];

    function driver() { return $form.find('[name=driver]').val(); }

    function showDriver() {
        $form.find('.js-driver').each(function () { $(this).toggle($(this).data('driver') === driver()); });
    }

    function fillSelect($select, options, value, emptyLabel) {
        $select.empty();
        if (emptyLabel !== undefined) $select.append($('<option>').val('').text(emptyLabel));
        $.each(options, function (key, label) { $select.append($('<option>').val(key).text(label)); });
        if (value !== undefined && value !== null) $select.val(value);
    }

    // 資料模型：選了 model 才載入它的欄位，代碼／名稱欄位與條件都只能選這些欄位
    function loadColumns(keepValues) {
        var model = $form.find('[name="settings[model]"]').val();
        columns = [];
        var refill = function () {
            var options = {};
            columns.forEach(function (c) { options[c] = c; });
            $form.find('.js-column').each(function () {
                fillSelect($(this), options, keepValues ? $(this).data('value') : null, '請選擇');
            });
            $form.find('.js-condition-column').each(function () {
                var current = $(this).val() || $(this).data('value');
                fillSelect($(this), options, current, '欄位');
            });
        };
        if (!model) { refill(); return; }
        $.getJSON(columnsUrl, { model: model }).done(function (data) { columns = data.columns; refill(); });
    }

    function addCondition($group, column, value) {
        var $row = $('<div class="input-group input-group-sm mb-2 js-condition">' +
            '<select class="form-select js-condition-column"></select>' +
            '<span class="input-group-text">=</span>' +
            '<input type="text" class="form-control js-condition-value" placeholder="值">' +
            '<button type="button" class="btn btn-outline-danger js-remove-condition" aria-label="移除條件">×</button></div>');
        var options = {};
        columns.forEach(function (c) { options[c] = c; });
        $row.find('.js-condition-column').data('value', column || '');
        fillSelect($row.find('.js-condition-column'), options, column || '', '欄位');
        $row.find('.js-condition-value').val(value === undefined ? '' : value);
        $group.find('.js-condition-rows').append($row);
    }

    // 單據：選了單據才載入可以當顯示文字的欄位
    function loadFields(keepValue) {
        var code = $form.find('[name="settings[doc_type]"]').val();
        var $select = $form.find('[name="settings[text_field]"]');
        if (!code) { fillSelect($select, {}, '', '只顯示單號'); return; }
        $.getJSON(fieldsUrl.replace('___CODE___', code)).done(function (data) {
            fillSelect($select, data.fields, keepValue ? $select.data('value') : '', '只顯示單號');
        });
    }

    function payload() {
        var settings = {};
        if (driver() === 'model') {
            settings.model = $form.find('[name="settings[model]"]').val();
            settings.code = $form.find('[name="settings[code]"]').val();
            settings.text = $form.find('[name="settings[text]"]').val();
            $form.find('.js-conditions').each(function () {
                var group = {};
                $(this).find('.js-condition').each(function () {
                    var column = $(this).find('.js-condition-column').val();
                    if (column) group[column] = $(this).find('.js-condition-value').val();
                });
                settings[$(this).data('group')] = group;
            });
        } else if (driver() === 'document') {
            settings.doc_type = $form.find('[name="settings[doc_type]"]').val();
            settings.text_field = $form.find('[name="settings[text_field]"]').val() || null;
        }
        return {
            label: $form.find('[name=label]').val(),
            code: $form.find('[name=code]').val(),
            status: $form.find('[name=status]').val(),
            driver: driver(),
            settings: settings
        };
    }

    $form.on('change', '[name=driver]', showDriver);
    $form.on('change', '[name="settings[model]"]', function () { loadColumns(false); });
    $form.on('change', '[name="settings[doc_type]"]', function () { loadFields(false); });
    $form.on('click', '.js-add-condition', function () { addCondition($(this).closest('.js-conditions')); });
    $form.on('click', '.js-remove-condition', function () { $(this).closest('.js-condition').remove(); });

    $form.on('click', '.js-preview', function () {
        var $list = $form.find('.js-preview-results').empty();
        $.ajax({
            url: previewUrl, type: 'POST', contentType: 'application/json', dataType: 'json',
            data: JSON.stringify($.extend(payload(), { term: $form.find('.js-preview-term').val() }))
        }).done(function (data) {
            if (!data.results.length) { $list.append($('<li class="text-muted">').text('沒有符合的資料')); return; }
            data.results.forEach(function (row) { $list.append($('<li>').text(row.name)); });
        });
    });

    $form.on('submit', function (e) {
        e.preventDefault();
        var $button = $form.find('button[type=submit]').attr('disabled', true);
        $.ajax({
            url: $form.attr('action'), type: $form.data('method'), contentType: 'application/json', dataType: 'json',
            data: JSON.stringify(payload())
        }).done(function (data) {
            Swal.fire({ text: data.message, icon: 'success' }).then(function () { location.href = indexUrl; });
        }).always(function () { $button.attr('disabled', false); });
    });

    // 初始畫面：還原既有設定
    showDriver();
    $form.find('.js-conditions').each(function () {
        var $group = $(this);
        $.each($group.data('value') || {}, function (column, value) { addCondition($group, column, value); });
    });
    loadColumns(true);
    loadFields(true);
});
</script>
@endpush
