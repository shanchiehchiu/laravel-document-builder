@extends(config('document_builder.layout'))

@section('content')
<div class="block block-rounded">
    <div class="block-header block-header-default">
        <h3 class="block-title">來源庫</h3>
        <a href="{{ route(document_builder_route_name('document_builder.sources.create')) }}" class="btn btn-sm btn-primary">{{ __('document-builder::ui.create') }}</a>
    </div>
    <div class="block-content block-content-full">
        <p class="text-muted small">主檔下拉欄位的資料從這裡來。設計表單時，每個啟用的來源都會出現在左側「主檔下拉」。</p>
        <div class="table-responsive">
            <table class="table table-bordered table-vcenter mb-0">
                <thead>
                <tr>
                    <th>名稱</th>
                    <th>代碼</th>
                    <th>類型</th>
                    <th>資料</th>
                    <th class="text-center">使用中的欄位</th>
                    <th class="text-center">{{ __('document-builder::ui.status') }}</th>
                    <th class="text-center">{{ __('document-builder::ui.option') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($sources as $row)
                    @php($source = $row['model'])
                    <tr>
                        <td>{{ $source->label }}</td>
                        <td><code>{{ $source->code }}</code></td>
                        <td>{{ $row['driver'] }}</td>
                        <td class="small">{{ $row['summary'] }}</td>
                        <td class="text-center">{{ $row['usage'] }}</td>
                        <td class="text-center">
                            <span class="badge bg-{{ $source->status ? 'success' : 'secondary' }}">{{ $source->status ? __('document-builder::ui.active') : __('document-builder::ui.inactive') }}</span>
                        </td>
                        <td class="text-center text-nowrap">
                            <a class="btn btn-sm btn-warning" href="{{ route(document_builder_route_name('document_builder.sources.edit'), $source) }}">{{ __('document-builder::ui.edit') }}</a>
                            @if($row['usage'] === 0)
                                <button type="button" class="btn btn-sm btn-danger ms-1 js-delete-source" data-url="{{ route(document_builder_route_name('document_builder.sources.destroy'), $source) }}">{{ __('document-builder::ui.delete') }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @foreach($configured as $row)
                    <tr class="table-light">
                        <td>{{ $row['label'] }}</td>
                        <td><code>{{ $row['code'] }}</code></td>
                        <td>{{ $row['driver'] }}</td>
                        <td class="small">{{ $row['summary'] }}</td>
                        <td class="text-center">{{ $row['usage'] }}</td>
                        <td class="text-center"><span class="badge bg-success">{{ __('document-builder::ui.active') }}</span></td>
                        <td class="text-center small text-muted">程式設定，不得在這裡修改</td>
                    </tr>
                @endforeach
                @if($sources->isEmpty() && $configured->isEmpty())
                    <tr><td colspan="7" class="text-center text-muted">還沒有任何來源，按右上角「{{ __('document-builder::ui.create') }}」新增。</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    $('.js-delete-source').on('click', function () {
        var url = $(this).data('url');
        Swal.fire({
            text: '{{ __('document-builder::ui.confirm_delete') }}',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '{{ __('document-builder::ui.sure') }}',
            cancelButtonText: '{{ __('document-builder::ui.cancel') }}'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            $.ajax({ url: url, type: 'DELETE', dataType: 'json' }).done(function (data) {
                Swal.fire({ text: data.message, icon: 'success' }).then(function () { location.reload(); });
            });
        });
    });
});
</script>
@endpush
