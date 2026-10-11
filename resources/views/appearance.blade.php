@extends(config('document_builder.layout'))

@section('content')
<div class="block block-rounded">
    <div class="block-header block-header-default">
        <h3 class="block-title">外觀設定</h3>
        <a href="{{ route(document_builder_route_name('document_builder.types.index')) }}" class="btn btn-secondary">{{ __('document-builder::ui.back_list') }}</a>
    </div>
    <div class="block-content">
        @if(session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif

        <form method="post" action="{{ route(document_builder_route_name('document_builder.appearance.update')) }}" class="js-appearance" style="max-width: 40rem">
            @csrf
            @method('PUT')
            @php($isCustom = ! array_key_exists($primary, $presets) && $primary !== \DocumentBuilder\Support\Theme::DEFAULT_PRIMARY)
            <div class="mb-3">
                <label class="form-label d-block">按鈕主色</label>
                <div class="d-flex flex-wrap gap-2" role="radiogroup" aria-label="按鈕主色">
                    @foreach($presets as $hex => $label)
                    <label class="btn btn-sm btn-outline-secondary mb-0" style="cursor: pointer">
                        <input type="radio" name="primary_choice" value="{{ $hex }}" class="form-check-input me-1" @checked(! $isCustom && $primary === $hex)>
                        <span class="d-inline-block rounded-circle align-middle me-1" style="width: .9rem; height: .9rem; background: {{ $hex }}"></span>{{ $label }}
                    </label>
                    @endforeach
                    <label class="btn btn-sm btn-outline-secondary mb-0" style="cursor: pointer">
                        <input type="radio" name="primary_choice" value="custom" class="form-check-input me-1" @checked($isCustom)>自訂
                    </label>
                </div>
                <div class="d-flex align-items-center gap-2 mt-2">
                    <input type="color" class="form-control form-control-color js-hex-picker" value="{{ $primary }}" title="自訂顏色" @disabled(! $isCustom)>
                    <input type="text" name="primary" class="form-control js-hex-text" style="max-width: 9rem" value="{{ $primary }}" placeholder="#RRGGBB" maxlength="7" @disabled(! $isCustom)>
                </div>
                <div class="form-text">只影響按鈕與標題列的主色。文字會依顏色自動換成白字或深字，確保看得清楚。</div>
            </div>

            <div class="mb-4">
                <label class="form-label d-block">預覽</label>
                {{-- 預覽用的是和正式頁面相同的主色變數（由下方腳本依選色更新），按鈕樣式來自 theme.css --}}
                <div class="js-preview p-3 rounded border bg-light d-flex flex-wrap gap-2 align-items-center">
                    <span class="btn btn-primary">新增</span>
                    <span class="btn btn-secondary">返回列表</span>
                    <span class="btn btn-outline-primary">變更狀態</span>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">儲存</button>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var DEFAULT = '{{ \DocumentBuilder\Support\Theme::DEFAULT_PRIMARY }}';
    var $form = $('.js-appearance');
    var $preview = $('.js-preview');

    // 和伺服器端 DocumentBuilder\Support\Theme 相同的規則：白字對比 ≥ 3 用白字，否則用深字
    function rgb(hex) {
        return [1, 3, 5].map(function (i) { return parseInt(hex.substr(i, 2), 16); });
    }
    function luminance(c) {
        var v = c.map(function (x) { x = x / 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); });
        return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
    }
    function contrast(a, b) {
        var la = luminance(a), lb = luminance(b);
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    }
    function paint(hex) {
        if (!/^#[0-9a-fA-F]{6}$/.test(hex)) return;
        var c = rgb(hex);
        var dark = c.map(function (x) { return Math.round(x * 0.88); });
        var hexDark = '#' + dark.map(function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
        var text = contrast(c, [255, 255, 255]) >= 3 ? '#ffffff' : '#111827';
        $preview.css({
            '--db-primary': hex,
            '--db-primary-dark': hexDark,
            '--db-primary-rgb': c.join(', '),
            '--db-primary-text': text
        });
        $('.js-hex-picker').val(hex);
    }
    function choice() { return $form.find('[name=primary_choice]:checked').val(); }

    $form.find('[name=primary_choice]').on('change', function () {
        var custom = $(this).val() === 'custom';
        $('.js-hex-text, .js-hex-picker').prop('disabled', !custom);
        if (!custom) {
            $('.js-hex-text').val($(this).val());
            paint($(this).val());
        }
    });
    $('.js-hex-text').on('input', function () { paint($(this).val().trim()); });
    $('.js-hex-picker').on('input', function () { $('.js-hex-text').val(this.value); paint(this.value); });

    // 送出時：色票用選到的值，自訂用文字欄位（disabled 的欄位不會送出，所以這裡先打開）
    $form.on('submit', function () {
        var value = choice() === 'custom' ? $('.js-hex-text').val().trim() : choice();
        $('.js-hex-text').prop('disabled', false).val(value);
    });
    paint($('.js-hex-text').val() || DEFAULT);
})();
</script>
@endpush
