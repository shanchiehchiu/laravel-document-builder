@extends(config('document_builder.layout'))

@section('content')
<div class="block block-rounded">
    <div class="block-header block-header-default">
        <h3 class="block-title">{{ $docType->name }}－表單預覽</h3>
        <a href="{{ route(document_builder_route_name('document_builder.designer.edit'), $docType) }}" class="btn btn-secondary">返回設計器</a>
    </div>
    <div class="block-content block-content-full">
        <div class="alert alert-info" role="status">停用草稿的預覽不會建立正式單據。</div>
        @if($previewHtml)
            {{-- 這段 HTML 由已驗證的白名單 Runtime renderer 產生，沒有正式單據的儲存按鈕。 --}}
            {!! $previewHtml !!}
        @else
            <div class="alert alert-warning" role="alert">目前表單結構無法預覽。</div>
            <ul>
                @foreach($validation['errors'] as $error)
                <li>{{ $error['message'] }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection
