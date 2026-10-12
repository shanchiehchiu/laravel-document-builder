/**
 * 主檔附隨帶入：選了主檔後，主檔回傳的附隨欄位會寫進「同一列」中代碼相同的欄位，並改為唯讀。
 * - 作用域只限於觸發的那一列（tr），不影響其他列或表頭。
 * - 清空主檔時，同列的附隨欄位一併清空（事件引擎會收到變更，例如換算組會跟著清空）。
 * - 附隨欄位由來源決定（下拉的 data-extra-keys），金額欄位的隱藏欄位會一起寫入。
 * 需要 jQuery；select2 送出 select2:select／select2:clear 事件。
 */
(function ($) {
    'use strict';

    function keysOf($select) {
        try {
            return JSON.parse($select.attr('data-extra-keys') || '[]');
        } catch (error) {
            return [];
        }
    }

    // 同一列中名為 key 的控制項（金額欄位是它的隱藏純數字欄位）
    function rowControls($row, key) {
        return $row.find('[name$="[' + key + ']"]');
    }

    // 唯讀：金額欄位的顯示框也要一起鎖住，使用者看不到的隱藏欄位不用處理
    function lock(control) {
        var wrap = control.closest ? control.closest('[data-amount]') : null;
        if (wrap) {
            var display = wrap.querySelector('[data-amount-display]');
            if (display) display.readOnly = true;
        }
        control.readOnly = true;
    }

    function apply($select, extras) {
        var $row = $select.closest('tr');
        keysOf($select).forEach(function (key) {
            var value = extras && extras[key] !== undefined && extras[key] !== null ? String(extras[key]) : '';
            rowControls($row, key).each(function () {
                if (this.value === value) return;
                this.value = value;
                // 通知事件引擎與金額顯示（amount-field.js 會依此更新畫面）
                this.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    }

    // 把附隨欄位鎖成唯讀；新增列後也要呼叫一次
    function mark($root) {
        ($root || $(document)).find('select[data-extra-keys]').each(function () {
            var $select = $(this);
            keysOf($select).forEach(function (key) {
                rowControls($select.closest('tr'), key).each(function () { lock(this); });
            });
        });
    }

    // 選取：select2 會帶出選項資料（含 extras）
    $(document).on('select2:select', 'select[data-extra-keys]', function (event) {
        var data = event.params && event.params.data ? event.params.data : {};
        apply($(this), data.extras || {});
    });

    // 任何改值方式（清除鈕、鍵盤、程式 val(null).trigger('change')）都會觸發 change。
    // 值為空就清空；有值時只在拿得到選項資料時寫入，拿不到就不動，避免程式設值時誤清。
    $(document).on('change', 'select[data-extra-keys]', function () {
        var $select = $(this);
        if ($select.val() === '' || $select.val() === null) {
            apply($select, {});
            return;
        }
        if (!$select.data('select2')) return;
        var selected = $select.select2('data')[0];
        if (selected && selected.extras) apply($select, selected.extras);
    });

    window.DBMasterExtras = { mark: mark };

    $(function () { mark($(document)); });
}(window.jQuery));
