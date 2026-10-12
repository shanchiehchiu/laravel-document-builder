/**
 * 金額欄位：畫面顯示千分位與小數位，送出的永遠是隱藏欄位裡的純數字。
 * - 獲得焦點：顯示純數字，方便輸入，不會因為補上逗號而讓游標跳動。
 * - 輸入中：只更新隱藏欄位的值，讓事件引擎即時運算。
 * - 離開：四捨五入到設定的小數位，並補上千分位。
 * - 隱藏欄位被事件或程式改值時（沒有焦點），顯示同步更新。
 */
(function () {
    'use strict';

    // 清掉千分位後轉成數字；空白或還不完整（例如只有負號）時回傳 null
    function toNumber(text) {
        var clean = String(text == null ? '' : text).replace(/,/g, '').trim();
        if (clean === '' || clean === '-' || clean === '.') return null;
        var n = Number(clean);
        return isFinite(n) ? n : null;
    }

    function wrapOf(el) {
        return el && el.closest ? el.closest('[data-amount]') : null;
    }

    function partsOf(wrap) {
        return {
            display: wrap.querySelector('[data-amount-display]'),
            raw: wrap.querySelector('[data-amount-raw]'),
            decimals: parseInt(wrap.getAttribute('data-decimals'), 10) || 0
        };
    }

    // 寫入隱藏欄位；值真的改變時才通知事件引擎
    function setRaw(raw, value) {
        if (raw.value === value) return;
        raw.value = value;
        raw.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function formatted(raw, decimals) {
        var n = toNumber(raw);
        return n === null ? '' : n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    document.addEventListener('focusin', function (event) {
        var display = event.target;
        if (!display.matches || !display.matches('[data-amount-display]')) return;
        var parts = partsOf(wrapOf(display));
        display.value = parts.raw.value;
    });

    document.addEventListener('input', function (event) {
        var display = event.target;
        if (!display.matches || !display.matches('[data-amount-display]')) return;
        var parts = partsOf(wrapOf(display));
        // 只保留數字、小數點與負號；其他字元直接拿掉
        var clean = display.value.replace(/[^0-9.\-]/g, '');
        if (clean !== display.value) display.value = clean;
        var n = toNumber(clean);
        setRaw(parts.raw, n === null ? '' : clean);
    });

    document.addEventListener('focusout', function (event) {
        var display = event.target;
        if (!display.matches || !display.matches('[data-amount-display]')) return;
        var parts = partsOf(wrapOf(display));
        var n = toNumber(display.value);
        setRaw(parts.raw, n === null ? '' : n.toFixed(parts.decimals));
        display.value = formatted(parts.raw.value, parts.decimals);
    });

    // 事件引擎或程式改了隱藏欄位的值，而且使用者沒有在輸入：顯示跟著更新
    document.addEventListener('change', function (event) {
        var raw = event.target;
        if (!raw.matches || !raw.matches('[data-amount-raw]')) return;
        var wrap = wrapOf(raw);
        if (!wrap) return;
        var parts = partsOf(wrap);
        if (document.activeElement === parts.display) return;
        parts.display.value = formatted(raw.value, parts.decimals);
    });

    // 唯讀（檢視）模式：隱藏欄位會被停用，顯示框也一起停用
    document.querySelectorAll('[data-amount]').forEach(function (wrap) {
        var parts = partsOf(wrap);
        if (parts.raw.disabled && parts.display) parts.display.disabled = true;
    });
}());
