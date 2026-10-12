/*!
 * 20261009 單據建置：表單設計器
 *
 * 外殼沿用 WebFoundry 編輯器（builder.css 的 wfb-*），GrapesJS 只當拖拉介面：
 * - 畫布最上面是固定的 db-head（標題列與按鈕：改單據名稱、開關按鈕）與 db-date（單據日期，固定欄位），
 *   不能移動或刪除；下面是 db-row（一列，.row）裝 db-cell（一格，.col-md-N）。
 * - 點畫布空白處選到整張單據（wrapper），設定選單排序與狀態。
 * - 每格的外觀由屬性即時畫出（文字一律跳脫），不是子元件，所以一格只會有一個東西。
 * - db-table（明細表）獨佔一列、直接放在最外層；欄位拖進明細表就變成表身的一欄。
 *   既有的表身欄位只能留在原本的明細表、表頭欄位不能拖進明細表（舊單據存的值才對得上），規則集中在 canPlace()。
 * - 存檔只送「列 → 格 → 欄位定義」結構與單據設定，不送 HTML；後端驗證後回傳新的畫布元件整個換掉。
 */
(function () {
    'use strict';

    var C = window.DB_DESIGNER;
    var REGISTRY = C.registry;
    var COMPONENTS = REGISTRY.components;
    var FIELD_TYPE_DEFS = REGISTRY.field_types;
    var $ = function (sel) { return document.querySelector(sel); };

    var ROW = 'db-row';
    var CELL = 'db-cell';
    var HEAD = 'db-head';
    var DATE = 'db-date';
    var TABLE = 'db-table';
    // 元件屬性不能用 name、status：GrapesJS 自己用它們存圖層名稱與選取狀態
    var BUTTON_PROP = function (key) { return 'btn_' + key; };
    var ARCHIVED = '已封存';
    // 一格可能帶的屬性（存檔、記住已封存欄位時用）
    var PROPS = ['kind', 'width', 'fieldId', 'key', 'label', 'ftype', 'required', 'showInList', 'choices', 'layout', 'decimals', 'currency', 'source', 'text', 'bg', 'border', 'tableKey', 'sum'];
    var KEY_RE = /^[a-z][a-z0-9_]{0,49}$/;
    var COLOR_RE = /^#[0-9a-f]{6}$/;
    var DARK_TEXT = '#1f2937';
    C.fieldTypes = Object.keys(FIELD_TYPE_DEFS).reduce(function (labels, type) {
        labels[type] = FIELD_TYPE_DEFS[type].label;
        return labels;
    }, {});
    var KIND_NAMES = ['heading', 'note', 'divider', 'spacer'].reduce(function (labels, type) {
        labels[type] = COMPONENTS[type].label;
        return labels;
    }, {});

    /* ───────────────────────── 小工具 ───────────────────────── */

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function nowText() {
        var d = new Date();
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function numberDate(date, format) {
        var year = String(date.getFullYear());
        var month = ('0' + (date.getMonth() + 1)).slice(-2);
        var day = ('0' + date.getDate()).slice(-2);
        if (format === 'Ymd') return year + month + day;
        if (format === 'Ym') return year + month;
        return year;
    }

    function numberExample(component) {
        var format = component.get('noDateFormat') || 'Ymd';
        var digits = parseInt(component.get('noSeqDigits'), 10) || 3;
        return String(component.get('noPrefix') || '')
            + numberDate(new Date(), format)
            + String('1').padStart(digits, '0');
    }

    var toastTimer;
    function toast(message, type) {
        var el = $('#wfb-toast');
        el.className = 'wfb-toast is-show' + (type ? ' is-' + type : '');
        el.textContent = message;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.classList.remove('is-show'); }, type === 'error' ? 8000 : 3200);
    }

    function setSaveState(text, type) {
        var el = $('#wfb-save-state');
        el.textContent = text;
        el.className = 'wfb-save-state' + (type ? ' is-' + type : '');
    }

    /* 頂部狀態：已儲存（灰點＋時間，儲存鈕停用）／有未儲存的修改（橘點，儲存鈕可按） */
    function markSaved(timeText) {
        setSaveState(timeText ? '已儲存 ' + timeText : '已儲存', 'saved');
        $('#wfb-save').disabled = true;
    }

    function markDirty() {
        setSaveState('有未儲存的修改', 'dirty');
        $('#wfb-save').disabled = false;
        // 每次儲存都會產生新草稿，新草稿要重新審核，所以有修改時一定不能直接發布
        setPublishState(false, '有未儲存的修改；儲存後會產生新草稿，必須重新審核後才可以發布');
    }

    function setPublishState(ready, reason) {
        var button = $('#dbd-publish');
        if (!button) return;
        button.disabled = !ready;
        var wrap = $('#dbd-publish-wrap');
        // 停用的按鈕收不到滑鼠事件，原因放在外層的 title 才看得到
        if (ready) wrap.removeAttribute('title');
        else wrap.title = reason || '';
    }

    function applyRevisionInfo(info) {
        if (!info) return;
        C.revisionInfo = info;
        var rev = $('#wfb-rev');
        rev.textContent = info.label;
        rev.classList.toggle('is-published', !!info.published);
        setPublishState(info.canPublish, info.publishReason);
    }

    function request(url, method, json) {
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': C.csrf
            },
            body: JSON.stringify(json)
        }).then(function (res) {
            return res.text().then(function (text) {
                var data = {};
                try { data = text ? JSON.parse(text) : {}; } catch (e) { data = {}; }
                if (res.ok) return data;

                var msg = data.message || ('伺服器錯誤（' + res.status + '）');
                if (data.errors) {
                    msg = Object.keys(data.errors).map(function (k) { return [].concat(data.errors[k]).join('\n'); }).join('\n');
                }
                if (res.status === 419) msg = '登入已逾時或頁面停留太久，請重新整理頁面';
                throw new Error(msg);
            });
        });
    }

    function width(cell) {
        return parseInt(cell.get('width'), 10) || 12;
    }

    function inTable(c) {
        var parent = c && c.parent && c.parent();
        return !!parent && parent.get('type') === TABLE;
    }

    // 欄位代碼在「表頭」與「表身」各自不能重複
    function sectionOf(c) {
        return inTable(c) ? 'item' : 'header';
    }

    // 拖放檢查時，元件庫的來源可能還是純物件（方塊內容），兩種都要能讀
    function attr(c, name) {
        if (!c) return null;
        return c.get ? c.get(name) : c[name];
    }

    function typeOf(c) {
        return attr(c, 'type');
    }

    // 拖放規則：src 是被拖的元件、trg 是要放進去的容器。
    // 已存檔的表身欄位只能留在原本的明細表；已存檔的表頭欄位不能進明細表；新欄位哪裡都能放。
    function canPlace(src, trg) {
        var st = typeOf(src);
        var tt = typeOf(trg);
        if (!st || !tt) return true;
        if (st === TABLE || st === ROW) return tt === 'wrapper';
        if (st !== CELL) return false;

        var saved = !!attr(src, 'fieldId');
        var srcTable = attr(src, 'tableKey');
        if (tt === TABLE) {
            if (attr(src, 'kind') !== 'field') return false;
            return !saved || (!!srcTable && srcTable === attr(trg, 'tableKey'));
        }
        if (tt === ROW || tt === 'wrapper') return !(saved && srcTable);
        return false;
    }

    function pick(cell) {
        var out = {};
        PROPS.forEach(function (p) { out[p] = cell.get(p); });
        return out;
    }

    /* ───────────────────────── 每格的預覽（與填單畫面同樣的 markup） ───────────────────────── */

    function fieldPreview(m) {
        var type = m.get('ftype');
        var label = esc(m.get('label') || '未命名欄位');
        var star = m.get('required') && type !== 'checkbox' ? '<span class="text-danger">*</span>' : '';
        var item = inTable(m);
        var badges = [];
        if (!m.get('fieldId')) badges.push('<span class="db-badge is-new">新</span>');
        if (type === 'master') {
            // 來源的小圖示跟著放在標記上：面板只剩一張主檔關聯積木，來源的語意改由畫布上的欄位呈現
            var src = m.get('source');
            var srcIcon = src ? smallIcon(ICONS['m-' + src] ? 'm-' + src : 'm-default') : '';
            badges.push('<span class="db-badge is-master">' + srcIcon + (item ? '' : '主檔：') + esc(C.masters[src] || '未選') + '</span>');
        }
        // 「列表」只留小圖示，文字放在 title（畫布上每欄都有，全寫字會很吵）
        if (!item && m.get('showInList')) badges.push('<span class="db-badge is-list" title="顯示於列表" aria-label="顯示於列表">' + BADGE_TABLE_ICON + '</span>');

        var input;
        switch (type) {
            case 'textarea':
                input = '<textarea class="form-control" rows="3" readonly tabindex="-1"></textarea>';
                break;
            case 'number':
                var d = parseInt(m.get('decimals'), 10) || 0;
                input = '<input class="form-control" readonly tabindex="-1" placeholder="' + (d ? '0.' + new Array(d + 1).join('0') : '0') + '">';
                break;
            case 'amount':
                var ad = parseInt(m.get('decimals'), 10) || 0;
                var currency = String(m.get('currency') || '').trim();
                input = '<div class="input-group">' + (currency ? '<span class="input-group-text">' + esc(currency) + '</span>' : '')
                    + '<input class="form-control text-end" readonly tabindex="-1" placeholder="' + (ad ? '0.' + new Array(ad + 1).join('0') : '0') + '"></div>';
                break;
            case 'date':
                input = '<input type="date" class="form-control" readonly tabindex="-1">';
                break;
            case 'checkbox':
                input = '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" tabindex="-1">'
                    + '<label class="form-check-label">是</label></div>';
                break;
            case 'select':
                var choices = String(m.get('choices') || '').split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
                input = '<select class="form-select" tabindex="-1"><option>' + esc(choices.length ? choices.join('／') : '（尚未設定選項）') + '</option></select>';
                break;
            case 'multiselect':
                var multiChoices = String(m.get('choices') || '').split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
                input = '<select class="form-select" tabindex="-1" multiple><option>' + esc(multiChoices.length ? multiChoices.join('／') : '（尚未設定選項）') + '</option></select>';
                break;
            case 'checkgroup':
                var groupChoices = String(m.get('choices') || '').split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
                var groupVertical = m.get('layout') === 'vertical';
                input = '<div style="display:flex;flex-wrap:wrap;gap:12px;' + (groupVertical ? 'flex-direction:column;' : '') + '">'
                    + (groupChoices.length ? groupChoices : ['（尚未設定選項）']).map(function (c) {
                        return '<span style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" tabindex="-1" disabled> ' + esc(c) + '</span>';
                    }).join('') + '</div>';
                break;
            case 'radio':
                var radioChoices = String(m.get('choices') || '').split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
                var vertical = m.get('layout') === 'vertical';
                input = '<div style="display:flex;flex-wrap:wrap;gap:12px;' + (vertical ? 'flex-direction:column;' : '') + '">'
                    + (radioChoices.length ? radioChoices : ['（尚未設定選項）']).map(function (c) {
                        return '<span style="display:inline-flex;align-items:center;gap:4px"><input type="radio" tabindex="-1" disabled> ' + esc(c) + '</span>';
                    }).join('') + '</div>';
                break;
            case 'master':
                input = '<select class="form-select" tabindex="-1"><option>搜尋' + label + '</option></select>';
                break;
            default:
                input = '<input class="form-control" readonly tabindex="-1">';
        }

        if (item) {
            // 明細表的一欄：表頭一格欄位名稱、下面一格示範輸入框，有合計就加一格合計
            var sum = type === 'number' && m.get('sum');
            return '<div class="db-th"><span>' + label + star + '</span><span class="db-badges">' + badges.join('') + '</span></div>'
                + '<div class="db-td">' + input + '</div>'
                + '<div class="db-tf">' + (sum ? '合計' : '') + '</div>';
        }

        return '<div class="db-badges">' + badges.join('') + '</div>'
            + '<label class="form-label">' + label + star + '</label>' + input;
    }

    // 與 FormLayoutService::headingStyle() 同一個公式：字色依 WCAG 對比自動挑白字或深色字
    function luminance(hex) {
        var weights = [0.2126, 0.7152, 0.0722];
        return [1, 3, 5].reduce(function (sum, at, i) {
            var c = parseInt(hex.substr(at, 2), 16) / 255;
            return sum + weights[i] * (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
        }, 0);
    }

    function contrast(a, b) {
        var la = luminance(a);
        var lb = luminance(b);
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    }

    function headingStyle(bg, border) {
        bg = COLOR_RE.test(bg || '') ? bg : '';
        border = COLOR_RE.test(border || '') ? border : '';
        if (!bg && !border) return '';
        if (!bg) return 'border: 1px solid ' + border;
        var text = contrast(bg, '#ffffff') >= contrast(bg, DARK_TEXT) ? '#ffffff' : DARK_TEXT;
        return 'background-color: ' + bg + '; color: ' + text + '; border: 1px solid ' + (border || bg);
    }

    function preview(m) {
        switch (m.get('kind')) {
            case 'heading':
                var style = headingStyle(m.get('bg'), m.get('border'));
                return style
                    ? '<h4 class="fs-5 fw-semibold rounded px-3 py-2 mb-0" style="' + style + '">' + esc(m.get('text')) + '</h4>'
                    : '<h4 class="fs-5 fw-semibold border-bottom pb-2 mb-0">' + esc(m.get('text')) + '</h4>';
            case 'note':
                return '<p class="text-muted mb-0" style="white-space: pre-line">' + esc(m.get('text')) + '</p>';
            case 'divider':
                return '<hr class="db-divider">';
            case 'spacer':
                return '<div class="db-spacer">空白格</div>';
            default:
                return fieldPreview(m);
        }
    }

    // 標題列：和檢視頁的標題列同樣的按鈕；關掉的按鈕變淡並劃掉
    // 畫布徽章用的小圖示（12px，沿用 stroke 風格）
    var BADGE_TABLE_ICON = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="M3 10h18M3 14.5h18M9 5v14"/></svg>';
    var BADGE_LOCK_ICON = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';

    function headPreview(m) {
        var on = function (key) { return m.get(BUTTON_PROP(key)) ? '' : ' db-off'; };
        // 前台動作列的示意：用和填單頁相同的主題色 class，顏色交給宿主的佈景主題決定，畫面和實際填單頁一致
        return '<h3 class="block-title">' + esc(m.get('docName') || '未命名單據') + '</h3>'
            + '<span class="btn btn-primary db-preview-btn me-2' + on('create') + '">新增</span>'
            + '<span class="btn btn-warning db-preview-btn me-2' + on('edit') + '">修改</span>'
            + '<span class="btn btn-danger db-preview-btn me-2' + on('delete') + '" title="刪除在列表的每一列上">刪除</span>'
            + '<span class="btn btn-secondary db-preview-btn">返回列表</span>'
            + '<span class="btn-group ms-2' + on('navigation') + '"><span class="btn btn-outline-secondary db-preview-btn">← 上一筆</span>'
            + '<span class="btn btn-outline-secondary db-preview-btn">下一筆 →</span></span>';
    }

    // 固定的第一列：單號（存檔時自動產生）＋單據日期，和填單頁的第一列一樣
    function fixedRowPreview(example) {
        var lock = '<div class="db-badges"><span class="db-badge is-fixed" title="固定欄位" aria-label="固定欄位">' + BADGE_LOCK_ICON + '</span></div>';
        return '<div class="col-md-4 db-cell">' + lock
            + '<label class="form-label">單號</label>'
            + '<div class="form-control-plaintext text-muted">存檔時自動產生' + (example ? '，例如 ' + esc(example) : '') + '</div></div>'
            + '<div class="col-md-4 db-cell">' + lock
            + '<label class="form-label">單據日期<span class="text-danger">*</span></label>'
            + '<input type="date" class="form-control" readonly tabindex="-1"></div>';
    }

    /* ───────────────────────── 右側設定（依格子種類不同） ───────────────────────── */

    function wrapperTraits() {
        var list = [
            { type: 'db-section', name: 'secBasic', text: '基本資訊' },
            { type: 'text', name: 'docName', label: '單據名稱', changeProp: true },
            { type: 'db-info', name: 'docCode', label: '單據代碼', text: C.settings.code, hint: '用在網址，建立後不得修改' },
            { type: 'number', name: 'docSeq', label: '選單排序', changeProp: true, min: 0, step: 1 },
            { type: 'checkbox', name: 'docStatus', label: '啟用單據', changeProp: true },
            { type: 'db-info', name: 'statusNote', label: '說明', note: true, text: '停用後側選單隱藏，單據無法進入。' },
            { type: 'db-section', name: 'secNumbering', text: '編碼規則' },
            { type: 'text', name: 'noPrefix', label: '單號前綴', changeProp: true, maxLength: 20 },
            {
                type: 'select', name: 'noDateFormat', label: '日期格式與歸零週期', changeProp: true,
                options: [
                    { id: 'Ymd', label: '每日（年月日）' },
                    { id: 'Ym', label: '每月（年月）' },
                    { id: 'Y', label: '每年（年）' }
                ]
            },
            { type: 'number', name: 'noSeqDigits', label: '流水號補零位數', changeProp: true, min: 1, max: 12, step: 1 },
            { type: 'db-number-example', name: 'noExample', label: '單號範例' },
            { type: 'db-section', name: 'secActions', text: '前台功能權限' }
        ];
        Object.keys(C.buttonLabels).forEach(function (key) {
            list.push({ type: 'checkbox', name: BUTTON_PROP(key), label: C.buttonLabels[key], changeProp: true });
        });
        list.push({ type: 'db-info', name: 'buttonsNote', label: '權限說明', note: true, text: '「檢視」與「返回列表」固定開放。關閉的操作會連網址一起擋下，不只是隱藏；「刪除」按鈕在列表的每一列上。' });
        return list;
    }


    function widthTrait() {
        return {
            // 選項固定只有四種，用分段按鈕一眼看完、點一下就改
            type: 'db-radio', name: 'width', label: '欄寬', changeProp: true,
            options: Object.keys(C.widths).map(function (k) { return { id: k, label: C.widths[k], title: k + ' 格' }; })
        };
    }

    function traitsFor(m) {
        var kind = m.get('kind');
        if (kind === 'heading') {
            return [
                { type: 'text', name: 'text', label: '標題文字', changeProp: true },
                widthTrait(),
                { type: 'db-color', name: 'bg', label: '背景顏色', changeProp: true },
                { type: 'db-color', name: 'border', label: '外框顏色', changeProp: true },
                { type: 'db-info', name: 'colorNote', label: '說明', note: true, text: '文字顏色依背景自動用白字或深色字。只設外框時沒有底色；只設背景時外框同背景色。' }
            ];
        }
        if (kind === 'note') return [{ type: 'db-textarea', name: 'text', label: '說明文字', changeProp: true }, widthTrait()];
        if (kind === 'spacer' || kind === 'divider') return [widthTrait()];

        // 明細表裡的欄位：沒有欄寬（表格一欄）與「顯示在列表」（列表只列表頭欄位）；數字欄可勾合計
        var item = inTable(m);
        var type = m.get('ftype');
        var list = [
            { type: 'db-info', name: 'ftype', label: '型別', text: C.fieldTypes[type], hint: '建立後不得修改；換型別必須刪除後重新拖入' },
            { type: 'text', name: 'label', label: '欄位名稱', changeProp: true }
        ];
        if (!item) list.push(widthTrait());
        if (type !== 'checkbox') list.push({ type: 'checkbox', name: 'required', label: item ? '必填（每一列）' : '必填', changeProp: true });
        if (!item) list.push({ type: 'checkbox', name: 'showInList', label: '顯示在列表', changeProp: true });

        if (type === 'select' || type === 'radio' || type === 'checkgroup' || type === 'multiselect') {
            list.push({ type: 'db-textarea', name: 'choices', label: '選項（一行一個）', changeProp: true });
        }
        if (type === 'radio' || type === 'checkgroup') {
            list.push({ type: 'select', name: 'layout', label: '排列方向', changeProp: true, options: [{ id: 'horizontal', label: '水平' }, { id: 'vertical', label: '垂直' }] });
        }
        if (type === 'amount') {
            list.push({ type: 'number', name: 'decimals', label: '小數位數', changeProp: true, min: 0, max: 6, step: 1 });
            list.push({ type: 'text', name: 'currency', label: '幣別符號（選填，例如 NT$）', changeProp: true });
        }
        if (type === 'number') {
            list.push({ type: 'number', name: 'decimals', label: '小數位數', changeProp: true, min: 0, max: 6, step: 1 });
            if (item) list.push({ type: 'checkbox', name: 'sum', label: '顯示合計', changeProp: true });
        }
        if (type === 'master') {
            if (m.get('fieldId') && C.hasRecords) {
                list.push({ type: 'db-info', name: 'source', label: '主檔來源', text: C.masters[m.get('source')] || '', hint: '已有單據資料，不得修改' });
            } else {
                list.push({
                    type: 'select', name: 'source', label: '主檔來源', changeProp: true,
                    options: Object.keys(C.masters).map(function (k) { return { id: k, label: C.masters[k] }; })
                });
            }
        }
        // 新欄位可以自訂代碼（預設依欄位名稱自動產生）；存檔後鎖定，因為舊單據資料以代碼為鍵
        if (!m.get('fieldId')) list.push({ type: 'db-key', name: 'key', label: '欄位代碼', changeProp: true });
        else if (m.get('key')) list.push({ type: 'db-info', name: 'key', label: '欄位代碼', text: m.get('key'), hint: '建立後不得修改' });

        return list;
    }

    function tableTraits(m) {
        return [
            { type: 'text', name: 'title', label: '明細表名稱', changeProp: true },
            m.get('tableKey')
                ? { type: 'db-info', name: 'tableKey', label: '明細表代碼', text: m.get('tableKey'), hint: '建立後不得修改' }
                : { type: 'db-info', name: 'tableKeyNote', label: '明細表代碼', note: true, text: '存檔時自動編號' },
            { type: 'db-info', name: 'tableNote', label: '說明', note: true, text: '把欄位拖進表格成為一欄，左右拖曳調整順序。刪除明細表會把它的欄位一起封存，可以從「已封存」整張拖回。' }
        ];
    }

    // 欄位拖進或拖出明細表後設定項不同；選取時才換（換 traits 不算修改，也不進復原紀錄）
    function refreshTraits(c) {
        if (typeOf(c) !== CELL || c.get('kind') !== 'field') return;
        var want = sectionOf(c);
        if (c.traitsFor === want) return;
        c.traitsFor = want;
        um.skip(function () { c.setTraits(traitsFor(c)); });
    }

    /* ───────────────────────── 元件型別（plugin，要在載入畫布之前註冊） ───────────────────────── */

    var MOVE_ICON = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>';
    var COPY_ICON = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>';
    var ARROW_LEFT_ICON = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>';
    var ARROW_RIGHT_ICON = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
    var TRASH_ICON = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>';

    // 欄位就地操作列：拖曳移動、寬度快速切換、移除（移到已封存）
    function cellToolbar() {
        var items = [{ attributes: { title: '拖曳移動' }, label: MOVE_ICON, command: 'tlb-move' }];
        Object.keys(C.widths).forEach(function (w) {
            items.push({ attributes: { title: '寬度 ' + C.widths[w] }, label: C.widths[w], command: 'db-width-' + w });
        });
        items.push({ attributes: { title: '往左移' }, label: ARROW_LEFT_ICON, command: 'db-move-left' });
        items.push({ attributes: { title: '往右移' }, label: ARROW_RIGHT_ICON, command: 'db-move-right' });
        items.push({ attributes: { title: '複製' }, label: COPY_ICON, command: 'db-dup' });
        items.push({ attributes: { title: '移除（移到已封存）' }, label: TRASH_ICON, command: 'tlb-delete' });
        return items;
    }

    // 寬度只對畫布上的一般欄位有效；明細表的欄位是表格的一欄，寬度由表格決定
    function setCellWidth(cell, w) {
        if (!cell || typeOf(cell) !== CELL) return;
        if (inTable(cell)) { toast('明細表裡的欄位寬度由表格決定', 'error'); return; }
        cell.set('width', w);
    }

    // 複製：緊接在原格子後面放一格相同設定的新格子；代碼與 fieldId 清空，讓它成為新欄位（代碼會自動取一個不重複的）
    function duplicateCell(cell) {
        if (!cell || typeOf(cell) !== CELL) return;
        var copy = Object.assign(pick(cell), { type: CELL, fieldId: null, key: null });
        var added = cell.parent().components().add(copy, { at: cell.index() + 1 });
        editor.select(added);
    }

    // 左右移：在同一列（或同一明細表）內和前後一格交換位置，用按鈕代替拖放，明細表內的排序就不會落點錯誤
    function moveCell(cell, delta) {
        if (!cell || typeOf(cell) !== CELL) return;
        var parent = cell.parent();
        var index = cell.index();
        var to = index + delta;
        if (to < 0 || to >= parent.components().length) return;
        // GrapesJS 的 move 在同一父層內用「移動前」的索引：往右要給 index+2 才會落在下一格之後（給 index+1 會被當成原地不動）
        var at = delta > 0 ? index + 2 : index - 1;
        // GrapesJS 的 move 會檢查 draggable，明細表內的欄位平常不能拖；這裡只在移動的那一刻打開，移完還原（開關本身不進復原紀錄）
        var draggable = cell.get('draggable');
        um.skip(function () { cell.set('draggable', true); });
        cell.move(parent, { at: at });
        um.skip(function () { cell.set('draggable', draggable); });
        editor.select(cell);
    }

    function definePlugin(editor) {
        var dc = editor.DomComponents;
        var baseView = dc.getType('default').view;

        editor.Commands.add('db-dup', {
            run: function (ed) { duplicateCell(ed.getSelected()); }
        });
        editor.Commands.add('db-move-left', {
            run: function (ed) { moveCell(ed.getSelected(), -1); }
        });
        editor.Commands.add('db-move-right', {
            run: function (ed) { moveCell(ed.getSelected(), 1); }
        });

        Object.keys(C.widths).forEach(function (w) {
            editor.Commands.add('db-width-' + w, {
                run: function (ed) { setCellWidth(ed.getSelected(), parseInt(w, 10)); }
            });
        });

        dc.addType(ROW, {
            model: {
                defaults: {
                    tagName: 'div',
                    name: '一列',
                    classes: ['row', 'db-row'],
                    // 列只能放在最外層；列裡只能放格子
                    draggable: '[data-gjs-type=wrapper]',
                    droppable: function (src, trg) { return typeOf(src) === CELL && canPlace(src, trg); },
                    copyable: false,
                    stylable: false,
                    traits: []
                }
            }
        });

        dc.addType(CELL, {
            model: {
                defaults: {
                    tagName: 'div',
                    name: '一格',
                    classes: ['db-cell'],
                    // 格子可以放進列、最外層（自動包成新的一列）或明細表（只有欄位）
                    draggable: function (src, trg) { return !inTable(src) && canPlace(src, trg); },
                    droppable: false,
                    copyable: false,
                    stylable: false,
                    editable: false,
                    kind: 'field',
                    width: 6
                },
                init: function () {
                    this.traitsFor = sectionOf(this);
                    this.setTraits(traitsFor(this));
                    this.set('toolbar', cellToolbar());
                }
            },
            view: {
                init: function () {
                    this.listenTo(this.model, 'change:width', this.applyWidth);
                    this.listenTo(this.model, 'change:label change:required change:showInList change:choices change:decimals change:source change:text change:fieldId change:bg change:border change:sum', this.renderPreview);
                },
                onRender: function () {
                    this.applyWidth();
                    this.renderPreview();
                },
                // GrapesJS 依 model 的 classes 重寫 class 屬性時，欄寬類別要補回來
                updateClasses: function () {
                    baseView.prototype.updateClasses.apply(this, arguments);
                    this.applyWidth();
                },
                applyWidth: function () {
                    var cl = this.el.classList;
                    Array.prototype.slice.call(cl).forEach(function (c) { if (/^col-md-\d+$/.test(c)) cl.remove(c); });
                    cl.add('col-md-' + width(this.model));
                },
                renderPreview: function () {
                    this.model.previewFor = sectionOf(this.model);
                    this.el.innerHTML = preview(this.model);
                }
            }
        });

        // 標題列與按鈕：固定在最上面，只能點選改設定
        dc.addType(HEAD, {
            model: {
                defaults: {
                    tagName: 'div',
                    name: '標題列',
                    classes: ['block-header', 'block-header-default', 'db-head'],
                    draggable: false, droppable: false, removable: false, copyable: false,
                    stylable: false, editable: false, layerable: false
                }
            },
            view: {
                init: function () {
                    var props = ['docName'].concat(Object.keys(C.buttonLabels).map(BUTTON_PROP));
                    this.listenTo(this.model, props.map(function (p) { return 'change:' + p; }).join(' '), this.renderPreview);
                },
                onRender: function () { this.renderPreview(); },
                renderPreview: function () { this.el.innerHTML = headPreview(this.model); }
            }
        });

        // 單據日期：實體欄位（列表依它排序篩選），不是自訂欄位，只顯示不能動
        dc.addType(DATE, {
            model: {
                defaults: {
                    tagName: 'div',
                    name: '單號與單據日期',
                    classes: ['row', 'db-fixed'],
                    draggable: false, droppable: false, removable: false, copyable: false,
                    stylable: false, editable: false, layerable: false,
                    traits: [{ type: 'db-info', name: 'dateNote', label: '單號與單據日期', note: true, text: '固定欄位：單號在存檔時依單號格式自動產生；列表依單據日期排序與篩選。兩者都不得移動、刪除或改名。' }]
                }
            },
            view: {
                init: function () {
                    // 單號範例跟著整張單據的單號格式即時更新
                    var wrapper = editor.getWrapper();
                    if (wrapper) this.listenTo(wrapper, 'change:noPrefix change:noDateFormat change:noSeqDigits', this.onRender);
                },
                onRender: function () {
                    var wrapper = editor.getWrapper();
                    this.el.innerHTML = fixedRowPreview(wrapper ? numberExample(wrapper) : '');
                }
            }
        });

        // 明細表：外框（名稱）由自己畫，欄位是子元件、放在 .db-table-cols 裡
        dc.addType(TABLE, {
            model: {
                defaults: {
                    tagName: 'div',
                    name: '明細表',
                    classes: ['db-table'],
                    draggable: '[data-gjs-type=wrapper]',
                    droppable: function (src, trg) { return typeOf(src) === CELL && canPlace(src, trg); },
                    copyable: false,
                    stylable: false,
                    editable: false,
                    tableKey: '',
                    title: '明細表'
                },
                init: function () { this.setTraits(tableTraits(this)); }
            },
            view: {
                // 子元件容器要在渲染子元件之前就存在：GrapesJS 在 initialize 裡先呼叫 init 才 render
                init: function () {
                    this.el.innerHTML = '<div class="db-table-head"><span class="db-badge">明細表</span><span class="db-table-title"></span>'
                        + '<button type="button" class="db-table-more" hidden></button></div>'
                        + '<div class="db-table-scroll"><div class="db-table-body"><div class="db-table-no"><div class="db-th">#</div><div class="db-td">1</div><div class="db-tf"></div></div>'
                        + '<div class="db-table-cols"></div></div><div class="db-shade db-shade-l"></div><div class="db-shade db-shade-r"></div></div>'
                        + '<div class="db-table-foot">填單時可以新增、刪除列</div>';
                    this.listenTo(this.model, 'change:title', this.renderTitle);
                    this.bindScroll();
                },
                getChildrenSelector: function () { return '.db-table-cols'; },
                onRender: function () {
                    this.renderTitle();
                    // 掛進 iframe 之後才量得到寬度；ResizeObserver 要用 iframe 自己的 window 建立
                    var view = this;
                    requestAnimationFrame(function () { view.observeSize(); view.updateScroll(); });
                },
                renderTitle: function () {
                    this.el.querySelector('.db-table-title').textContent = this.model.get('title') || '未命名明細表';
                },
                // 欄位多到超出框寬時：兩側陰影＋「還有 N 欄」提示，滾輪直向也能左右捲
                bindScroll: function () {
                    var view = this;
                    var body = this.el.querySelector('.db-table-body');
                    body.addEventListener('scroll', function () { view.updateScroll(); }, { passive: true });
                    body.addEventListener('wheel', function (e) {
                        if (e.shiftKey || Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
                        var max = body.scrollWidth - body.clientWidth;
                        // 已經捲到底就放行，讓畫布照常上下捲，不會卡在表格上
                        if (max <= 0 || (e.deltaY > 0 && body.scrollLeft >= max - 1) || (e.deltaY < 0 && body.scrollLeft <= 0)) return;
                        e.preventDefault();
                        body.scrollLeft += e.deltaY;
                        view.updateScroll();
                    }, { passive: false });
                    this.el.querySelector('.db-table-more').addEventListener('click', function (e) {
                        e.stopPropagation();
                        var target = this.dataset.dir === 'right' ? Math.min(body.scrollLeft + body.clientWidth * 0.8, body.scrollWidth - body.clientWidth) : 0;
                        var from = body.scrollLeft;
                        body.scrollTo({ left: target, behavior: 'smooth' });
                        // 分頁沒在繪製時平滑捲動不會動，沒動就直接跳到位
                        setTimeout(function () { if (body.scrollLeft === from) body.scrollLeft = target; view.updateScroll(); }, 400);
                    });
                },
                observeSize: function () {
                    var win = this.el.ownerDocument.defaultView;
                    if (this.ro || !win || !win.ResizeObserver) return;
                    var view = this;
                    this.ro = new win.ResizeObserver(function () {
                        if (!view.el.isConnected) { view.ro.disconnect(); view.ro = null; return; }
                        view.updateScroll();
                    });
                    this.ro.observe(this.el.querySelector('.db-table-body'));
                    this.ro.observe(this.el.querySelector('.db-table-cols'));
                },
                updateScroll: function () {
                    var body = this.el.querySelector('.db-table-body');
                    var wrap = this.el.querySelector('.db-table-scroll');
                    var more = this.el.querySelector('.db-table-more');
                    var max = body.scrollWidth - body.clientWidth;
                    var canLeft = max > 0 && body.scrollLeft > 1;
                    var canRight = max > 0 && body.scrollLeft < max - 1;
                    wrap.classList.toggle('can-left', canLeft);
                    wrap.classList.toggle('can-right', canRight);
                    if (canRight) {
                        // 右側沒有完整露出的欄數
                        var edge = body.getBoundingClientRect().right + 1;
                        var hidden = [].filter.call(this.el.querySelector('.db-table-cols').children, function (c) {
                            return c.getBoundingClientRect().right > edge;
                        }).length;
                        more.textContent = '還有 ' + hidden + ' 欄 →';
                        more.dataset.dir = 'right';
                        more.hidden = false;
                    } else if (canLeft) {
                        more.textContent = '← 回到開頭';
                        more.dataset.dir = 'left';
                        more.hidden = false;
                    } else {
                        more.hidden = true;
                    }
                }
            }
        });

        // 多行文字設定（GrapesJS 沒有內建 textarea trait）
        editor.TraitManager.addType('db-textarea', {
            createInput: function () {
                var el = document.createElement('textarea');
                el.rows = 4;
                el.className = 'db-trait-textarea';
                return el;
            },
            onEvent: function (o) { o.component.set(o.trait.get('name'), o.elInput.value); },
            onUpdate: function (o) { o.elInput.value = o.component.get(o.trait.get('name')) || ''; }
        });

        // 分段按鈕（選項少且固定時取代下拉）；change 事件會從內層 radio 冒泡到外層容器
        var radioSeq = 0;
        editor.TraitManager.addType('db-radio', {
            createInput: function (o) {
                var el = document.createElement('div');
                var group = 'db-radio-' + (++radioSeq);
                el.className = 'db-trait-radio';
                el.innerHTML = (o.trait.get('options') || []).map(function (opt) {
                    return '<label title="' + esc(opt.title || '') + '"><input type="radio" name="' + group + '" value="' + esc(opt.id) + '">'
                        + '<span>' + esc(opt.label) + '</span></label>';
                }).join('');
                return el;
            },
            onEvent: function (o) {
                var checked = o.elInput.querySelector('input:checked');
                if (checked) o.component.set(o.trait.get('name'), checked.value);
            },
            onUpdate: function (o) {
                var value = String(o.component.get(o.trait.get('name')));
                o.elInput.querySelectorAll('input').forEach(function (input) { input.checked = input.value === value; });
            }
        });

        // 顏色：色塊挑色 + 色碼輸入 + 清除；空字串代表不設定
        editor.TraitManager.addType('db-color', {
            createInput: function (o) {
                var el = document.createElement('div');
                el.className = 'db-trait-color';
                el.innerHTML = '<input type="color" class="db-color-pick" title="挑選顏色">'
                    + '<input type="text" class="db-color-text" maxlength="7" placeholder="未設定" spellcheck="false">'
                    + '<button type="button" class="db-trait-btn">清除</button>';
                el.querySelector('button').addEventListener('click', function () { o.component.set(o.trait.get('name'), ''); });
                return el;
            },
            onEvent: function (o) {
                var name = o.trait.get('name');
                var target = o.event && o.event.target;
                var raw = target && target.classList.contains('db-color-text')
                    ? o.elInput.querySelector('.db-color-text').value
                    : o.elInput.querySelector('.db-color-pick').value;
                var value = normalizeColor(raw);
                if (value === null) {
                    // 色碼打錯就還原成原本的值
                    this.onUpdate(o);
                    return;
                }
                o.component.set(name, value);
            },
            onUpdate: function (o) {
                var value = o.component.get(o.trait.get('name')) || '';
                o.elInput.classList.toggle('is-empty', !value);
                o.elInput.querySelector('.db-color-pick').value = value || '#ffffff';
                o.elInput.querySelector('.db-color-text').value = value;
            }
        });

        // 欄位代碼（只有還沒存檔的新欄位）：可手動輸入，或按「自動產生」依欄位名稱取名
        editor.TraitManager.addType('db-key', {
            createInput: function (o) {
                var el = document.createElement('div');
                el.className = 'db-trait-key';
                el.innerHTML = '<div class="db-key-row"><input type="text" class="db-key-input" maxlength="50" placeholder="留空存檔時自動編號" spellcheck="false">'
                    + '<button type="button" class="db-trait-btn">自動產生</button></div>'
                    + '<div class="db-info-hint db-key-hint"></div>'
                    + '<div class="db-info-hint db-key-extra"></div>';
                el.querySelector('button').addEventListener('click', function () { generateKey(o.component, true); });
                // 進度與來源不是 key 本身，changeProp 不會通知，自己聽
                var view = this;
                this.listenTo(o.component, 'change:keyBusy change:keyVia', function () { view.onUpdate({ elInput: el, component: o.component, trait: o.trait }); });
                return el;
            },
            onEvent: function (o) {
                var value = o.elInput.querySelector('.db-key-input').value.trim().toLowerCase();
                // 清空代表交還給自動產生
                o.component.set({ keyManual: value !== '', keyVia: value ? 'manual' : '' });
                o.component.set('key', value || null);
                if (!value) generateKey(o.component, false);
            },
            onUpdate: function (o) {
                var key = o.component.get('key') || '';
                var hint = o.elInput.querySelector('.db-key-hint');
                var bad = key && !KEY_RE.test(key);
                o.elInput.querySelector('.db-key-input').value = key;
                o.elInput.querySelector('button').disabled = !!o.component.get('keyBusy');
                o.elInput.querySelector('button').textContent = o.component.get('keyBusy') || '自動產生';
                hint.classList.toggle('is-error', !!bad);
                hint.textContent = bad
                    ? '只能用小寫英文、數字、底線，開頭須為英文'
                    : (KEY_VIA[o.component.get('keyVia')] || '') + '存檔後不得修改。';
                // 代碼命中主檔的附隨欄位：只提示，不鎖定；使用者仍可不放這個欄位
                var extra = o.elInput.querySelector('.db-key-extra');
                var matched = !bad && key && (C.masterExtraKeys || []).indexOf(key) > -1;
                extra.textContent = matched ? '💡 此代碼與主檔附隨欄位吻合，放在明細表中時，填單會由主檔自動帶入並轉為唯讀。' : '';
            }
        });

        // 分組標題：純展示，沒有值也不會被存檔；只用來把整張單據的設定分成幾個區塊
        editor.TraitManager.addType('db-section', {
            createInput: function (o) {
                var el = document.createElement('div');
                el.className = 'db-section-title';
                el.textContent = o.trait.get('text') || '';
                return el;
            },
            onEvent: function () {},
            onUpdate: function () {}
        });

        // 唯讀資訊：鎖定的值（型別、欄位代碼、主檔來源）值用深色、說明用灰色小字；note 是純說明文字，不加鎖頭
        editor.TraitManager.addType('db-info', {
            createInput: function (o) {
                var el = document.createElement('div');
                var hint = o.trait.get('hint');
                el.className = 'db-trait-info' + (o.trait.get('note') ? ' is-note' : '');
                el.innerHTML = '<div><div class="db-info-value">' + esc(o.trait.get('text') || '') + '</div>'
                    + (hint ? '<div class="db-info-hint">' + esc(hint) + '</div>' : '') + '</div>';
                return el;
            },
            onEvent: function () {},
            onUpdate: function () {}
        });

        editor.TraitManager.addType('db-number-example', {
            createInput: function (o) {
                var el = document.createElement('div');
                el.className = 'db-trait-info db-number-preview';
                el.innerHTML = '<div><div class="db-info-value db-number-example-value"></div>'
                    + '</div>';
                var view = this;
                this.listenTo(o.component, 'change:noPrefix change:noDateFormat change:noSeqDigits', function () {
                    view.onUpdate({ elInput: el, component: o.component });
                });
                this.onUpdate({ elInput: el, component: o.component });
                return el;
            },
            onEvent: function () {},
            onUpdate: function (o) {
                o.elInput.querySelector('.db-number-example-value').textContent = numberExample(o.component);
            }
        });
    }

    /* ───────────────────────── 初始化 ───────────────────────── */

    function headComponent(settings) {
        var head = { type: HEAD, docName: settings.name };
        Object.keys(C.buttonLabels).forEach(function (key) { head[BUTTON_PROP(key)] = settings.buttons[key] !== false; });
        return head;
    }

    function canvasComponents(rows, settings) {
        return [headComponent(settings), { type: DATE }].concat(rows || []);
    }

    var messages = JSON.parse(JSON.stringify(window.WFBuilderI18n || {}));
    messages.domComponents = messages.domComponents || {};
    messages.domComponents.names = Object.assign({}, messages.domComponents.names, { 'db-row': '一列', 'db-cell': '一格' });

    var editor = grapesjs.init({
        container: '#wfb-canvas',
        height: '100%',
        width: 'auto',
        fromElement: false,
        storageManager: { autosave: false, autoload: false },
        noticeOnUnload: false,
        showOffsets: false,
        undoManager: { trackSelection: false },
        i18n: { locale: 'zh-TW', detectLocale: false, localeFallback: 'en', messages: { 'zh-TW': messages } },
        plugins: [definePlugin],
        components: canvasComponents(C.components, C.settings),
        canvas: { styles: C.canvasStyles },
        panels: { defaults: [] },
        deviceManager: { devices: [{ id: 'desktop', name: '桌機', width: '' }] },
        blockManager: { appendTo: '#wfb-blocks' },
        traitManager: { appendTo: '#wfb-traits' },
        layerManager: { appendTo: document.createElement('div') },
        selectorManager: { appendTo: document.createElement('div') },
        styleManager: { appendTo: document.createElement('div') }
    });

    var bm = editor.BlockManager;
    var um = editor.UndoManager;

    /* ───────────────────────── 元件庫 ───────────────────────── */

    // 每種欄位／主檔各自一個圖示（f-* 對應欄位型別，m-* 對應主檔來源），找不到時退回通用圖示
    var ICONS = {
        field: '<path d="M4 7h16M4 12h10M4 17h7"/>',
        master: '<circle cx="11" cy="11" r="6"/><path d="m20 20-4.3-4.3"/>',
        'f-text': '<path d="M5 6h14M12 6v13"/>',
        'f-textarea': '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h10M7 17h6"/>',
        'f-number': '<path d="M9 4 7 20M17 4l-2 16M4 9h16M3 15h16"/>',
        'f-date': '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'f-checkbox': '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="m8 12 3 3 5-6"/>',
        'f-radio': '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3.5"/>',
        'f-multiselect': '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 10 3 3 5-6"/><path d="M7 16h10"/>',
        'f-checkgroup': '<rect x="3" y="3" width="8" height="8" rx="1.5"/><path d="m4.5 7 1.5 1.5 2.5-3"/><rect x="13" y="13" width="8" height="8" rx="1.5"/>',
        'f-amount': '<path d="M15.5 8.5c-.6-1-1.7-1.6-3.5-1.6-2 0-3.3.9-3.3 2.2 0 3.1 7 1.4 7 4.6 0 1.4-1.4 2.3-3.7 2.3-1.9 0-3.1-.6-3.8-1.7"/><path d="M12 4.5v2M12 17.5v2"/>',
        'f-select': '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="m9 11 3 3 3-3"/>',
        'm-customer': '<path d="M5 8h14l-1 12H6z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'm-vendor': '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
        'm-product': '<path d="M12 3 3 7.5v9L12 21l9-4.5v-9z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/>',
        'm-employee': '<rect x="5" y="4" width="14" height="17" rx="2"/><circle cx="12" cy="11" r="2.5"/><path d="M8.5 17.5c.7-1.5 2-2.3 3.5-2.3s2.8.8 3.5 2.3"/>',
        'm-department': '<rect x="9" y="3" width="6" height="5" rx="1"/><rect x="3" y="16" width="6" height="5" rx="1"/><rect x="15" y="16" width="6" height="5" rx="1"/><path d="M12 8v4M6 16v-2h12v2"/>',
        'm-warehouse': '<path d="M3 10 12 4l9 6v10H3z"/><path d="M7 20v-6h10v6"/>',
        'm-user': '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/>',
        'm-leave': '<rect x="3" y="5" width="13" height="14" rx="2"/><path d="M3 10h13M7 3v4M12 3v4"/><circle cx="18" cy="16" r="4"/><path d="M18 14v2l1.5 1"/>',
        // 主檔來源可由來源庫新增，沒有專屬圖示時用資料庫圖示，與欄位的三條橫線區分開
        'm-default': '<ellipse cx="12" cy="5" rx="7" ry="3"/><path d="M5 5v6c0 1.7 3.1 3 7 3s7-1.3 7-3V5"/><path d="M5 11v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>',
        heading: '<path d="M6 4v16M18 4v16M6 12h12"/>',
        note: '<path d="M4 6h16M4 10h16M4 14h10"/>',
        spacer: '<rect x="4" y="6" width="16" height="12" rx="2" stroke-dasharray="3 3"/>',
        divider: '<path d="M3 12h18"/>',
        archived: '<path d="M3 7h18v13H3zM3 7l2-3h14l2 3M10 12h4"/>',
        table: '<rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="M3 10h18M3 14.5h18M9 5v14"/>'
    };

    // 卡片標記用的小尺寸圖示（與 icon() 同樣的線條樣式，只改尺寸）
    function smallIcon(name) {
        return icon(name).replace('width="26" height="26"', 'width="12" height="12"');
    }

    function icon(name) {
        return '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[name] || ICONS.field) + '</svg>';
    }

    function newField(ftype, label, w, extra) {
        return Object.assign({
            type: CELL, kind: 'field', width: w, fieldId: null, key: null, label: label, ftype: ftype,
            required: false, showInList: false, choices: '', decimals: 0, source: null, tableKey: '', sum: false
        }, extra || {});
    }

    var defaultWidth = Object.keys(FIELD_TYPE_DEFS).reduce(function (widths, type) {
        widths[type] = FIELD_TYPE_DEFS[type].default_width;
        return widths;
    }, {});
    Object.keys(FIELD_TYPE_DEFS).forEach(function (t) {
        if (t === 'master') return;
        bm.add('field-' + t, {
            label: FIELD_TYPE_DEFS[t].label, category: '欄位', media: icon('f-' + t),
            content: newField(t, FIELD_TYPE_DEFS[t].label, defaultWidth[t] || 6,
                (t === 'select' || t === 'radio' || t === 'checkgroup' || t === 'multiselect') ? { choices: '選項一\n選項二' } : (t === 'amount' ? { decimals: 2 } : null))
        });
    });
    // 主檔關聯：只有一張積木，拖入後來源留空，由右側「主檔來源」選擇（存檔前會檢查，見 unsetMasterCells）
    bm.add('master-link', {
        label: '主檔關聯', category: '主檔下拉', media: icon('master'),
        // 帶上 class 讓樣式能標出「關聯主檔」（左上角鏈結標記）
        attributes: { class: 'wfb-block-master' },
        content: newField('master', '主檔關聯', 6, null)
    });

    /* ───────── 來源庫彈窗：編輯來源時不離開設計器，存檔後就地更新主檔下拉 ───────── */
    var sourcesModal = document.getElementById('wfb-sources-modal');
    var sourcesFrame = document.getElementById('wfb-sources-frame');
    var sourcesLink = document.getElementById('wfb-sources-link');

    function showToast(text) {
        var toast = document.createElement('div');
        toast.className = 'wfb-toast';
        toast.textContent = text;
        document.body.appendChild(toast);
        setTimeout(function () { toast.remove(); }, 2200);
    }

    if (sourcesModal && sourcesFrame && sourcesLink) {
        function openSources(e) {
            // 保留 Ctrl／Cmd 點擊等開新分頁的操作
            if (e.metaKey || e.ctrlKey || e.shiftKey) return;
            e.preventDefault();
            sourcesFrame.src = sourcesLink.href;
            sourcesModal.hidden = false;
        }

        function closeSources() {
            sourcesModal.hidden = true;
            sourcesFrame.src = 'about:blank';
        }

        // 來源代碼就是主檔下拉的 key；停用的來源從下拉移除，已放在畫布上的主檔欄位不受影響。
        // 積木只有一張（主檔關聯），來源差異只在屬性面板的下拉選項，所以這裡只更新來源清單
        function syncMasterSource(source) {
            if (source.status) {
                C.masters[source.code] = source.label;
            } else {
                delete C.masters[source.code];
            }
        }

        sourcesLink.addEventListener('click', openSources);
        document.getElementById('wfb-sources-close').addEventListener('click', closeSources);
        sourcesModal.addEventListener('click', function (e) {
            if (e.target === sourcesModal) closeSources();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !sourcesModal.hidden) closeSources();
        });

        // 只接受同源、且來自這個彈窗 iframe 的訊息
        window.addEventListener('message', function (e) {
            if (e.origin !== window.location.origin || e.source !== sourcesFrame.contentWindow) return;
            if (!e.data || e.data.type !== 'DB_SOURCE_SAVED' || !e.data.source) return;
            syncMasterSource(e.data.source);
            closeSources();
            showToast('來源已更新，主檔下拉已同步');
        });
    }
    bm.add('layout-heading', { label: COMPONENTS.heading.label, category: '輔助排版', media: icon('heading'), content: { type: CELL, kind: 'heading', width: 12, text: COMPONENTS.heading.label } });
    bm.add('layout-note', { label: COMPONENTS.note.label, category: '輔助排版', media: icon('note'), content: { type: CELL, kind: 'note', width: 12, text: COMPONENTS.note.label } });
    bm.add('layout-divider', { label: COMPONENTS.divider.label, category: '輔助排版', media: icon('divider'), content: { type: CELL, kind: 'divider', width: 12 } });
    bm.add('layout-spacer', { label: COMPONENTS.spacer.label, category: '輔助排版', media: icon('spacer'), content: { type: CELL, kind: 'spacer', width: 6 } });
    bm.add('layout-table', { label: COMPONENTS.table.label, category: '表格', media: icon('table'), content: { type: TABLE, title: COMPONENTS.table.label, tableKey: '' } });

    /* ───────────────────────── 已封存：所有已知欄位 − 畫布上的欄位 ───────────────────────── */

    // known：所有存過檔的欄位（fieldId → 屬性）；knownTables：存過檔的明細表（tableKey → 元件定義）
    var known = {};
    var knownTables = {};

    function tables() {
        return editor.getWrapper().components().filter(function (c) { return c.get('type') === TABLE; });
    }

    function cells() {
        var out = [];
        editor.getWrapper().components().forEach(function (c) {
            if (c.get('type') === ROW || c.get('type') === TABLE) c.components().forEach(function (x) { out.push(x); });
            else if (c.get('type') === CELL) out.push(c);
        });
        return out;
    }

    // 明細表連同表內欄位的快照（移除後放進「已封存」，拖回來就整張還原）
    function tableSnapshot(t) {
        return {
            type: TABLE, tableKey: t.get('tableKey'), title: t.get('title'),
            components: t.components().map(function (c) { return Object.assign({ type: CELL }, pick(c)); })
        };
    }

    function rememberCanvas() {
        cells().forEach(function (c) { if (c.get('fieldId')) known[c.get('fieldId')] = pick(c); });
    }

    function syncArchived() {
        var onCanvas = {};
        var tablesOnCanvas = {};
        cells().forEach(function (c) { if (c.get('fieldId')) onCanvas[c.get('fieldId')] = true; });
        tables().forEach(function (t) {
            if (t.get('tableKey')) tablesOnCanvas[t.get('tableKey')] = t.get('title');
            t.components().forEach(function (c) { if (c.get('fieldId')) onCanvas[c.get('fieldId')] = true; });
        });

        bm.getAll().filter(function (b) { return /^arch-/.test(b.getId()); }).forEach(function (b) { bm.remove(b.getId()); });

        // 分類標題帶數量，封存的欄位一眼看得到（與下面建立區塊的判斷一致）
        var archivedCount = Object.keys(known).filter(function (id) {
            var p = known[id];
            return !(onCanvas[id] || (p.tableKey && !(p.tableKey in tablesOnCanvas)));
        }).length + Object.keys(knownTables).filter(function (key) { return !(key in tablesOnCanvas); }).length;
        var archivedLabel = archivedCount ? ARCHIVED + '（' + archivedCount + '）' : ARCHIVED;

        // GrapesJS 的分類不會因為底下的區塊清空而消失，數量變了會留下舊標題，所以先把舊的「已封存」分類全部移掉
        var categories = bm.getCategories();
        categories.filter(function (cat) { return String(cat.get('id') || '').indexOf(ARCHIVED) === 0; })
            .forEach(function (cat) { categories.remove(cat); });
        Object.keys(known).forEach(function (id) {
            var p = known[id];
            // 表身欄位只能拖回原本的明細表；明細表不在畫布上時，跟著明細表一起還原
            if (onCanvas[id] || (p.tableKey && !(p.tableKey in tablesOnCanvas))) return;
            var where = p.tableKey ? '・' + tablesOnCanvas[p.tableKey] : '';
            bm.add('arch-' + id, {
                label: (p.label || '未命名') + '（' + C.fieldTypes[p.ftype] + where + '）',
                category: archivedLabel, media: icon('archived'),
                content: Object.assign({ type: CELL }, p)
            });
        });
        Object.keys(knownTables).forEach(function (key) {
            if (key in tablesOnCanvas) return;
            var t = knownTables[key];
            bm.add('arch-table-' + key, {
                label: (t.title || '未命名') + '（明細表，' + t.components.length + ' 欄）',
                category: archivedLabel, media: icon('table'),
                content: JSON.parse(JSON.stringify(t))
            });
        });

        // 分類標題的 DOM 不會跟著 collection 一起移除，整個面板重畫一次，數量才不會留舊標題
        bm.render();
        refreshCells();
    }

    // 欄位拖進或拖出明細表（含復原）後，預覽與右側設定項要換成對應的樣子
    function refreshCells() {
        cells().forEach(function (c) {
            if (c.get('kind') !== 'field') return;
            if (c.previewFor !== sectionOf(c)) {
                var view = c.getView && c.getView();
                if (view && view.renderPreview) view.renderPreview();
            }
            refreshTraits(c);
        });
    }

    var syncTimer;
    function scheduleSync() {
        clearTimeout(syncTimer);
        syncTimer = setTimeout(syncArchived, 0);
    }

    function resetKnown(components, archived, archivedTables) {
        known = {};
        knownTables = {};
        (components || []).forEach(function (row) {
            if (row.type === TABLE && row.tableKey) knownTables[row.tableKey] = row;
            (row.components || []).forEach(function (c) { if (c.fieldId) known[c.fieldId] = c; });
        });
        (archived || []).forEach(function (c) { known[c.fieldId] = c; });
        (archivedTables || []).forEach(function (t) {
            knownTables[t.tableKey] = t;
            (t.components || []).forEach(function (c) { if (c.fieldId) known[c.fieldId] = c; });
        });
    }

    // 從畫布移除欄位（含整列、整張明細表刪除）時記下最新的設定，放到「已封存」
    editor.on('component:remove', function (c) {
        if (c.get('type') === TABLE && c.get('tableKey')) knownTables[c.get('tableKey')] = tableSnapshot(c);
        var list = c.get('type') === ROW || c.get('type') === TABLE ? c.components().models : [c];
        var archivedNames = [];
        list.forEach(function (x) {
            if (x.get('type') === CELL && x.get('fieldId')) {
                known[x.get('fieldId')] = pick(x);
                archivedNames.push(x.get('label') || '未命名欄位');
            }
        });
        if (c.get('type') === TABLE && c.get('tableKey')) archivedNames.push(c.get('title') || '未命名明細表');
        if (archivedNames.length) {
            toast('已將「' + archivedNames[0] + '」' + (archivedNames.length > 1 ? '等 ' + archivedNames.length + ' 項' : '') + '移到左側「已封存」，拖回即可復原');
        }
        scheduleSync();
    });

    /* ───────────────────────── 拖放後整理結構 ───────────────────────── */

    // 放在最外層的格子包成新的一列，拖空的列移除。要同步執行，才會跟拖放併成同一個復原步驟。
    function normalize() {
        var list = editor.getWrapper().components();
        list.filter(function (c) { return c.get('type') === CELL; }).forEach(function (cell) {
            var row = list.add({ type: ROW }, { at: cell.index() });
            cell.move(row, { at: 0 });
        });
        list.filter(function (c) { return c.get('type') === ROW && !c.components().length; })
            .forEach(function (row) { row.remove(); });

        // 拖到標題列或單據日期上面的列或明細表，移到單據日期下面
        var date = list.find(function (c) { return c.get('type') === DATE; });
        if (date) {
            list.filter(function (c) { return (c.get('type') === ROW || c.get('type') === TABLE) && c.index() < date.index(); })
                .forEach(function (row) { row.move(editor.getWrapper(), { at: date.index() + 1 }); });
        }

        // 新欄位在表頭與明細表之間移動後，代碼可能和另一區塊的欄位重複（自動取名，不進復原紀錄）
        cells().forEach(function (c) {
            if (!isNewField(c) || !c.get('key')) return;
            var key = uniqueKey(c.get('key'), c);
            if (key !== c.get('key')) um.skip(function () { c.set('key', key); });
        });
        refreshCells();
    }

    // 屬性面板裡「主檔來源」那一列的下拉（列的文字含「主檔來源」，GrapesJS 不會替 select 加 name）
    function sourceTraitSelect() {
        var row = Array.prototype.find.call(document.querySelectorAll('#wfb-traits .gjs-trt-trait'), function (t) {
            return t.textContent.indexOf('主檔來源') > -1;
        });
        return row ? row.querySelector('select') : null;
    }

    editor.on('block:drag:stop', function (component) {
        normalize();
        if (component) editor.select(component);
        // 拖入的主檔關聯還沒選來源：把焦點移到右側的來源下拉，省一次點擊（等屬性面板換成這個欄位之後再聚焦）
        if (component && component.get('ftype') === 'master' && !component.get('source')) {
            setTimeout(function () {
                var select = sourceTraitSelect();
                if (select) select.focus();
            }, 0);
        }
    });

    // 來源選定後，若欄位名稱還是預設的「主檔關聯」，就改用來源的名稱（使用者改過名稱就不動）
    editor.on('component:update:source', function (c) {
        if (c.get('ftype') !== 'master') return;
        if (c.get('label') === '主檔關聯' && C.masters[c.get('source')]) c.set('label', C.masters[c.get('source')]);
    });
    editor.on('component:drag:end', normalize);

    /* ───────────────────────── 選取時顯示名稱 ───────────────────────── */

    function describe(c) {
        if (!c) return '尚未選取元件';
        if (c.get('type') === ROW) {
            var total = c.components().reduce(function (sum, x) { return sum + width(x); }, 0);
            return '一列（' + c.components().length + ' 格，寬度合計 ' + total + '／12' + (total > 12 ? '，超過的會換行' : '') + '）';
        }
        if (c.get('type') === HEAD) return '標題列與按鈕';
        if (c.get('type') === DATE) return '單號與單據日期（固定欄位）';
        if (c.get('type') === TABLE) return COMPONENTS.table.label + '：' + (c.get('title') || '未命名') + '（' + c.components().length + ' 欄）';
        if (c.get('type') !== CELL) return '整張單據';
        if (c.get('kind') !== 'field') return KIND_NAMES[c.get('kind')];
        return (inTable(c) ? '明細表「' + (c.parent().get('title') || '未命名') + '」的' : '')
            + (C.fieldTypes[c.get('ftype')] || '') + '欄位：' + (c.get('label') || '未命名');
    }

    function renderSelected() {
        $('#wfb-selected-name').textContent = describe(editor.getSelected());
        renderEventPanel(editor.getSelected());
    }

    editor.on('component:selected component:deselected', renderSelected);

    // 標題列的名稱與按鈕設定都在整張單據上，點標題列就改選整張單據
    editor.on('component:selected', function (c) {
        if (previewMode) { editor.selectRemove(c); return; }
        if (c.get('type') === HEAD) editor.select(editor.getWrapper());
    });

    // 沒有選取任何元件時，右側就是整張單據的設定（單號、排序、狀態）。
    // 延後一拍再判斷：取消選取和選取另一個元件是同一串事件，這時候不能把選取搶回來。
    editor.on('component:deselected', function () {
        setTimeout(function () {
            if (!previewMode && !editor.getSelected()) editor.select(editor.getWrapper());
        }, 0);
    });
    editor.on('component:update:label component:update:width component:update:title', renderSelected);
    editor.on('component:update:key component:update:choices', renderSelected);

    function designerEventFields() {
        return cells().filter(function (cell) {
            return cell.get('kind') === 'field' && !inTable(cell) && KEY_RE.test(String(cell.get('key') || ''));
        }).map(function (cell) {
            return {
                key: cell.get('key'),
                label: cell.get('label') || cell.get('key'),
                type: cell.get('ftype'),
                choices: String(cell.get('choices') || '').split(/\r?\n/).map(function (choice) { return choice.trim(); }).filter(Boolean)
            };
        });
    }

    function eventValueControl(field, id, value) {
        var html;
        if (field.type === 'select' || field.type === 'radio') {
            html = '<select id="' + id + '" class="db-event-value">'
                + field.choices.map(function (choice) {
                    return '<option value="' + esc(choice) + '"' + (choice === value ? ' selected' : '') + '>' + esc(choice) + '</option>';
                }).join('') + '</select>';
        } else if (field.type === 'checkbox') {
            html = '<select id="' + id + '" class="db-event-value">'
                + '<option value="true"' + (value === true ? ' selected' : '') + '>是</option>'
                + '<option value="false"' + (value !== true ? ' selected' : '') + '>否</option></select>';
        } else {
            var inputType = field.type === 'number' || field.type === 'amount' ? 'number' : (field.type === 'date' ? 'date' : 'text');
            html = '<input id="' + id + '" class="db-event-value" type="' + inputType + '" value="' + esc(value == null ? '' : value) + '"'
                + (field.type === 'textarea' ? ' maxlength="2000"' : ' maxlength="255"') + '>';
        }

        return html;
    }

    function renderConditionValue(field, value) {
        var container = $('#db-event-condition-value');
        var operator = $('#db-event-condition').value;
        if (operator === 'always' || operator === 'is_empty' || !field || field.type === 'master') {
            container.innerHTML = '';
            return;
        }
        container.innerHTML = '<label for="db-event-condition-input">比較值</label>' + eventValueControl(field, 'db-event-condition-input', value);
    }

    function renderTargetValue(field, value) {
        var container = $('#db-event-target-value');
        if (!field) {
            container.innerHTML = '';
            return;
        }
        container.innerHTML = '<label for="db-event-target-input">設定值</label>' + eventValueControl(field, 'db-event-target-input', value);
    }

    function typedEventValue(field, input) {
        if (!input) return null;
        if (field.type === 'number' || field.type === 'amount') return input.value === '' ? null : Number(input.value);
        if (field.type === 'checkbox') return input.value === 'true';
        return input.value;
    }

    function renderEventPanel(selected) {
        var panel = $('#db-event-editor');
        if (!panel) return;
        var supported = selected && selected.get('type') === CELL && selected.get('kind') === 'field'
            && !inTable(selected) && KEY_RE.test(String(selected.get('key') || ''));
        panel.hidden = !supported;
        if (!supported) return;

        var source = {
            key: selected.get('key'),
            label: selected.get('label') || selected.get('key'),
            type: selected.get('ftype'),
            choices: String(selected.get('choices') || '').split(/\r?\n/).map(function (choice) { return choice.trim(); }).filter(Boolean)
        };
        var fields = designerEventFields().filter(function (field) { return field.key !== source.key && field.type !== 'master'; });
        var events = Array.isArray(C.schema.events) ? C.schema.events : [];
        var current = events.find(function (event) { return event.trigger && event.trigger.field === source.key; }) || null;
        var currentAction = current && current.actions ? current.actions[0] : null;
        var targetKey = currentAction && currentAction.field;
        if (!fields.some(function (field) { return field.key === targetKey; })) targetKey = fields.length ? fields[0].key : '';

        var targetSelect = $('#db-event-target');
        targetSelect.innerHTML = fields.map(function (field) {
            return '<option value="' + esc(field.key) + '"' + (field.key === targetKey ? ' selected' : '') + '>'
                + esc(field.label) + '（' + esc(C.fieldTypes[field.type] || field.type) + '）</option>';
        }).join('');
        targetSelect.disabled = fields.length === 0;
        $('#db-event-save').disabled = fields.length === 0;

        // 主檔與複選的值不是單一字串，只能用「沒有填寫」判斷（後端的事件驗證也會擋下其他運算子）
        var emptyOnly = source.type === 'master' || source.type === 'checkgroup' || source.type === 'multiselect';
        var operator = current && current.condition ? current.condition.operator : 'always';
        if (emptyOnly && operator !== 'is_empty') operator = 'always';
        $('#db-event-condition').value = operator;
        Array.prototype.forEach.call($('#db-event-condition').options, function (option) {
            option.disabled = emptyOnly && ['equals', 'not_equals'].indexOf(option.value) !== -1;
        });

        var compareValue = current && current.condition ? current.condition.value : '';
        renderConditionValue(source, compareValue);
        var target = fields.find(function (field) { return field.key === targetKey; }) || null;
        renderTargetValue(target, currentAction ? currentAction.value : '');
        $('#db-event-remove').hidden = !current;
        $('#db-event-message').textContent = current ? '目前有一條規則；套用新規則會取代它。' : '尚未設定連動規則。';
        $('#db-event-message').classList.remove('is-error');
    }

    $('#db-event-condition').addEventListener('change', function () {
        var selected = editor.getSelected();
        if (!selected || selected.get('type') !== CELL) return;
        var source = {
            type: selected.get('ftype'),
            choices: String(selected.get('choices') || '').split(/\r?\n/).map(function (choice) { return choice.trim(); }).filter(Boolean)
        };
        renderConditionValue(source, '');
    });
    $('#db-event-target').addEventListener('change', function () {
        var field = designerEventFields().find(function (item) { return item.key === $('#db-event-target').value; });
        renderTargetValue(field, '');
    });

    function selectedEventSource() {
        var selected = editor.getSelected();
        return selected && selected.get('type') === CELL && selected.get('kind') === 'field' && !inTable(selected)
            ? selected
            : null;
    }

    function storeSelectedEvent(remove) {
        var sourceCell = selectedEventSource();
        if (!sourceCell) return;
        var sourceKey = String(sourceCell.get('key') || '');
        var events = (Array.isArray(C.schema.events) ? C.schema.events : []).filter(function (event) {
            return !event.trigger || event.trigger.field !== sourceKey;
        });

        if (!remove) {
            var fields = designerEventFields();
            var source = fields.find(function (field) { return field.key === sourceKey; });
            var target = fields.find(function (field) { return field.key === $('#db-event-target').value; });
            var targetInput = $('#db-event-target-input');
            var actionValue = typedEventValue(target, targetInput);
            if (!source || !target || actionValue === null || (typeof actionValue === 'number' && !Number.isFinite(actionValue))) {
                $('#db-event-message').textContent = '請選擇有效的更新欄位與設定值。';
                $('#db-event-message').classList.add('is-error');
                return;
            }

            var event = {
                trigger: { type: 'field.changed', field: sourceKey },
                actions: [{ type: 'set_value', field: target.key, value: actionValue }]
            };
            var operator = $('#db-event-condition').value;
            if (operator !== 'always') {
                event.condition = { operator: operator };
                if (operator !== 'is_empty') {
                    var compareValue = typedEventValue(source, $('#db-event-condition-input'));
                    if (compareValue === null || (typeof compareValue === 'number' && !Number.isFinite(compareValue))) {
                        $('#db-event-message').textContent = '請輸入有效的比較值。';
                        $('#db-event-message').classList.add('is-error');
                        return;
                    }
                    event.condition.value = compareValue;
                }
            }
            events.push(event);
        }

        C.schema.events = events;
        editor.getWrapper().set('eventSchema', JSON.stringify(events));
        renderEventPanel(sourceCell);
        $('#db-event-message').textContent = remove ? '連動規則已移除；按右上角「儲存」套用。' : '連動規則已套用；按右上角「儲存」套用。';
    }

    $('#db-event-save').addEventListener('click', function () { storeSelectedEvent(false); });
    $('#db-event-remove').addEventListener('click', function () { storeSelectedEvent(true); });
    editor.on('component:update:eventSchema', function (component) {
        try {
            C.schema.events = JSON.parse(component.get('eventSchema') || '[]');
        } catch (e) {
            C.schema.events = [];
        }
        renderEventPanel(editor.getSelected());
    });

    /* ───────────────────────── 單據設定（標題列 + 整張單據） ───────────────────────── */

    function head() {
        return editor.getWrapper().components().find(function (c) { return c.get('type') === HEAD; });
    }

    function applyWrapperSettings(settings) {
        var buttonProps = {};
        Object.keys(C.buttonLabels).forEach(function (key) { buttonProps[BUTTON_PROP(key)] = settings.buttons[key] !== false; });
        editor.getWrapper().set(Object.assign({
            docName: settings.name,
            noPrefix: settings.noPrefix,
            noDateFormat: settings.noDateFormat,
            noSeqDigits: settings.noSeqDigits,
            docSeq: settings.seq,
            docStatus: !!settings.status,
            eventSchema: JSON.stringify(C.schema.events || [])
        }, buttonProps));
    }

    // 標題列是按整張單據畫出來的示意：單據名稱與按鈕開關改了，就同步到標題列（不進復原紀錄，復原由整張單據那一步負責）
    var MIRROR_PROPS = ['docName'].concat(Object.keys(C.buttonLabels).map(BUTTON_PROP));
    editor.on(MIRROR_PROPS.map(function (p) { return 'component:update:' + p; }).join(' '), function (c) {
        if (c !== editor.getWrapper() || !head()) return;
        um.skip(function () {
            MIRROR_PROPS.forEach(function (p) { head().set(p, c.get(p)); });
        });
    });

    function collectSettings() {
        var w = editor.getWrapper();
        var buttons = {};
        Object.keys(C.buttonLabels).forEach(function (key) { buttons[key] = !!w.get(BUTTON_PROP(key)); });
        var seq = parseInt(w.get('docSeq'), 10);
        return {
            name: String(w.get('docName') || '').trim(),
            status: !!w.get('docStatus'),
            seq: isNaN(seq) ? 0 : seq,
            no_prefix: String(w.get('noPrefix') || '').trim(),
            no_date_format: String(w.get('noDateFormat') || 'Ymd'),
            no_seq_digits: parseInt(w.get('noSeqDigits'), 10) || 3,
            buttons: buttons
        };
    }

    function renderTypeName(name) {
        $('#dbd-type-name').textContent = name || '未命名單據';
        document.title = '設計單據｜' + (name || '未命名單據');
    }

    editor.on('component:update:docName', function (c) { renderTypeName(c.get('docName')); });

    /* ───────────────────────── 欄位代碼自動取名：Chrome 內建翻譯 → 拼音 ───────────────────────── */

    var KEY_VIA = {
        translate: '依 Chrome 內建翻譯產生。',
        pinyin: '依拼音產生（這個瀏覽器沒有內建翻譯）。',
        pinyinInsecure: '依拼音產生（Chrome 翻譯只能在 https 網址使用）。',
        pinyinDownloadable: '依拼音產生；按「自動產生」可以下載 Chrome 翻譯模型改用英文。',
        pinyinFailed: '依拼音產生（Chrome 翻譯模型無法下載或啟用）。',
        text: '依欄位名稱產生。',
        manual: '自訂代碼。'
    };
    // Chrome 的 Translator 有的版本只認 zh，依序嘗試
    var SOURCE_LANGS = ['zh-Hant', 'zh'];
    var translator = null;
    // 按過按鈕仍無法啟用就記住，之後不再提示「可下載」
    var translatorFailed = false;

    function normalizeColor(raw) {
        var v = String(raw || '').trim().toLowerCase();
        if (v === '') return '';
        if (v.charAt(0) !== '#') v = '#' + v;
        if (/^#[0-9a-f]{3}$/.test(v)) v = '#' + v.charAt(1) + v.charAt(1) + v.charAt(2) + v.charAt(2) + v.charAt(3) + v.charAt(3);
        return COLOR_RE.test(v) ? v : null;
    }

    function slugify(text) {
        var s = String(text || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        if (!s) return '';
        if (!/^[a-z]/.test(s)) s = 'f_' + s;
        return s.slice(0, 50).replace(/_+$/, '');
    }

    function pinyinKey(label) {
        if (!window.pinyinPro) return '';
        return slugify(window.pinyinPro.pinyin(label, { toneType: 'none', type: 'array', nonZh: 'consecutive' }).join(' '));
    }

    // 依序嘗試各語言代碼：有的 Chrome 版本回報 available 卻建立失敗，就換下一個。
    // allowDownload 只在使用者按按鈕時為 true：下載模型需要使用者手勢，也不該在打字時默默下載。
    // 回傳 { status: ready|downloadable|failed|unavailable|unsupported|insecure, translator? }
    function getTranslator(allowDownload, onProgress) {
        if (translator) return translator;
        // 非 https（例如本機 http://xxx.test）時 Chrome 會把翻譯 API 整個藏起來
        if (!window.isSecureContext) return Promise.resolve({ status: 'insecure' });
        if (!('Translator' in window)) return Promise.resolve({ status: 'unsupported' });
        if (translatorFailed && !allowDownload) return Promise.resolve({ status: 'failed' });

        var downloadable = false;
        var failed = false;
        var tryLang = function (i) {
            if (i >= SOURCE_LANGS.length) {
                // 自動取名時，還有語言可下載就提示按按鈕；按了按鈕仍失敗就是真的不能用
                if (allowDownload && failed) return { status: 'failed' };
                return { status: downloadable ? 'downloadable' : (failed ? 'failed' : 'unavailable') };
            }
            var lang = { sourceLanguage: SOURCE_LANGS[i], targetLanguage: 'en' };
            return window.Translator.availability(lang).then(function (status) {
                var canDownload = status === 'downloadable' || status === 'downloading';
                downloadable = downloadable || canDownload;
                if (status !== 'available' && !(allowDownload && canDownload)) return tryLang(i + 1);
                return window.Translator.create(Object.assign({
                    monitor: function (m) {
                        m.addEventListener('downloadprogress', function (e) { if (onProgress) onProgress(e.loaded); });
                    }
                }, lang)).then(function (t) {
                    return { status: 'ready', translator: t };
                });
            }).catch(function (e) {
                failed = true;
                if (window.console) console.warn('[document-builder] Chrome 翻譯（' + SOURCE_LANGS[i] + '）無法使用：', e);
                return tryLang(i + 1);
            });
        };

        // 成功才快取，失敗下次按按鈕可以再試
        return tryLang(0).then(function (r) {
            if (r.status === 'ready') translator = Promise.resolve(r);
            if (allowDownload && r.status !== 'ready') translatorFailed = true;
            return r;
        });
    }

    function suggestKey(label, allowDownload, onProgress) {
        label = String(label || '').trim();
        if (!label) return Promise.resolve({ key: '', via: '' });
        if (!/[\u3400-\u9fff]/.test(label)) return Promise.resolve({ key: slugify(label), via: 'text' });

        var fallback = function (status) {
            var via = { downloadable: 'pinyinDownloadable', downloading: 'pinyinDownloadable', failed: 'pinyinFailed', insecure: 'pinyinInsecure' }[status] || 'pinyin';
            return { key: pinyinKey(label), via: via };
        };
        return getTranslator(allowDownload, onProgress).then(function (r) {
            if (r.status !== 'ready') return fallback(r.status);
            return r.translator.translate(label).then(function (en) {
                var key = slugify(en);
                return key ? { key: key, via: 'translate' } : fallback();
            });
        }).catch(function () { return fallback(); });
    }

    // 和同一區塊（表頭／所有明細表）畫布上的其他欄位、已封存欄位都不能重複（已封存的代碼仍被舊資料使用）
    function uniqueKey(key, self) {
        if (!key) return key;
        var item = sectionOf(self) === 'item';
        var used = {};
        cells().forEach(function (c) {
            if (c !== self && c.get('key') && (sectionOf(c) === 'item') === item) used[c.get('key')] = true;
        });
        Object.keys(known).forEach(function (id) {
            if (known[id].key && !!known[id].tableKey === item) used[known[id].key] = true;
        });
        if (!used[key]) return key;
        for (var n = 2; ; n++) {
            var candidate = key.slice(0, 50 - String(n).length - 1) + '_' + n;
            if (!used[candidate]) return candidate;
        }
    }

    function generateKey(c, allowDownload) {
        var seq = (c.keyRequest || 0) + 1;
        c.keyRequest = seq;
        // 按鈕上的進度只是畫面狀態，不進復原紀錄
        var done = false;
        var busy = function (text) { um.skip(function () { c.set('keyBusy', text); }); };
        if (allowDownload) busy('產生中…');
        suggestKey(c.get('label'), allowDownload, function (loaded) {
            // 只有使用者按按鈕才顯示進度；進度事件可能比結果晚到，結束後就不再更新
            if (allowDownload && !done) busy('下載模型 ' + Math.round(loaded * 100) + '%');
        }).then(function (r) {
            done = true;
            // 等待期間又改了名稱、按了按鈕或手動輸入過，這次結果作廢（按鈕狀態也交給最新那次處理）
            if (c.keyRequest !== seq) return;
            if (c.get('keyBusy')) busy('');
            if (c.get('keyManual') && !allowDownload) return;
            // 自動取名不算使用者的編輯步驟，不進復原紀錄
            um.skip(function () {
                c.set({ keyManual: false, keyVia: r.via });
                c.set('key', uniqueKey(r.key, c) || null);
            });
        });
    }

    function isNewField(c) {
        return c && c.get('type') === CELL && c.get('kind') === 'field' && !c.get('fieldId');
    }

    // 新拖進來的欄位先取一次名；之後改欄位名稱時，只要沒手動改過代碼就跟著重新產生
    editor.on('component:add', function (c) {
        if (isNewField(c) && !c.get('key') && !c.get('keyManual')) generateKey(c, false);
    });
    editor.on('component:update:label', function (c) {
        if (!isNewField(c) || c.get('keyManual')) return;
        clearTimeout(c.keyTimer);
        c.keyTimer = setTimeout(function () { generateKey(c, false); }, 400);
    });

    /* ───────────────────────── 存檔 ───────────────────────── */

    function cellPayload(c) {
        var kind = c.get('kind');
        var item;
        if (kind === 'field') {
            var type = c.get('ftype');
            item = {
                kind: 'field',
                id: c.get('fieldId') || null,
                key: c.get('key') || null,
                label: String(c.get('label') || '').trim(),
                type: type,
                required: !!c.get('required'),
                // 列表只列表頭欄位；合計只有明細表的數字欄
                show_in_list: !inTable(c) && !!c.get('showInList'),
                choices: (type === 'select' || type === 'radio') ? String(c.get('choices') || '') : null,
                layout: type === 'radio' ? (c.get('layout') || 'horizontal') : null,
                decimals: (type === 'number' || type === 'amount') ? (parseInt(c.get('decimals'), 10) || 0) : null,
                currency: type === 'amount' ? (String(c.get('currency') || '').trim() || null) : null,
                source: type === 'master' ? (c.get('source') || null) : null,
                sum: inTable(c) && type === 'number' && !!c.get('sum')
            };
        } else if (kind === 'spacer' || kind === 'divider') {
            item = { kind: kind };
        } else if (kind === 'heading') {
            item = { kind: kind, text: String(c.get('text') || ''), bg: c.get('bg') || null, border: c.get('border') || null };
        } else {
            item = { kind: kind, text: String(c.get('text') || '') };
        }
        return { width: width(c), item: item };
    }

    function ensureTableKey(t) {
        var current = t.get('tableKey');
        if (current) return current;

        var knownKeys = Object.assign({}, C.schema.layout.tables || {});
        Object.keys(knownTables).forEach(function (key) { knownKeys[key] = true; });
        var n = Object.keys(knownKeys).length + 1;
        while (Object.prototype.hasOwnProperty.call(knownKeys, 'table_' + n)) n++;
        current = 'table_' + n;
        t.set('tableKey', current);
        return current;
    }

    function tablePayload(t) {
        var key = ensureTableKey(t);
        return {
            width: 12,
            item: {
                kind: 'table',
                key: key,
                title: String(t.get('title') || '').trim(),
                fields: t.components().filter(function (c) { return c.get('kind') === 'field'; })
                    .map(function (c) { return cellPayload(c).item; })
            }
        };
    }

    // 最外層的格子視為獨立一列、空列略過（復原到一半的狀態也能存出合法結構）；明細表獨佔一列
    // 畫布上還沒選主檔來源的主檔欄位（表頭與明細表裡的都要找）。後端雖然會擋，但只回報「缺少必要屬性」，不指出是哪一欄
    function unsetMasterCells() {
        var found = [];
        (function walk(c) {
            if (c.get('kind') === 'field' && c.get('ftype') === 'master' && !c.get('source')) found.push(c);
            c.components().forEach(walk);
        })(editor.getWrapper());
        return found;
    }

    function collectRows() {
        var rows = [];
        editor.getWrapper().components().forEach(function (c) {
            if (c.get('type') === TABLE) {
                rows.push({ cols: [tablePayload(c)] });
                return;
            }
            var list = c.get('type') === ROW ? c.components().models : (c.get('type') === CELL ? [c] : []);
            if (list.length) rows.push({ cols: list.map(cellPayload) });
        });
        return rows;
    }

    function fieldIdentity(key, section, tableKey) {
        return section + ':' + String(tableKey || '') + ':' + key;
    }

    function uniqueSchemaKey(label, section, fields) {
        var used = {};
        (C.schema.fields || []).forEach(function (field) {
            if (field.section === section) used[field.key] = true;
        });
        fields.forEach(function (field) {
            if (field.section === section) used[field.key] = true;
        });
        var base = slugify(label) || 'field';
        var candidate = base;
        for (var n = 2; used[candidate]; n++) {
            candidate = base.slice(0, 50 - String(n).length - 1) + '_' + n;
        }
        return candidate;
    }

    function fieldOptions(item, section) {
        if (item.type === 'select' || item.type === 'radio') {
            var seen = {};
            var choiceOptions = {
                choices: String(item.choices || '').split(/\r?\n/).map(function (choice) {
                    return choice.trim();
                }).filter(function (choice) {
                    if (!choice || seen[choice]) return false;
                    seen[choice] = true;
                    return true;
                })
            };
            if (item.type === 'radio') choiceOptions.layout = item.layout === 'vertical' ? 'vertical' : 'horizontal';
            return choiceOptions;
        }
        if (item.type === 'amount') {
            var amountOptions = { decimals: parseInt(item.decimals, 10) || 0 };
            if (item.currency) amountOptions.currency = String(item.currency).trim();
            return amountOptions;
        }
        if (item.type === 'number') {
            var options = { decimals: parseInt(item.decimals, 10) || 0 };
            if (section === 'item') options.sum = !!item.sum;
            return options;
        }
        if (item.type === 'master') return { source: item.source || null };
        return {};
    }

    /** 把 GrapesJS 編輯狀態轉成 Schema v1；輸出不含 DOM、HTML 或 GrapesJS 專屬資料。 */
    function schemaPayload(rows, settings) {
        var schema = JSON.parse(JSON.stringify(C.schema));
        var fields = schema.fields || [];
        var fieldPositions = {};
        fields.forEach(function (field, index) {
            fieldPositions[fieldIdentity(field.key, field.section, field.table_key)] = index;
        });
        var placed = {};
        var order = 0;

        function placeField(item, section, tableKey, cellWidth) {
            var key = item.key || uniqueSchemaKey(item.label, section, fields);
            var identity = fieldIdentity(key, section, tableKey);
            var definition = {
                key: key,
                section: section,
                table_key: section === 'item' ? tableKey : null,
                label: String(item.label || '').trim(),
                type: item.type,
                required: !!item.required,
                width: section === 'item' ? 12 : cellWidth,
                options: fieldOptions(item, section),
                show_in_list: section === 'header' && !!item.show_in_list,
                order: ++order,
                archived: false
            };
            placed[identity] = true;
            if (Object.prototype.hasOwnProperty.call(fieldPositions, identity)) {
                fields[fieldPositions[identity]] = definition;
            } else {
                fieldPositions[identity] = fields.length;
                fields.push(definition);
            }
            return { type: 'field', key: key };
        }

        var schemaRows = rows.map(function (row) {
            return { cols: row.cols.map(function (col) {
                var item = col.item;
                if (item.kind === 'table') {
                    var tableKey = item.key;
                    schema.layout.tables[tableKey] = item.title;
                    return {
                        width: 12,
                        component: {
                            type: 'table',
                            key: tableKey,
                            title: item.title,
                            fields: item.fields.map(function (field) {
                                return { key: placeField(field, 'item', tableKey, 12).key };
                            })
                        }
                    };
                }
                if (item.kind === 'field') {
                    return {
                        width: col.width,
                        component: placeField(item, 'header', null, col.width)
                    };
                }
                if (item.kind === 'spacer' || item.kind === 'divider') {
                    return { width: col.width, component: { type: item.kind } };
                }
                if (item.kind === 'heading') {
                    return {
                        width: col.width,
                        component: { type: 'heading', text: item.text, bg: item.bg, border: item.border }
                    };
                }
                return { width: col.width, component: { type: 'note', text: item.text } };
            }) };
        });

        fields.forEach(function (field) {
            field.archived = ! placed[fieldIdentity(field.key, field.section, field.table_key)];
        });
        schema.layout.rows = schemaRows;
        schema.fields = fields;
        schema.form = {
            code: schema.form.code,
            name: settings.name,
            enabled: settings.status,
            order: settings.seq,
            buttons: settings.buttons,
            numbering: {
                prefix: settings.no_prefix,
                date_format: settings.no_date_format,
                sequence_digits: settings.no_seq_digits
            }
        };
        try {
            schema.events = JSON.parse(editor.getWrapper().get('eventSchema') || '[]');
        } catch (e) {
            schema.events = C.schema.events || [];
        }
        var activeHeaderFields = {};
        fields.forEach(function (field) {
            if (field.section === 'header' && !field.archived) activeHeaderFields[field.key] = true;
        });
        schema.events = schema.events.filter(function (event) {
            return event.trigger && activeHeaderFields[event.trigger.field]
                && (event.actions || []).every(function (action) { return activeHeaderFields[action.field]; });
        });

        return schema;
    }

    function isDirty() {
        return editor.getDirtyCount() > 0;
    }

    function updateUndoButtons() {
        $('#wfb-undo').disabled = !um.hasUndo();
        $('#wfb-redo').disabled = !um.hasRedo();
    }

    var saving = null;
    function save(opts) {
        opts = opts || {};
        if (saving) return saving;
        // 主檔關聯還沒選來源：不送出，選取該欄位讓右側直接顯示來源下拉
        var unset = unsetMasterCells();
        if (unset.length) {
            editor.select(unset[0]);
            toast('「' + (unset[0].get('label') || '主檔關聯') + '」尚未選擇主檔來源，請在右側設定', 'error');
            return Promise.reject(new Error('主檔關聯尚未選擇來源'));
        }
        setSaveState('儲存中…');
        $('#wfb-save').disabled = true;
        $('#wfb').classList.add('is-saving');

        var payload = schemaPayload(collectRows(), collectSettings());
        saving = request(C.urls.save, 'PUT', payload)
            .then(function (res) {
                // 換成後端回傳的畫布（新欄位才拿得到 id 與代碼）
                editor.select(null);
                C.settings = res.settings;
                C.schema = res.schema;
                editor.setComponents(canvasComponents(res.components, res.settings));
                applyWrapperSettings(res.settings);
                renderTypeName(res.settings.name);
                resetKnown(res.components, res.archived, res.archivedTables);
                syncArchived();
                // setComponents 的 update 事件延到下一個 tick 才觸發，要等它過了再清除變更計數
                return new Promise(function (resolve) { setTimeout(resolve, 0); }).then(function () {
                    editor.clearDirtyCount();
                    um.clear();
                    updateUndoButtons();
                    renderSelected();
                    markSaved(nowText());
                    applyRevisionInfo(res.revisionInfo);
                    if (!opts.quiet) toast(res.message || '已儲存', 'success');
                    return res;
                });
            })
            .catch(function (err) {
                setSaveState('儲存失敗', 'error');
                toast(err.message, 'error');
                throw err;
            })
            .finally(function () {
                saving = null;
                // 存成功後沒有修改就維持停用；存失敗還留著修改，要能再按
                $('#wfb-save').disabled = !isDirty() && !!(C.revisionInfo && C.revisionInfo.number);
                $('#wfb').classList.remove('is-saving');
            });

        return saving;
    }

    /* ───────────────────────── 工具列與離開提醒 ───────────────────────── */

    $('#wfb-save').addEventListener('click', function () { save().catch(function () {}); });
    var publishButton = $('#dbd-publish');
    if (publishButton) {
        publishButton.addEventListener('click', function () {
            // 有修改時按鈕已停用（見 markDirty），這裡發布的一定是已審核的草稿
            if (!window.confirm('發布目前已審核的草稿？審核者與發布者必須是不同管理員。')) return;
            var summary = window.prompt('發布摘要（可以留空）', '') || '';

            publishButton.disabled = true;
            request(C.urls.publish, 'POST', { summary: summary }).then(function (result) {
                applyRevisionInfo({
                    number: result.revision.revision,
                    label: '已發布 v' + result.revision.revision,
                    published: true,
                    canPublish: false,
                    publishReason: '目前沒有等待發布的草稿'
                });
                toast(result.message || '表單版本已發布', 'success');
            }).catch(function (err) {
                publishButton.disabled = false;
                toast(err.message, 'error');
            });
        });
    }
    // 復原／重做會把欄位放回或拿走畫布，但不會觸發新增／移除事件，所以每次都排一次封存區同步
    function syncAfterHistory() { setTimeout(scheduleSync, 0); }
    $('#wfb-undo').addEventListener('click', function () { um.undo(); syncAfterHistory(); });
    $('#wfb-redo').addEventListener('click', function () { um.redo(); syncAfterHistory(); });
    function onHistoryKey(e) {
        var key = String(e.key || '').toLowerCase();
        if ((e.ctrlKey || e.metaKey) && (key === 'z' || key === 'y')) syncAfterHistory();
    }
    document.addEventListener('keydown', onHistoryKey);
    editor.Keymaps.add('db:save', '⌘+s, ctrl+s', function () { save().catch(function () {}); }, { prevent: true });

    $('#dbd-open-form').addEventListener('click', function () {
        // 先開分頁（避免被擋彈出視窗），有修改就先存再導過去
        var win = window.open('about:blank', '_blank');
        var go = function () {
            if (win) win.location.href = C.urls.preview;
            else window.open(C.urls.preview, '_blank');
        };
        if (isDirty()) save({ quiet: true }).then(go).catch(function () { if (win) win.close(); });
        else go();
    });

    /* ───────────────────────── 頂部下拉選單（全域選單、單據切換） ───────────────────────── */

    function closeMenus(except) {
        document.querySelectorAll('.wfb-menu').forEach(function (menu) {
            if (menu === except) return;
            menu.querySelector('.wfb-menu-list').hidden = true;
            menu.querySelector('[aria-haspopup]').setAttribute('aria-expanded', 'false');
        });
    }
    document.querySelectorAll('.wfb-menu').forEach(function (menu) {
        var button = menu.querySelector('[aria-haspopup]');
        var list = menu.querySelector('.wfb-menu-list');
        button.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = list.hidden;
            closeMenus(menu);
            list.hidden = !open;
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                var first = list.querySelector('.is-current') || list.querySelector('a');
                if (first) first.focus();
            }
        });
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.wfb-menu')) closeMenus();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelector('.wfb-menu-list:not([hidden])');
        if (!open) return;
        closeMenus();
        open.parentNode.querySelector('[aria-haspopup]').focus();
    });

    var leaving = false;
    document.querySelectorAll('[data-leave]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            if (isDirty() && !window.confirm('有未儲存的修改，確定離開？')) {
                e.preventDefault();
                return;
            }
            leaving = true;
        });
    });

    window.addEventListener('beforeunload', function (e) {
        if (!leaving && isDirty()) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    editor.on('update', function () {
        if (!saving && isDirty()) markDirty();
        updateUndoButtons();
        scheduleSync();
    });

    /* ───────────────────────── 拖曳時的 12 欄參考線與放置提示 ───────────────────────── */

    function setDragging(on) {
        if (canvasDoc && canvasDoc.body) canvasDoc.body.classList.toggle('db-dragging', on);
    }
    editor.on('block:drag:start component:drag:start', function () { setDragging(true); });
    editor.on('block:drag:stop component:drag:end', function () { setDragging(false); });

    /* ───────────────────────── 編輯／預覽切換 ───────────────────────── */

    var previewMode = false;

    // 預覽填入範例資料：只改畫布上的顯示值（不進 GrapesJS 的紀錄、不算未儲存），用長文字檢查排版會不會撐破
    var dummyOn = false;
    var DUMMY_TEXT = '（範例）台灣某某國際股份有限公司第二分公司，這是很長的客戶名稱，用來檢查排版';
    var DUMMY_NOTE = '第一行範例說明文字。\n第二行範例說明，內容比較長，用來確認多行文字的高度與換行是否正常。';
    function fillDummy(on) {
        dummyOn = on;
        if (!canvasDoc) return;
        canvasDoc.querySelectorAll('.db-cell').forEach(function (cell) {
            cell.querySelectorAll('input, textarea, select').forEach(function (el) {
                if (el.tagName === 'SELECT') return;
                if (el.type === 'checkbox') { el.checked = on; return; }
                if (el.type === 'date') { el.value = on ? '2026-10-11' : ''; return; }
                if (el.type === 'number' || el.inputMode === 'decimal') { el.value = on ? '12345.6789' : ''; return; }
                if (el.tagName === 'TEXTAREA') { el.value = on ? DUMMY_NOTE : ''; return; }
                el.value = on ? DUMMY_TEXT : '';
            });
        });
        $('#wfb-fill-dummy').textContent = on ? '清除範例資料' : '填入範例資料';
    }
    $('#wfb-fill-dummy').addEventListener('click', function () { fillDummy(!dummyOn); });

    function setPreview(on) {
        previewMode = on;
        $('#wfb-fill-dummy').hidden = !on;
        if (!on && dummyOn) fillDummy(false);
        $('#wfb').classList.toggle('is-preview', on);
        if (canvasDoc && canvasDoc.body) canvasDoc.body.classList.toggle('db-preview', on);
        if (on) {
            // 預覽只看填單的樣子：拿掉選取、虛線框與標示，左右面板也收起來
            editor.stopCommand('core:component-outline');
            editor.getSelectedAll().forEach(function (c) { editor.selectRemove(c); });
        } else {
            editor.runCommand('core:component-outline');
        }
        ['edit', 'preview'].forEach(function (mode) {
            var btn = $('#wfb-mode-' + mode);
            var active = (mode === 'preview') === on;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-pressed', String(active));
        });
    }
    $('#wfb-mode-edit').addEventListener('click', function () { setPreview(false); });
    $('#wfb-mode-preview').addEventListener('click', function () { setPreview(true); });

    /* ───────────────────────── 操作說明（右上 ?） ───────────────────────── */

    var helpBtn = $('#wfb-help-btn');
    var helpPop = $('#wfb-help');
    helpBtn.addEventListener('click', function () {
        var open = helpPop.hasAttribute('hidden');
        helpPop.toggleAttribute('hidden', !open);
        helpBtn.setAttribute('aria-expanded', String(open));
    });

    /* ───────────────────────── 載入完成 ───────────────────────── */

    var canvasDoc = null;
    editor.on('load', function () {
        // 畫布在 iframe 裡，鍵盤事件要另外聽（復原快捷鍵）
        canvasDoc = editor.Canvas.getDocument();
        if (canvasDoc) canvasDoc.addEventListener('keydown', onHistoryKey);
        // 自訂按鈕主色：放在畫布 CSS 之後，才能蓋過預設色
        if (canvasDoc && C.themeCss) {
            var themeStyle = canvasDoc.createElement('style');
            themeStyle.textContent = C.themeCss;
            canvasDoc.head.appendChild(themeStyle);
        }
        // 畫布在 iframe 裡，點畫布不會傳到外層 document，要另外關掉頂部下拉選單
        if (canvasDoc) canvasDoc.addEventListener('mousedown', function () { closeMenus(); });

        // 最外層只接受列與格子
        var wrapper = editor.getWrapper();
        wrapper.set({
            name: '整張單據',
            droppable: function (src, trg) {
                var t = typeOf(src);
                return (t === ROW || t === CELL || t === TABLE) && canPlace(src, trg);
            },
            stylable: false
        });
        wrapper.setTraits(wrapperTraits());
        applyWrapperSettings(C.settings);
        editor.runCommand('core:component-outline');

        resetKnown(C.components, C.archived, C.archivedTables);
        rememberCanvas();
        syncArchived();

        editor.select(wrapper);
        editor.clearDirtyCount();
        um.clear();
        updateUndoButtons();
        renderSelected();
        // 從沒存過（例如剛匯入的範本）就不顯示「已儲存」，只讓左側的版本標籤說明「尚未儲存」
        if (C.revisionInfo && C.revisionInfo.number) markSaved(C.revisionInfo.savedAt);
        else { setSaveState(''); $('#wfb-save').disabled = false; } // 沒存過也要能存，才會有第一個草稿可審核
        $('#wfb-loading').classList.add('is-done');
        setTimeout(function () { var el = $('#wfb-loading'); if (el) el.remove(); }, 400);
    });
})();
