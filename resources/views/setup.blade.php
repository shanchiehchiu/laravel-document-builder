@extends('document-builder::layouts.install')

@php
    $route = fn (string $name, $params = []) => route(document_builder_route_name('document_builder.setup'.$name), $params);
    $route_ = fn (string $name, $params = []) => route(document_builder_route_name($name), $params);
    $levels = ['ok' => ['is-ok', '通過'], 'error' => ['is-error', '失敗'], 'warn' => ['is-warn', '注意']];
    $packageLayout = 'document-builder::layouts.app';
@endphp

@section('content')
<ol class="db-stepper" aria-label="安裝步驟">
    @foreach(['環境檢查', '帳號與版型', '路由與整合', '執行安裝', '完成'] as $i => $label)
        <li data-step="{{ $i + 1 }}"><span>{{ $label }}</span></li>
    @endforeach
</ol>

{{-- Step 1：環境檢查 --}}
<section class="db-install-card db-step" data-step="1">
    <div class="db-step-title">環境與相依性檢查</div>
    <div class="db-step-desc">先檢查執行環境，防止安裝到一半失敗。</div>

    @if($needsToken)
        <div class="js-token-box">
            <div class="alert alert-warning small">
                @if($tokenConfigured)
                    這不是本機開發環境，請輸入 <code>.env</code> 裡 <code>DOCUMENT_BUILDER_SETUP_TOKEN</code> 的值才可以繼續，防止別人搶先安裝。
                @else
                    這不是本機開發環境。請先在 <code>.env</code> 加上 <code>DOCUMENT_BUILDER_SETUP_TOKEN=自訂的一串密碼</code>，重新整理這一頁後輸入它。
                @endif
            </div>
            <div class="d-flex gap-2 mb-3">
                <input type="password" class="form-control js-token" placeholder="初始化 token" autocomplete="off" @disabled(! $tokenConfigured)>
                <button type="button" class="btn btn-outline-primary text-nowrap js-check" @disabled(! $tokenConfigured)>驗證並檢查</button>
            </div>
        </div>
    @endif

    <ul class="db-check-list js-checks">
        @foreach($checks as $check)
            <li class="db-row {{ $levels[$check['level']][0] }}">
                <span class="db-row-icon" aria-hidden="true"></span>
                <div class="db-row-body">
                    <div class="db-row-title">{{ $check['label'] }}</div>
                    <div class="db-row-sub">{{ $check['value'] }}</div>
                    @if($check['fix'])<div class="db-row-fix">{{ $check['fix'] }}</div>@endif
                </div>
                <span class="db-row-status">{{ $levels[$check['level']][1] }}</span>
            </li>
        @endforeach
    </ul>

    <div class="db-install-actions">
        <button type="button" class="btn db-btn-ghost js-check" @if($needsToken) hidden @endif>重新檢查</button>
        <button type="button" class="btn btn-primary js-next" data-to="2"
                @disabled($needsToken || collect($checks)->contains('level', 'error'))>下一步 <span aria-hidden="true">→</span></button>
    </div>
</section>

{{-- Step 2：帳號與版型 --}}
<section class="db-install-card db-step" data-step="2" hidden>
    <div class="db-step-title">帳號體系與版型</div>
    <div class="db-step-desc">已自動偵測專案的設定，確認無誤即可。</div>

    <div class="mb-3">
        <label class="form-label">登入 Guard</label>
        <select name="guard" class="form-select js-guard">
            @foreach($guards as $guard)
                <option value="{{ $guard['name'] }}" data-model="{{ $guard['model'] }}" @selected($values['guard'] === $guard['name'])>
                    {{ $guard['name'] }}（{{ class_basename($guard['model']) }}）{{ $guard['logged_in'] ? '' : '－目前沒有用它登入' }}
                </option>
            @endforeach
        </select>
        <div class="form-text">安裝完成後，你會用這個 guard 的帳號成為第一位超級管理員。</div>
    </div>
    <div class="row">
        <div class="col-md-7 mb-3">
            <label class="form-label">使用者 Model</label>
            <input type="text" name="user_model" class="form-control js-model" value="{{ $values['user_model'] }}">
        </div>
        <div class="col-md-5 mb-3">
            <label class="form-label">使用者資料表</label>
            <input type="text" name="users_table" class="form-control" value="{{ $values['users_table'] }}">
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label d-block">主鍵型別</label>
        <div class="db-pills">
            @foreach(['int' => '整數（int）', 'uuid' => 'UUID', 'ulid' => 'ULID'] as $key => $label)
                <label><input type="radio" name="user_key_type" value="{{ $key }}" @checked($values['user_key_type'] === $key)>{{ $label }}</label>
            @endforeach
        </div>
        <div class="form-text">單據建置的資料表指向使用者的欄位依這個型別建立，安裝後不得修改。</div>
    </div>
    <div class="mb-3">
        <label class="form-label">顯示名稱的欄位</label>
        <select name="user_name_column" class="form-select js-name-column">
            @foreach($modelInfo['columns'] ?? [] as $column)
                <option value="{{ $column }}" @selected($values['user_name_column'] === $column)>{{ $column }}</option>
            @endforeach
        </select>
    </div>
    @php
        $themePresets = ['' => '預設藍', '#2563eb' => '科技藍', '#059669' => '翡翠綠', '#4f46e5' => '靛青', '#1e293b' => '商務灰黑', '#ea580c' => '活力橙'];
        $currentTheme = (string) ($values['theme']['primary'] ?? '');
        $isCustomTheme = $currentTheme !== '' && ! array_key_exists($currentTheme, $themePresets);
    @endphp
    <div class="mb-3">
        <label class="form-label d-block">按鈕主色</label>
        <div class="db-pills js-theme" role="radiogroup" aria-label="按鈕主色">
            @foreach($themePresets as $hex => $label)
            <label><input type="radio" name="theme_primary" value="{{ $hex }}" @checked(! $isCustomTheme && $currentTheme === $hex)>
                <span class="db-swatch" style="background: {{ $hex ?: \DocumentBuilder\Support\Theme::DEFAULT_PRIMARY }}"></span>{{ $label }}</label>
            @endforeach
            <label><input type="radio" name="theme_primary" value="custom" @checked($isCustomTheme)>自訂</label>
        </div>
        <input type="text" class="form-control form-control-sm mt-2 js-theme-hex" style="max-width: 10rem" placeholder="#RRGGBB" value="{{ $isCustomTheme ? $currentTheme : '' }}" @disabled(! $isCustomTheme)>
        <div class="form-text">只用於按鈕與標題列。文字會依顏色自動換成白字或深字，確保看得清楚。</div>
    </div>
    <div class="mb-1">
        <label class="form-label d-block">頁面版型</label>
        <label class="db-option">
            <input type="radio" name="layout_mode" value="package" class="form-check-input me-2" @checked($values['layout'] === $packageLayout)>套件內建版型（建議）
            <small>單據建置自帶導覽列與樣式，不依賴專案的畫面。</small>
        </label>
        <label class="db-option">
            <input type="radio" name="layout_mode" value="custom" class="form-check-input me-2" @checked($values['layout'] !== $packageLayout)>嵌進專案的後台版型
            <small>填寫版型名稱，例如 <code>layouts.admin</code>；版型必須提供 content 區塊與 scripts 堆疊。</small>
            <input type="text" class="form-control form-control-sm mt-2 js-custom-layout" placeholder="layouts.admin"
                   value="{{ $values['layout'] !== $packageLayout ? $values['layout'] : '' }}">
        </label>
    </div>

    <div class="db-install-actions">
        <button type="button" class="btn db-btn-ghost js-prev" data-to="1">上一步</button>
        <button type="button" class="btn btn-primary js-next" data-to="3">下一步 <span aria-hidden="true">→</span></button>
    </div>
</section>

{{-- Step 3：路由與整合 --}}
<section class="db-install-card db-step" data-step="3" hidden>
    <div class="db-step-title">路由與選單整合</div>
    <div class="db-step-desc">決定單據建置的網址與權限中介層；不確定就保留預設值。</div>

    <div class="mb-3">
        <label class="form-label">路由前綴</label>
        <input type="text" name="route_prefix" class="form-control js-prefix" value="{{ $values['route']['prefix'] }}" placeholder="例如 admin，留空表示沒有">
        <div class="db-preview-url">網址：<span class="js-prefix-preview"></span></div>
    </div>
    <div class="row">
        <div class="col-md-5 mb-3">
            <label class="form-label">路由名稱前綴</label>
            <input type="text" name="route_name" class="form-control" value="{{ $values['route']['name'] }}" placeholder="例如 admin.">
        </div>
        <div class="col-md-7 mb-3">
            <label class="form-label">中介層</label>
            <input type="text" name="route_middleware" class="form-control js-middleware" value="{{ $values['route']['middleware'] }}">
            <div class="form-text">逗號分隔，必須包含登入檢查，例如 <code>web,auth</code> 或 <code>web,auth:admin</code>。</div>
        </div>
    </div>
    <div class="mb-1">
        <label class="form-label d-block">側選單與權限整合</label>
        @foreach($integrations as $class => $label)
            <label class="db-option">
                <input type="radio" name="integration" value="{{ $class }}" class="form-check-input me-2" @checked($values['integration'] === $class)>{{ $label }}
                <small><code>{{ $class }}</code></small>
            </label>
        @endforeach
    </div>

    <div class="db-install-actions">
        <button type="button" class="btn db-btn-ghost js-prev" data-to="2">上一步</button>
        <button type="button" class="btn btn-primary js-save-settings">下一步 <span aria-hidden="true">→</span></button>
    </div>
</section>

{{-- Step 4：執行安裝 --}}
<section class="db-install-card db-step" data-step="4" hidden>
    <div class="db-step-title">執行安裝</div>
    <div class="db-step-desc">建立資料表、設定超級管理員並寫入設定檔。前端資產由套件直接提供，不需要發布。</div>

    <ul class="db-check-list js-tasks">
        <li class="db-row is-wait" data-task="migrate">
            <span class="db-row-icon" aria-hidden="true"></span>
            <div class="db-row-body"><div class="db-row-title">建立資料表結構</div><div class="db-row-sub">只執行單據建置自己的 migration，不會動到專案其他資料表</div></div>
            <span class="db-row-status">未開始</span>
        </li>
        <li class="db-row is-wait" data-task="admin">
            <span class="db-row-icon" aria-hidden="true"></span>
            <div class="db-row-body"><div class="db-row-title">指派超級管理員</div><div class="db-row-sub">將目前登入的帳號設為單據建置的超級管理員</div></div>
            <span class="db-row-status">未開始</span>
        </li>
        <li class="db-row is-wait" data-task="config">
            <span class="db-row-icon" aria-hidden="true"></span>
            <div class="db-row-body"><div class="db-row-title">產生設定檔</div><div class="db-row-sub"><code>config/document_builder.php</code></div></div>
            <span class="db-row-status">未開始</span>
        </li>
    </ul>
    <details class="mt-3">
        <summary class="small text-muted">執行記錄</summary>
        <pre class="db-log js-log">尚未執行</pre>
    </details>
    <div class="js-manual-config mt-3" hidden>
        <div class="alert alert-warning small mb-2">設定檔無法自動寫入。資料表與超級管理員都已完成，請把下面的內容存成 <code>config/document_builder.php</code>。</div>
        <pre class="db-log js-config"></pre>
        <button type="button" class="btn btn-sm btn-outline-secondary mt-2 js-copy-config">複製設定內容</button>
    </div>

    <div class="db-install-actions">
        <button type="button" class="btn db-btn-ghost js-prev" data-to="3">上一步</button>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary js-run">開始初始化安裝</button>
            <button type="button" class="btn btn-primary js-finish" hidden>下一步 <span aria-hidden="true">→</span></button>
        </div>
    </div>
</section>

{{-- Step 5：完成 --}}
<section class="db-install-card db-step" data-step="5" hidden>
    <div class="text-center mb-4">
        <div class="db-done-icon" aria-hidden="true">✓</div>
        <h2 class="h5 mb-1">單據建置平台已經可以使用</h2>
        <p class="text-muted small mb-0">
            @if(count($pending) > 0)
                還有資料表沒有建立，請回到「執行安裝」。
            @else
                資料表、超級管理員與設定都已完成。
            @endif
        </p>
    </div>

    @if($locked)
        <div class="d-flex flex-wrap justify-content-center gap-2 mb-4">
            <button type="button" class="btn btn-primary js-sample" data-url="{{ $route('.sample') }}">匯入範本並建立第一張單據</button>
            <a href="{{ $route_('document_builder.types.index') }}" class="btn btn-outline-primary">進入單據設定</a>
            <a href="{{ $route_('document_builder.home') }}" class="btn btn-outline-secondary">我的單據</a>
        </div>

        <h3 class="h6">超級管理員</h3>
        @if($hostDefines)
            <p class="small text-muted">超級管理員由專案的程式定義（<code>document-builder.super-admin</code> Gate），不在這裡設定。</p>
        @else
            <p class="small text-muted">可以設計單據、管理來源庫、發布與審核版本。</p>
            <ul class="db-check-list mb-3">
                @foreach($admins as $admin)
                    <li>
                        <span>{{ \DocumentBuilder\DocumentBuilder::userName($admin->user) ?? '已刪除的使用者' }}
                            @if((string) $admin->user_id === (string) $userId)<span class="text-muted small">（你）</span>@endif</span>
                        @if($admins->count() > 1)
                            <button type="button" class="btn btn-sm btn-outline-danger js-remove-admin" data-url="{{ $route('.admins.destroy', $admin) }}">移除</button>
                        @endif
                    </li>
                @endforeach
            </ul>
            <form class="d-flex gap-2 js-add-admin" data-url="{{ $route('.admins.store') }}">
                <select name="user_id" class="form-select js-user-search" data-url="{{ $route('.users') }}" data-placeholder="搜尋使用者名稱或 email"><option></option></select>
                <button type="submit" class="btn btn-outline-primary text-nowrap">加入</button>
            </form>
        @endif

        <details class="mt-4">
            <summary class="small text-muted">給開發人員：改用程式定義超級管理員</summary>
            <p class="small text-muted mt-2 mb-1">想用專案自己的規則時，在 AuthServiceProvider（Laravel 11 以後是 AppServiceProvider）的 boot() 加上：</p>
            <pre class="db-log">Gate::define('document-builder.super-admin', fn ($user) => $user->is_admin);</pre>
        </details>
    @endif
</section>
@endsection

@push('scripts')
<script>
$(function () {
    var urls = {
        check: '{{ $route('.check') }}',
        model: '{{ $route('.model') }}',
        settings: '{{ $route('.settings') }}',
        run: '{{ $route('.run', '___TASK___') }}'
    };
    var appUrl = @json(rtrim($appUrl, '/'));
    var packageLayout = @json($packageLayout);
    var installed = @json($installed);
    var nextUrl = null;

    function escape(text) { return $('<div>').text(text).html(); }

    // 失敗時顯示訊息：422 列出每個欄位的第一個錯誤
    $(document).ajaxError(function (event, xhr, settings, thrownError) {
        if (thrownError === 'abort') return;
        var json = xhr.responseJSON || {};
        var messages = json.errors
            ? Object.values(json.errors).map(function (list) { return Array.isArray(list) ? list[0] : list; })
            : [json.message || thrownError || ('HTTP ' + xhr.status)];
        Swal.fire({ icon: 'error', html: messages.map(escape).join('<br>') });
    });

    function go(step) {
        $('.db-step').attr('hidden', true).filter('[data-step=' + step + ']').attr('hidden', false);
        $('.db-stepper li').each(function () {
            var n = +$(this).data('step');
            $(this).toggleClass('is-current', n === step).toggleClass('is-done', n < step);
        });
        window.scrollTo(0, 0);
    }
    $('.js-next, .js-prev').on('click', function () { go(+$(this).data('to')); });

    // Step 1：環境檢查（正式環境同時驗證 token）
    var levels = { ok: ['is-ok', '通過'], error: ['is-error', '失敗'], warn: ['is-warn', '注意'] };
    $('.js-check').on('click', function () {
        $.post(urls.check, { token: $('.js-token').val() }).done(function (data) {
            var $list = $('.js-checks').empty();
            data.checks.forEach(function (c) {
                var fix = c.fix ? '<div class="db-row-fix">' + escape(c.fix) + '</div>' : '';
                $list.append('<li class="db-row ' + levels[c.level][0] + '"><span class="db-row-icon" aria-hidden="true"></span>' +
                    '<div class="db-row-body"><div class="db-row-title">' + escape(c.label) + '</div><div class="db-row-sub">' + escape(c.value) + '</div>' + fix + '</div>' +
                    '<span class="db-row-status">' + levels[c.level][1] + '</span></li>');
            });
            $('.js-token-box').remove();
            $('.js-check').attr('hidden', false);
            $('[data-step=1] .js-next').attr('disabled', !data.passed);
        });
    });

    // Step 2：選 guard 帶出 model；改 model 帶出資料表、主鍵型別與欄位
    function loadModel(model) {
        $.get(urls.model, { model: model }).done(function (info) {
            $('[name=users_table]').val(info.table);
            $('[name=user_key_type][value=' + info.key_type + ']').prop('checked', true);
            var $name = $('.js-name-column').empty();
            info.columns.forEach(function (c) { $name.append($('<option>').val(c).text(c)); });
            $name.val(info.name_column);
        });
    }
    $('.js-guard').on('change', function () {
        var model = $(this).find(':selected').data('model');
        $('.js-model').val(model);
        loadModel(model);
        // 中介層跟著 guard：web 用 auth，其他用 auth:guard
        var guard = $(this).val();
        $('.js-middleware').val(guard === 'web' ? 'web,auth' : 'web,auth:' + guard);
    });
    $('.js-model').on('change', function () { loadModel($(this).val()); });
    $('.js-custom-layout').on('focus', function () { $('[name=layout_mode][value=custom]').prop('checked', true); });

    // Step 3：網址預覽
    function previewPrefix() {
        var prefix = $('.js-prefix').val().replace(/^\/+|\/+$/g, '');
        $('.js-prefix-preview').text(appUrl + '/' + (prefix ? prefix + '/' : '') + 'document_builder');
    }
    $('.js-prefix').on('input', previewPrefix);
    previewPrefix();

    function settings() {
        var custom = $('[name=layout_mode]:checked').val() === 'custom';
        return {
            guard: $('[name=guard]').val(),
            user_model: $('[name=user_model]').val(),
            users_table: $('[name=users_table]').val(),
            user_key_type: $('[name=user_key_type]:checked').val(),
            user_name_column: $('[name=user_name_column]').val(),
            layout: custom ? $('.js-custom-layout').val() : packageLayout,
            route: { prefix: $('[name=route_prefix]').val(), name: $('[name=route_name]').val(), middleware: $('[name=route_middleware]').val() },
            integration: $('[name=integration]:checked').val(),
            theme: { primary: $('[name=theme_primary]:checked').val() === 'custom' ? $('.js-theme-hex').val() : $('[name=theme_primary]:checked').val() }
        };
    }
    $('[name=theme_primary]').on('change', function () {
        $('.js-theme-hex').prop('disabled', $(this).val() !== 'custom');
    });
    $('.js-save-settings').on('click', function () {
        $.ajax({ url: urls.settings, type: 'POST', contentType: 'application/json', data: JSON.stringify(settings()) })
            .done(function (data) { nextUrl = data.url; go(4); });
    });

    // Step 4：依序執行，每一項顯示進度
    function setTask(task, cls, text) {
        $('.js-tasks [data-task=' + task + ']').attr('class', 'db-row ' + cls).find('.db-row-status').text(text);
    }
    function log(text) {
        var $log = $('.js-log');
        $log.text(($log.text() === '尚未執行' ? '' : $log.text() + '\n') + text);
    }
    $('.js-run').on('click', function () {
        var $button = $(this).attr('disabled', true);
        var tasks = ['migrate', 'admin', 'config'];
        (function next(i) {
            if (i >= tasks.length) {
                $button.attr('hidden', true);
                $('.js-finish').attr('hidden', false);
                $('[data-step=4] .js-prev').attr('disabled', true);
                return;
            }
            var task = tasks[i];
            setTask(task, 'is-run', '進行中…');
            $.post(urls.run.replace('___TASK___', task)).done(function (data) {
                log('[' + task + '] ' + data.log);
                if (data.url) nextUrl = data.url;
                if (data.ok === false && data.config) {
                    setTask(task, 'is-warn', '部分完成');
                    $('.js-config').text(data.config);
                    $('.js-manual-config').attr('hidden', false);
                } else {
                    setTask(task, 'is-ok', '已完成');
                }
                next(i + 1);
            }).fail(function () {
                setTask(task, 'is-error', '失敗');
                $button.attr('disabled', false).text('重試');
            });
        })(0);
    });
    $('.js-copy-config').on('click', function () {
        navigator.clipboard.writeText($('.js-config').text()).then(function () {
            Swal.fire({ icon: 'success', text: '已複製', timer: 1200, showConfirmButton: false });
        });
    });
    // 重新打開精靈的完成頁（安裝後才有範本與管理員名單）；路由前綴改了就換到新網址
    $('.js-finish').on('click', function () {
        var target = new URL(nextUrl || location.href, location.href);
        if (target.pathname === location.pathname) {
            location.hash = 'done';
            location.reload();
        } else {
            location.href = target.pathname + '#done';
        }
    });

    // Step 5
    $('.js-sample').on('click', function () {
        var $button = $(this).attr('disabled', true);
        $.post($button.data('url')).done(function (data) {
            Swal.fire({ icon: 'success', text: data.message }).then(function () { location.href = data.url; });
        }).fail(function () { $button.attr('disabled', false); });
    });
    $('.js-user-search').each(function () {
        var $select = $(this);
        $select.select2({
            width: '100%',
            placeholder: $select.data('placeholder'),
            ajax: {
                url: $select.data('url'),
                data: function (params) { return { search: params.term }; },
                processResults: function (data) { return { results: data.map(function (u) { return { id: u.id, text: u.name }; }) }; }
            }
        });
    });
    function reloadDone(data) {
        Swal.fire({ icon: 'success', text: data.message }).then(function () { location.hash = 'done'; location.reload(); });
    }
    $('.js-add-admin').on('submit', function (e) {
        e.preventDefault();
        $.post($(this).data('url'), { user_id: $(this).find('[name=user_id]').val() }).done(reloadDone);
    });
    $('.js-remove-admin').on('click', function () {
        var url = $(this).data('url');
        Swal.fire({ icon: 'warning', text: '確定移除這位超級管理員？', showCancelButton: true, confirmButtonText: '確定', cancelButtonText: '取消' })
            .then(function (result) {
                if (result.isConfirmed) $.ajax({ url: url, type: 'DELETE' }).done(reloadDone);
            });
    });

    // 已完成安裝的超級管理員直接看完成頁，可以點步驟導航回去調整設定或套用更新
    go(installed || location.hash === '#done' ? 5 : 1);
    if (installed) {
        $('.db-stepper li').css('cursor', 'pointer').on('click', function () { go(+$(this).data('step')); });
    }
});
</script>
@endpush
