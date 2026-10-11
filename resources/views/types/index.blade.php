@extends(config('document_builder.layout'))

@section('content')
<div class="block block-rounded">
    <div class="block-header block-header-default">
        <h3 class="block-title">{{ __('document-builder::ui.list') }}</h3>
        <a href="{{ route(document_builder_route_name('document_builder.types.create')) }}" class="btn btn-primary create">{{ __('document-builder::ui.create') }}</a>
    </div>
    <div class="block-content block-content-full">
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
    var path = '{{ route(document_builder_route_name('document_builder.types.index')) }}';
    var recordUrl = '{{ route(document_builder_route_name('doc_records.index'), '___CODE___') }}';
    // 單據設定與表單版面都在設計器裡改
    var designerUrl = '{{ route(document_builder_route_name('document_builder.designer.edit'), '___ID___') }}';
    // 狀態文字：不依賴宿主版型的全域變數
    var statusList = { 0: '{{ __('document-builder::ui.inactive') }}', 1: '{{ __('document-builder::ui.active') }}' };
    var table = $('#data-table').DataTable({
        autoWidth: false,
        processing: true,
        serverSide: true,
        ordering: false,
        searching: false,
        responsive: true,
        ajax: path,
        language: { url: '{{ \DocumentBuilder\Support\Assets::datatablesLanguage() }}' },
        columns: [
            { data: 'id', title: '#', className: 'text-center', render: function (d, t, r, meta) {
                return meta.row + 1 + meta.settings._iDisplayStart;
            } },
            { data: 'name', title: '單據名稱' },
            { data: 'code', title: '單據代碼', className: 'text-center' },
            { data: 'fields_count', title: '欄位數', className: 'text-center' },
            { data: 'records_count', title: '單據筆數', className: 'text-center' },
            { data: 'status', title: '{{ __('document-builder::ui.status') }}', className: 'text-center', render: function (data) {
                return `<span class="badge bg-${data ? 'success' : 'danger'}">${statusList[data ? 1 : 0]}</span>`;
            } },
            { data: 'id', title: '{{ __('document-builder::ui.option') }}', className: 'text-center text-nowrap', render: function (id, type, row) {
                return `<a class="btn btn-sm btn-primary" href="${recordUrl.replace('___CODE___', row.code)}">開啟單據</a>
                    <a class="edit btn btn-sm btn-warning ms-2" href="${designerUrl.replace('___ID___', id)}">{{ __('document-builder::ui.edit') }}</a>
                    <a class="delete btn btn-sm btn-danger ms-2" data-id="${id}" href="javascript:;">{{ __('document-builder::ui.delete') }}</a>`;
            } },
        ]
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
