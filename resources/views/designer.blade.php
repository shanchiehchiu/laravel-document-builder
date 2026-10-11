{{-- 表單設計器：沿用 WebFoundry 的編輯器外殼（全螢幕獨立版型，不套後台 main：後台全域 JS 會干擾畫布） --}}
@php
    $asset = fn (string $path): string => \DocumentBuilder\Support\Assets::url($path);
    $favicon = \DocumentBuilder\Support\Assets::external(config('document_builder.assets.favicon'));
    $vendor = 'vendor/grapesjs-0.23.6';
@endphp
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>設計單據｜{{ $config['settings']['name'] }}</title>
    @if($favicon)<link rel="icon" href="{{ $favicon }}">@endif
    <link rel="stylesheet" href="{{ $asset($vendor.'/grapes.min.css') }}">
    <link rel="stylesheet" href="{{ $asset('css/builder.css') }}">
    <link rel="stylesheet" href="{{ $asset('designer.css') }}">
</head>
<body>
<div class="wfb" id="wfb">
    <header class="wfb-top">
        <div class="wfb-top-left">
            {{-- 全域選單：設計器不再是孤島，可直接回到單據建置的其他頁面 --}}
            <div class="wfb-menu" id="wfb-nav">
                <button type="button" class="wfb-icon-btn" id="wfb-nav-btn" title="單據建置選單" aria-haspopup="true" aria-expanded="false" aria-controls="wfb-nav-list">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="wfb-menu-list" id="wfb-nav-list" role="menu" hidden>
                    <a role="menuitem" href="{{ $config['urls']['home'] }}" data-leave>我的單據</a>
                    <a role="menuitem" href="{{ $config['urls']['back'] }}" data-leave>單據設定列表</a>
                    <a role="menuitem" href="{{ $config['urls']['sources'] }}" target="_blank" rel="noopener">來源庫 <span class="wfb-ext" aria-hidden="true">↗</span><span class="wfb-sr">（另開分頁）</span></a>
                </div>
            </div>
            <div class="wfb-doc">
                <div class="wfb-site-name"><a href="{{ $config['urls']['back'] }}" data-leave>單據設定</a> /</div>
                <div class="wfb-doc-row">
                    {{-- 單據切換：直接跳到其他單據的設計器，不必先回列表 --}}
                    <div class="wfb-menu" id="wfb-switch">
                        <button type="button" class="wfb-doc-switch" id="wfb-switch-btn" title="切換到其他單據" aria-haspopup="true" aria-expanded="false" aria-controls="wfb-switch-list">
                            <strong id="dbd-type-name">{{ $config['settings']['name'] }}</strong>
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="wfb-menu-list" id="wfb-switch-list" role="menu" hidden>
                            @foreach($config['switcher'] as $item)
                            <a role="menuitem" href="{{ $item['url'] }}" data-leave @class(['is-current' => (int) $item['id'] === (int) $type->id])>
                                {{ $item['name'] }}@unless($item['enabled'])<span class="wfb-menu-note">停用</span>@endunless
                            </a>
                            @endforeach
                        </div>
                    </div>
                    <span class="wfb-rev @if($config['revisionInfo']['published']) is-published @endif" id="wfb-rev">{{ $config['revisionInfo']['label'] }}</span>
                </div>
            </div>
            <a class="wfb-btn wfb-btn-ghost wfb-sources-link" href="{{ $config['urls']['sources'] }}" target="_blank" rel="noopener" title="在新分頁打開來源庫；新增來源後回到這裡重新整理即可使用">來源庫 <span aria-hidden="true">↗</span></a>
        </div>

        <div class="wfb-top-center">
            <div class="wfb-seg">
                <button type="button" id="wfb-undo" title="復原（Ctrl+Z）">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>
                </button>
                <button type="button" id="wfb-redo" title="重做（Ctrl+Shift+Z）">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 14 5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/></svg>
                </button>
            </div>
            <div class="wfb-seg wfb-mode" role="group" aria-label="編輯或預覽">
                <button type="button" id="wfb-mode-edit" class="is-active" aria-pressed="true">編輯</button>
                <button type="button" id="wfb-mode-preview" aria-pressed="false">預覽</button>
            </div>
        </div>

        <div class="wfb-top-right">
            <div class="wfb-group">
                <a class="wfb-btn" href="{{ $config['urls']['revisions'] }}">版本紀錄</a>
                @if($config['canPublish'])
                <span class="wfb-publish-wrap" id="dbd-publish-wrap" @if(! $config['revisionInfo']['canPublish']) title="{{ $config['revisionInfo']['publishReason'] }}" @endif>
                    <button type="button" class="wfb-btn" id="dbd-publish" @disabled(! $config['revisionInfo']['canPublish'])>發布版本</button>
                </span>
                @endif
            </div>
            <div class="wfb-group">
                <button type="button" class="wfb-btn" id="wfb-fill-dummy" hidden title="只在畫面上填入範例文字，不會儲存，用來檢查長文字會不會撐破版面">填入範例資料</button>
                <button type="button" class="wfb-icon-btn wfb-icon-btn-line" id="dbd-open-form" title="另開新分頁預覽填單畫面（不會建立或儲存單據）" aria-label="另開新分頁預覽填單畫面">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h6v6"/><path d="M20 4 11 13"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
                </button>
            </div>
            <div class="wfb-group wfb-group-save">
                <span class="wfb-save-state" id="wfb-save-state" role="status" aria-live="polite"></span>
                <button type="button" class="wfb-btn wfb-btn-primary" id="wfb-save" title="儲存版面與單據設定（Ctrl+S）" disabled>儲存</button>
            </div>
        </div>
    </header>

    <div class="wfb-main">
        <aside class="wfb-left">
            <div class="wfb-tabs"><button type="button" class="is-active">元件</button></div>
            <div class="wfb-panel is-active">
                <div class="wfb-scroll" id="wfb-blocks"></div>
            </div>
        </aside>

        <main class="wfb-canvas-wrap">
            <div id="wfb-canvas"></div>
        </main>

        <aside class="wfb-right">
            <div class="wfb-selected">
                <div class="wfb-selected-name" id="wfb-selected-name">整張單據</div>
                <button type="button" class="wfb-icon-btn wfb-help-btn" id="wfb-help-btn" title="操作說明" aria-label="操作說明" aria-expanded="false" aria-controls="wfb-help">?</button>
            </div>
            <div class="wfb-tips wfb-help-pop" id="wfb-help" hidden>
                <b>怎麼用</b>
                <ul>
                    <li>點最上面的標題列：改單據名稱、開關新增／修改／刪除／上一筆下一筆。</li>
                    <li>沒有選取任何元件時，這裡就是整張單據的設定：單號格式、選單排序、啟用或停用。</li>
                    <li>單號可選每日、每月或每年歸零，下方會即時預覽下一張的範例單號。</li>
                    <li>從左邊把欄位拖到畫面上；放在兩列之間會自動多一列。</li>
                    <li>點一下畫面上的欄位，在這裡改名稱、必填、欄寬。</li>
                    <li>選取表頭欄位可以設定欄位變更連動；只接受另一欄的允許固定值。</li>
                    <li>一列共 12 格寬：1/2 是 6 格、1/3 是 4 格、整列是 12 格。</li>
                    <li>刪掉的欄位會移到左邊「已封存」，資料不會不見，拖回來就恢復。</li>
                    <li>按「儲存」一次存版面與單據設定，填單畫面就會照這個版面顯示。</li>
                </ul>
            </div>
            <div class="wfb-tabs"><button type="button" class="is-active">設定</button></div>
            <div class="wfb-panel is-active">
                <div class="wfb-scroll">
                    <div id="wfb-traits"></div>
                    <section id="db-event-editor" class="db-event-editor" hidden aria-labelledby="db-event-title">
                        <h3 id="db-event-title">欄位變更連動</h3>
                        <p class="db-event-help">此欄位變更時，可以依條件將另一個欄位設為固定值。規則會在填單與預覽中執行。</p>
                        <label for="db-event-condition">觸發條件</label>
                        <select id="db-event-condition" class="db-event-control">
                            <option value="always">每次變更</option>
                            <option value="equals">等於指定值</option>
                            <option value="not_equals">不等於指定值</option>
                            <option value="is_empty">沒有填寫</option>
                        </select>
                        <div id="db-event-condition-value"></div>
                        <label for="db-event-target">更新欄位</label>
                        <select id="db-event-target" class="db-event-control"></select>
                        <div id="db-event-target-value"></div>
                        <div class="db-event-actions">
                            <button type="button" id="db-event-save" class="db-event-primary">套用連動</button>
                            <button type="button" id="db-event-remove">移除規則</button>
                        </div>
                        <p id="db-event-message" class="db-event-message" role="status" aria-live="polite"></p>
                    </section>
                </div>
            </div>
        </aside>
    </div>
</div>

<div class="wfb-toast" id="wfb-toast" role="status" aria-live="polite"></div>
<div class="wfb-loading" id="wfb-loading"><div class="wfb-spinner"></div><span>正在載入設計器…</span></div>

<script>
    window.DB_DESIGNER = @json($config);
</script>
<script src="{{ $asset($vendor.'/grapes.min.js') }}"></script>
<script src="{{ $asset('js/wf-i18n.js') }}"></script>
{{-- 欄位代碼自動取名：瀏覽器沒有內建翻譯時退回拼音（MIT） --}}
<script src="{{ $asset('vendor/pinyin-pro-3.29.4/pinyin-pro.min.js') }}"></script>
<script src="{{ $asset('designer.js') }}"></script>
</body>
</html>
