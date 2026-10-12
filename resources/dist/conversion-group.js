/**
 * 換算組（單價 ↔ 折數）：同一列內的雙向換算。
 *
 * 規則：
 * - 驅動方：使用者改過並離開欄位（focusout 且值有變）的那一欄；被動方才會被算出來。
 * - 被動方寫入時不會反向觸發換算（驅動方的值永遠保持原樣，不因四捨五入改寫）。
 * - 單價取整數（price_rounding: integer），折數取 rateDecimals 位小數。
 * - 建議價（base）變動：若單價與折數都空，帶入單價 = 建議價、折數 = rateScale；否則依最後的驅動方重算被動方。
 * - 建議價為空或 0：折數清空，不產生 NaN／Infinity。
 * - 促銷保護（guard）：guard.field 成立（checkbox 勾選，或其他欄位有正數值）且驅動方是單價時，不反算折數。
 *   guard 未設定或同列沒有該欄位，視為不成立。
 * - 程式寫入使用旗標 writing，不依賴 isTrusted（測試與自動化的事件都不是 trusted）。
 * - 只寫入未取得焦點的欄位：正在輸入的欄位不會被覆寫。
 *
 * 需要 amount-field.js 先載入（金額的顯示框由它同步）。
 */
(function () {
    'use strict';

    var groups = [];
    var writing = false;
    var snapshots = new WeakMap();
    var lastDriver = new WeakMap();

    function num(text) {
        var clean = String(text == null ? '' : text).replace(/,/g, '').trim();
        if (clean === '' || clean === '-' || clean === '.') return null;
        var n = Number(clean);
        return isFinite(n) ? n : null;
    }

    function round2(n) {
        return Math.round(n * 100) / 100;
    }

    // 同一列中的一個欄位：el 是寫入的元素（金額為隱藏純數字欄位），display 是金額的顯示框（沒有則為 null）
    function partsOf(row, key) {
        var el = row.querySelector('[name$="[' + key + ']"]');
        if (!el) return null;
        var wrap = el.closest ? el.closest('[data-amount]') : null;
        return { el: el, display: wrap ? wrap.querySelector('[data-amount-display]') : null };
    }

    function groupOf(control) {
        var row = control.closest ? control.closest('tr') : null;
        if (!row) return null;
        for (var i = 0; i < groups.length; i++) {
            var g = groups[i];
            var keys = [g.base, g.price, g.rate];
            for (var k = 0; k < keys.length; k++) {
                var parts = partsOf(row, keys[k]);
                if (parts && (parts.el === control || parts.display === control)) {
                    return { group: g, row: row, key: keys[k] };
                }
            }
        }
        return null;
    }

    function isFocused(parts) {
        return document.activeElement === parts.el || (parts.display && document.activeElement === parts.display);
    }

    // 程式寫入：值相同不動，正在輸入的欄位不覆寫，寫入後通知事件引擎與金額顯示（amount-field.js 依 change 同步）
    function write(parts, value) {
        if (!parts || isFocused(parts) || parts.el.value === value) return;
        writing = true;
        try {
            parts.el.value = value;
            parts.el.dispatchEvent(new Event('change', { bubbles: true }));
        } finally {
            writing = false;
        }
    }

    function guardOn(row, g) {
        if (!g.guard || !g.guard.field) return false;
        var parts = partsOf(row, g.guard.field);
        if (!parts) return false;
        if (parts.el.type === 'checkbox') return parts.el.checked;
        var n = num(parts.el.value);
        return n !== null ? n > 0 : parts.el.value.trim() !== '';
    }

    function priceFrom(base, rate, g) {
        return String(Math.round(base * rate / g.rateScale));
    }

    function rateFrom(price, base, g) {
        return round2(price / base * g.rateScale).toFixed(g.rateDecimals);
    }

    // 依驅動方重算被動方；base 無效時折數清空
    function recalc(row, g, driver) {
        var base = partsOf(row, g.base);
        var price = partsOf(row, g.price);
        var rate = partsOf(row, g.rate);
        if (!base || !price || !rate) return;

        var b = num(base.el.value);
        if (b === null || b <= 0) {
            write(rate, '');
            return;
        }

        if (driver === 'price') {
            if (guardOn(row, g)) return;          // 促銷：單價是特價，不反算折數
            var p = num(price.el.value);
            if (p === null) {
                write(rate, '');
                return;
            }
            write(rate, rateFrom(p, b, g));
        } else if (driver === 'rate') {
            var r = num(rate.el.value);
            if (r === null) return;                // 折數清空時單價保持原樣
            write(price, priceFrom(b, r, g));
        }
    }

    function onBaseChanged(row, g) {
        var base = partsOf(row, g.base);
        var price = partsOf(row, g.price);
        var rate = partsOf(row, g.rate);
        if (!base || !price || !rate) return;
        var b = num(base.el.value);
        if (b === null || b <= 0) {
            write(rate, '');
            return;
        }
        // 首次選品：單價與折數都空，預設原價開單
        if (num(price.el.value) === null && num(rate.el.value) === null) {
            write(price, String(Math.round(b)));
            write(rate, g.rateScale.toFixed(g.rateDecimals));
            return;
        }
        // 其他情況依最後的驅動方重算；沒有驅動紀錄時，有折數就以折數為準
        var driver = lastDriver.get(row) || (num(rate.el.value) !== null ? 'rate' : 'price');
        recalc(row, g, driver);
    }

    document.addEventListener('focusin', function (event) {
        var hit = groupOf(event.target);
        if (!hit) return;
        var parts = partsOf(hit.row, hit.key);
        snapshots.set(parts.el, parts.el.value);
    });

    document.addEventListener('focusout', function (event) {
        var hit = groupOf(event.target);
        if (!hit) return;
        var parts = partsOf(hit.row, hit.key);
        // 離開時金額欄位的 raw 已由 amount-field.js 整理好（它的監聽器先於本檔註冊）
        if (parts.el.value === snapshots.get(parts.el)) return;
        if (hit.key === hit.group.base) return;   // 建議價不是使用者的驅動方，由 change 處理
        var driver = hit.key === hit.group.price ? 'price' : 'rate';
        lastDriver.set(hit.row, driver);
        recalc(hit.row, hit.group, driver);
    });

    document.addEventListener('change', function (event) {
        if (writing) return;
        var hit = groupOf(event.target);
        if (!hit || hit.key !== hit.group.base) return;
        onBaseChanged(hit.row, hit.group);
    });

    // group: { base, price, rate, rateScale (預設 100), rateDecimals (預設 2), guard: { field } | null }
    function register(group) {
        var normalized = {
            base: group.base,
            price: group.price,
            rate: group.rate,
            rateScale: group.rateScale || 100,
            rateDecimals: group.rateDecimals === undefined ? 2 : group.rateDecimals,
            guard: group.guard && group.guard.field ? { field: group.guard.field } : null
        };
        var id = JSON.stringify(normalized);
        if (groups.some(function (g) { return JSON.stringify(g) === id; })) return;
        groups.push(normalized);
    }

    // 填單頁：伺服器依 schema 把換算組輸出在表單的 data-conversion-groups 上，頁面載入時註冊
    function registerFromForms() {
        document.querySelectorAll('[data-conversion-groups]').forEach(function (form) {
            var list;
            try {
                list = JSON.parse(form.getAttribute('data-conversion-groups') || '[]');
            } catch (error) {
                return;
            }
            (Array.isArray(list) ? list : []).forEach(register);
        });
    }

    window.DBConversion = {
        register: register,
        // 測試與除錯用：目前註冊的換算組
        groups: function () { return groups.slice(); }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', registerFromForms);
    } else {
        registerFromForms();
    }
}());
