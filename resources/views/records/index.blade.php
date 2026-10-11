@extends(config('document_builder.layout'))

@section('content')
<div class="block block-rounded">
    <div class="block-header block-header-default">
        <h3 class="block-title">{{ $docType->name }}{{ __('document-builder::ui.list') }}</h3>
        @if($docType->hasButton('create'))
        <a href="{{ route(document_builder_route_name('doc_records.create'), $docType->code) }}" class="btn btn-primary create">{{ __('document-builder::ui.create') }}</a>
        @endif
    </div>
    <div class="block-content block-content-full">
        <form id="form-filter" class="row g-2 align-items-end mb-3">
            <div class="col-md-2">
                <label class="form-label">單據日期起</label>
                <input type="date" name="date_from" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">單據日期迄</label>
                <input type="date" name="date_to" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">狀態</label>
                <select name="status_id" class="form-select" data-no-select2>
                    <option value="">全部狀態</option>
                    @foreach($statuses as $status)
                    <option value="{{ $status->id }}">{{ $status->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">關鍵字</label>
                <input type="text" name="keyword" class="form-control" placeholder="搜尋任一欄位內容">
            </div>
            @foreach($listFields as $listField)
            <div class="col-md-2">
                <label class="form-label">{{ $listField->label }}</label>
                <input type="text" name="field[{{ $listField->key }}]" class="form-control" placeholder="完全相符">
            </div>
            @endforeach
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary">查詢</button>
                <button type="reset" class="btn btn-secondary">清除</button>
            </div>
        </form>
        <table class="table table-bordered table-striped table-vcenter" id="data-table">
            <thead></thead>
            <tbody></tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    var path = '{{ route(document_builder_route_name('doc_records.index'), $docType->code) }}';
    // 各列的編輯／刪除依單據設定的按鈕開關決定；後端同樣會擋網址
    @php $buttonFlags = collect(\DocumentBuilder\Models\DocType::BUTTONS)->map(fn ($label, $key) => $docType->hasButton($key)); @endphp
    var buttons = @json($buttonFlags);
    var $filter = $('#form-filter');
    var $statusFilter = $filter.find('[name="status_id"]');
    $statusFilter.select2({ allowClear: true, width: '100%', placeholder: '全部狀態' });
    var table = $('#data-table').DataTable({
        autoWidth: false,
        processing: true,
        serverSide: true,
        ordering: false,
        searching: false,
        responsive: true,
        language: { url: '{{ \DocumentBuilder\Support\Assets::datatablesLanguage() }}' },
        ajax: {
            url: path,
            data: function (d) {
                $.each($filter.serializeArray(), function (_, f) { d[f.name] = f.value; });
            }
        },
        columns: [
            { data: 'id', title: '#', className: 'text-center', render: function (d, t, r, meta) {
                return meta.row + 1 + meta.settings._iDisplayStart;
            } },
            { data: 'no', title: '單號', className: 'text-center text-nowrap' },
            { data: 'doc_date', title: '單據日期', className: 'text-center' },
            { data: 'status_name', title: '狀態', className: 'text-center', render: function (name, type, row) {
                var colors = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];
                var color = colors.indexOf(row.status_color) >= 0 ? row.status_color : 'secondary';
                var safeName = String(name || '').replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
                return `<span class="badge bg-${color}">${safeName}</span>`;
            } },
            @foreach($listFields as $field)
            { data: @json('f_'.$field->key), title: @json($field->label) },
            @endforeach
            { data: 'creator_name', title: '建立人', className: 'text-center' },
            { data: 'id', title: '{{ __('document-builder::ui.option') }}', className: 'text-center text-nowrap', render: function (id, type, row) {
                var html = `<a class="read btn btn-sm btn-primary" href="${path}/${id}">{{ __('document-builder::ui.read') }}</a>`;
                if (row.revision_locked) html += '<span class="badge bg-warning text-dark ms-2">舊版</span>';
                if (buttons.edit && !row.status_locks_editing && !row.revision_locked) html += `<a class="edit btn btn-sm btn-warning ms-2" href="${path}/${id}/edit">{{ __('document-builder::ui.edit') }}</a>`;
                if (buttons.delete && !row.status_locks_editing && !row.revision_locked) html += `<a class="delete btn btn-sm btn-danger ms-2" data-id="${id}" href="javascript:;">{{ __('document-builder::ui.delete') }}</a>`;
                return html;
            } },
        ]
    });

    $filter.on('submit', function (e) {
        e.preventDefault();
        table.ajax.reload();
    });
    $filter.on('reset', function () {
        setTimeout(function () { table.ajax.reload(); });
    });

    $('#data-table').on('click', '.delete', function () {
        var id = $(this).data('id');
        Swal.fire({
            text: '{{ __('document-builder::ui.confirm_delete') }}',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '{{ __('document-builder::ui.sure') }}',
            cancelButtonText: '{{ __('document-builder::ui.cancel') }}'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            $.ajax({ url: `${path}/${id}`, type: 'DELETE', dataType: 'json' }).done(function (data) {
                Swal.fire({ text: data.message, icon: 'success' }).then(function () { table.ajax.reload(null, false); });
            });
        });
    });
});
</script>
@endpush
