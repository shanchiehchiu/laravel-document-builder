{{-- 安裝精靈專用的版型：安裝時宿主的版型可能還沒設定好，所以完全不依賴宿主，也不跟著 document_builder.layout --}}
@php($asset = fn (string $path): string => \DocumentBuilder\Support\Assets::url($path))
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>單據建置平台｜安裝引導精靈</title>
    <link rel="stylesheet" href="{{ $asset('vendor/bootstrap-5.3.3/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ $asset('vendor/select2-4.1.0-rc.0/select2.min.css') }}">
    <link rel="stylesheet" href="{{ $asset('css/install.css') }}">
    {!! \DocumentBuilder\Support\Theme::style() !!}
</head>
<body class="db-install">
<main class="db-install-shell">
    <header class="db-install-head">
        <span class="db-install-logo" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/></svg>
        </span>
        <div>
            <h1>單據建置平台</h1>
            <p>@yield('subtitle', '安裝引導精靈')</p>
        </div>
    </header>
    @yield('content')
</main>
<script src="{{ $asset('vendor/jquery-3.7.1/jquery.min.js') }}"></script>
<script src="{{ $asset('vendor/select2-4.1.0-rc.0/select2.min.js') }}"></script>
<script src="{{ $asset('vendor/sweetalert2-11.12.4/sweetalert2.all.min.js') }}"></script>
<script>
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'), 'Accept': 'application/json' } });
</script>
@stack('scripts')
</body>
</html>
