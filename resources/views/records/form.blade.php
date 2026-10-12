@extends(config('document_builder.layout'))

@php
    $isCreate = $record === null;
    $values = $record?->data ?? [];
    $title = $isCreate ? __('document-builder::ui.create') : ($show ? __('document-builder::ui.detail_info') : __('document-builder::ui.edit'));
@endphp

@section('content')
<div class="block block-rounded">
    <div class="block-header block-header-default">
        <h3 class="block-title">{{ $docType->name }}－{{ $title }}</h3>
        @unless($isCreate)
            @if($docType->hasButton('create'))
            <a href="{{ route(document_builder_route_name('doc_records.create'), $docType->code) }}" class="btn btn-primary me-2 create">{{ __('document-builder::ui.create') }}</a>
            @endif
            @if($show)
                @if($docType->hasButton('edit') && ! $record->status?->locks_editing && ! $isOutdated)
                <a href="{{ route(document_builder_route_name('doc_records.edit'), [$docType->code, $record]) }}" class="btn btn-warning me-2 edit">{{ __('document-builder::ui.edit') }}</a>
                @endif
            @else
            <a href="{{ route(document_builder_route_name('doc_records.show'), [$docType->code, $record]) }}" class="btn btn-info me-2 read">{{ __('document-builder::ui.read') }}</a>
            @endif
        @endunless
        <a href="{{ route(document_builder_route_name('doc_records.index'), $docType->code) }}" class="btn btn-secondary">{{ __('document-builder::ui.back_list') }}</a>
        @if($record)
        <span class="badge bg-{{ $record->status?->color ?? 'secondary' }} ms-2">{{ $record->status?->name ?? '未設定' }}</span>
        @if($formRevision)
        <span class="badge bg-info ms-2">表單第 {{ $formRevision->revision }} 版</span>
        @endif
        @if($isOutdated)
        <span class="badge bg-warning text-dark ms-2">舊版，只能檢視</span>
        @endif
        @if($show && $docType->hasButton('edit'))
        <form id="form-status" class="d-flex align-items-center gap-2 ms-3" method="post"
            action="{{ route(document_builder_route_name('doc_records.status'), [$docType->code, $record]) }}">
            @csrf
            @method('PATCH')
            <label class="visually-hidden" for="doc-status-select">單據狀態</label>
            <select id="doc-status-select" name="status_id" class="form-select" data-no-select2>
                @foreach($statuses as $status)
                <option value="{{ $status->id }}" @selected($status->id === $record->status_id)>{{ $status->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-outline-primary text-nowrap">變更狀態</button>
        </form>
        @endif
        @endif
        @if($adjacent)
            {{-- 外觀沿用 x-backend::around-records；網址要帶單據代碼，並停留在目前的檢視／編輯模式 --}}
            @php $navRoute = $show ? document_builder_route_name('doc_records.show') : document_builder_route_name('doc_records.edit'); @endphp
            <div class="ms-2">
                <div class="btn-group" role="group" aria-label="Record navigation">
                    @if($adjacent['previous'])
                    <a href="{{ route($navRoute, [$docType->code, $adjacent['previous']]) }}" class="btn btn-outline-secondary" rel="prev" aria-label="上一筆">← 上一筆</a>
                    @else
                    <button class="btn btn-outline-secondary" type="button" disabled aria-disabled="true" title="沒有上一筆">← 上一筆</button>
                    @endif
                    @if($adjacent['next'])
                    <a href="{{ route($navRoute, [$docType->code, $adjacent['next']]) }}" class="btn btn-outline-secondary" rel="next" aria-label="下一筆">下一筆 →</a>
                    @else
                    <button class="btn btn-outline-secondary" type="button" disabled aria-disabled="true" title="沒有下一筆">下一筆 →</button>
                    @endif
                </div>
            </div>
        @endif
    </div>
    <div class="block-content block-content-full">
        @if($isOutdated)
        <div class="alert alert-info" role="status">這張單據使用建立時的表單版本，為保留歷史資料，目前只能檢視。</div>
        @endif
        <form id="{{ $isCreate ? 'form-create' : 'form-edit' }}" method="post"
            data-form-events="{{ json_encode($events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
            data-conversion-groups="{{ json_encode($conversionGroups ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
            action="{{ $isCreate ? route(document_builder_route_name('doc_records.store'), $docType->code) : route(document_builder_route_name('doc_records.update'), [$docType->code, $record]) }}">
            @csrf
            @unless($isCreate) @method('PUT') @endunless
            @include('document-builder::records._runtime_fields')
            @unless($show)
            <button type="submit" class="btn btn-primary">{{ __('document-builder::ui.save') }}</button>
            @endunless
            <a href="{{ route(document_builder_route_name('doc_records.index'), $docType->code) }}" class="btn btn-secondary">{{ __('document-builder::ui.back_list') }}</a>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ \DocumentBuilder\Support\Assets::url('form-events.js') }}"></script>
<script src="{{ \DocumentBuilder\Support\Assets::url('amount-field.js') }}"></script>
<script src="{{ \DocumentBuilder\Support\Assets::url('master-extras.js') }}"></script>
<script src="{{ \DocumentBuilder\Support\Assets::url('conversion-group.js') }}"></script>
<script>
$(function () {
    var $form = $('#form-create, #form-edit');
    @if($show)
    // 詳情頁唯讀：欄位與明細列按鈕全部停用（要在 select2 初始化之前，下拉才會顯示成停用）
    $form.find('[name]').not('[name=_token], [name=_method]').prop('disabled', true);
    $form.find('.doc-item-add, .doc-item-remove').prop('disabled', true).attr('hidden', true);
    @endif
    var showUrl = '{{ route(document_builder_route_name('doc_records.show'), [$docType->code, '___ID___']) }}';

    // 明細表：新增列從 <template> 複製，列索引用不重複的遞增值（刪列後不回收，送出時後端重新編號）
    // 宿主版型若已有全域的 select2 初始化，已初始化的下拉會跳過，不重複處理
    function initSelects($scope) {
        // 固定選項：選項不多時不顯示搜尋框
        $scope.find('select.js-choice').each(function () {
            var $select = $(this);
            if ($select.hasClass('select2-hidden-accessible')) return;
            $select.select2({
                allowClear: true,
                width: '100%',
                placeholder: $select.data('placeholder'),
                minimumResultsForSearch: 10
            });
        });
        $scope.find('.js-select2[data-url]').each(function () {
            var $select = $(this);
            if ($select.hasClass('select2-hidden-accessible')) return;
            $select.select2({
                allowClear: true,
                width: '100%',
                placeholder: $select.data('placeholder'),
                ajax: {
                    url: $select.data('url'),
                    data: function (params) { return { search: params.term }; },
                    processResults: function (data) {
                        return { results: data.map(function (item) { return { id: item.id, text: item.name }; }) };
                    }
                }
            });
        });
    }
    initSelects($form);
    var $statusForm = $('#form-status');
    if ($statusForm.length) {
        $statusForm.find('select').select2({ width: '12rem', minimumResultsForSearch: 10 });
        $statusForm.ajaxForm({
            beforeSubmit: function () { $statusForm.find('button[type=submit]').attr('disabled', true); },
            success: function (data) {
                Swal.fire({ text: data.message, icon: 'success' }).then(function () { location.reload(); });
            },
            complete: function () { $statusForm.find('button[type=submit]').attr('disabled', false); }
        });
    }
    function renumber($table) {
        $table.find('tbody .doc-item-no').each(function (i) { $(this).text(i + 1); });
    }
    function recalc($table) {
        $table.find('[data-sum-total]').each(function () {
            var key = $(this).data('sumTotal'), decimals = Number($(this).data('decimals')) || 0, total = 0;
            $table.find('tbody [data-sum="' + key + '"]').each(function () { total += parseFloat(this.value) || 0; });
            $(this).text(total.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }));
        });
    }
    $form.on('click', '.doc-item-add', function () {
        var $table = $(this).closest('.doc-items');
        var index = Number($table.attr('data-next'));
        $table.attr('data-next', index + 1);
        var html = $table.find('template.doc-item-template').html().replace(/__INDEX__/g, index);
        var $row = $(html).appendTo($table.find('tbody'));
        initSelects($row);
        // 新列的主檔附隨欄位也要鎖成唯讀
        if (window.DBMasterExtras) window.DBMasterExtras.mark($row);
        renumber($table);
        $row.find('input:not([type=hidden]), select, textarea').first().trigger('focus');
    });
    $form.on('click', '.doc-item-remove', function () {
        var $table = $(this).closest('.doc-items');
        $(this).closest('tr').remove();
        renumber($table);
        recalc($table);
    });
    $form.on('input change', '.doc-items [data-sum]', function () {
        recalc($(this).closest('.doc-items'));
    });

    $form.ajaxForm({
        beforeSubmit: function () { $form.find('button[type=submit]').attr('disabled', true); },
        success: function (data) {
            Swal.fire({ text: data.message, icon: 'success' }).then(function () {
                location.href = showUrl.replace('___ID___', data.id);
            });
        },
        complete: function () { $form.find('button[type=submit]').attr('disabled', false); }
    });
});
</script>
@endpush
