{{-- 單據建置自帶的版型：宿主沒有指定 document_builder.layout 時使用，不依賴宿主的任何畫面或樣式。
     所有函式庫都從套件資產載入（不走 CDN），內網環境也能用。
     套件頁面只用到 content 區塊、styles 與 scripts 堆疊。 --}}
@php
    $asset = fn (string $path): string => \DocumentBuilder\Support\Assets::url($path);
    $user = \DocumentBuilder\DocumentBuilder::user();
    $canDesign = \DocumentBuilder\DocumentBuilder::canDesign($user);
    $homeUrl = config('document_builder.ui.home_url');
    $logoutRoute = config('document_builder.ui.logout_route');
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('document_builder.ui.brand') ?: config('document_builder.menu.builder') }}</title>
    @if($favicon = \DocumentBuilder\Support\Assets::external(config('document_builder.assets.favicon')))
        <link rel="icon" href="{{ $favicon }}">
    @endif
    <link rel="stylesheet" href="{{ $asset('vendor/bootstrap-5.3.3/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ $asset('vendor/select2-4.1.0-rc.0/select2.min.css') }}">
    <link rel="stylesheet" href="{{ $asset('vendor/datatables-1.13.8/dataTables.bootstrap5.min.css') }}">
    <link rel="stylesheet" href="{{ $asset('css/app.css') }}">
    @stack('styles')
</head>
<body class="db-app">
<nav class="db-topbar navbar navbar-expand-md">
    <div class="container-fluid">
        <a class="navbar-brand" href="{{ route(document_builder_route_name('document_builder.home')) }}">
            {{ config('document_builder.ui.brand') ?: config('document_builder.menu.builder') }}
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#db-nav" aria-controls="db-nav" aria-expanded="false" aria-label="切換選單">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="db-nav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs(document_builder_route_name('document_builder.home'), document_builder_route_name('doc_records.*')) ? 'active' : '' }}"
                       href="{{ route(document_builder_route_name('document_builder.home')) }}">{{ __('document-builder::ui.my_documents') }}</a>
                </li>
                @if($canDesign)
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs(document_builder_route_name('document_builder.types.*'), document_builder_route_name('document_builder.revisions.*')) ? 'active' : '' }}"
                           href="{{ route(document_builder_route_name('document_builder.types.index')) }}">{{ __('document-builder::ui.type_settings') }}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs(document_builder_route_name('document_builder.sources.*')) ? 'active' : '' }}"
                           href="{{ route(document_builder_route_name('document_builder.sources.index')) }}">{{ __('document-builder::ui.sources') }}</a>
                    </li>
                @endif
                @if(\DocumentBuilder\DocumentBuilder::isSuperAdmin($user))
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs(document_builder_route_name('document_builder.setup')) ? 'active' : '' }}"
                           href="{{ route(document_builder_route_name('document_builder.setup')) }}">{{ __('document-builder::ui.setup') }}</a>
                    </li>
                @endif
            </ul>
            <div class="d-flex align-items-center gap-2">
                @if($user)
                    <span class="navbar-text small">{{ \DocumentBuilder\DocumentBuilder::userName($user) }}</span>
                @endif
                @if($homeUrl)
                    <a class="btn btn-sm btn-outline-secondary" href="{{ url($homeUrl) }}">{{ __('document-builder::ui.back_to_app') }}</a>
                @endif
                @if($logoutRoute && \Illuminate\Support\Facades\Route::has($logoutRoute))
                    <form method="POST" action="{{ route($logoutRoute) }}" class="m-0">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('document-builder::ui.logout') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</nav>
<main class="db-main container-fluid">
    @yield('content')
</main>

<script src="{{ $asset('vendor/jquery-3.7.1/jquery.min.js') }}"></script>
<script src="{{ $asset('vendor/jquery-form-4.3.0/jquery.form.min.js') }}"></script>
<script src="{{ $asset('vendor/bootstrap-5.3.3/bootstrap.bundle.min.js') }}"></script>
<script src="{{ $asset('vendor/select2-4.1.0-rc.0/select2.min.js') }}"></script>
@if(str_starts_with(app()->getLocale(), 'zh'))
    <script src="{{ $asset('vendor/select2-4.1.0-rc.0/i18n/zh-TW.js') }}"></script>
@endif
<script src="{{ $asset('vendor/sweetalert2-11.12.4/sweetalert2.all.min.js') }}"></script>
<script src="{{ $asset('vendor/datatables-1.13.8/jquery.dataTables.min.js') }}"></script>
<script src="{{ $asset('vendor/datatables-1.13.8/dataTables.bootstrap5.min.js') }}"></script>
<script>
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });
    @if(str_starts_with(app()->getLocale(), 'zh'))
        $.fn.select2.defaults.set('language', 'zh-TW');
    @endif
    // AJAX 失敗時統一顯示訊息：422 列出每個欄位的第一個驗證錯誤，其他錯誤顯示 message
    $(document).ajaxError(function (event, xhr, settings, thrownError) {
        if (thrownError === 'abort') return;
        var json = xhr.responseJSON || {};
        var messages = json.errors
            ? Object.values(json.errors).map(function (list) { return Array.isArray(list) ? list[0] : list; })
            : [json.message || thrownError || ('HTTP ' + xhr.status)];
        var escape = function (text) { return $('<div>').text(text).html(); };
        Swal.fire({ icon: 'error', html: messages.map(escape).join('<br>') });
    });
</script>
@stack('scripts')
</body>
</html>
