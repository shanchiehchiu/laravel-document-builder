@extends(config('document_builder.layout'))

@section('content')
<div class="block block-rounded">
    <div class="block-header block-header-default">
        <h3 class="block-title">來源庫</h3>
        @if($sources->isNotEmpty() || $configured->isNotEmpty())
        <a href="{{ route(document_builder_route_name('document_builder.sources.create')) }}" class="btn btn-sm btn-primary">{{ __('document-builder::ui.create') }}</a>
        @endif
    </div>
    @if($sources->isEmpty() && $configured->isEmpty())
    {{-- 沒有任何來源：不畫空表格，改為置中的引導 --}}
    <div class="block-content text-center py-5">
        <div class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle bg-light" style="width: 4rem; height: 4rem">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="text-muted" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>
        </div>
        <h4 class="h6 mb-1">尚無任何主檔來源</h4>
        <p class="text-muted small mb-4">主檔來源供表單的主檔下拉使用，例如客戶、廠商、產品或同事。</p>
        <a href="{{ route(document_builder_route_name('document_builder.sources.create')) }}" class="btn btn-primary">{{ __('document-builder::ui.create') }}第一個主檔來源</a>
    </div>
    @else
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
                </tbody>
            </table>
        </div>
    </div>
    @endif
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
