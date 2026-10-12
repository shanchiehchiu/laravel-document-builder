(function () {
    'use strict';

    function readValue(control) {
        if (control.type === 'checkbox') return control.checked;
        if (control.type === 'number') return control.value === '' ? '' : Number(control.value);
        // 金額的隱藏欄位存的是純數字字串，讀取時和數字欄位一樣回傳數字
        if (control.hasAttribute('data-amount-raw')) return control.value === '' ? '' : Number(control.value);
        return control.value;
    }

    function matches(condition, value) {
        if (!condition) return true;
        if (condition.operator === 'equals') return value === condition.value;
        if (condition.operator === 'not_equals') return value !== condition.value;
        if (condition.operator === 'is_empty') return value === '' || value === null || (Array.isArray(value) && value.length === 0);
        return false;
    }

    // 事件範圍：表頭是整張表單（attr 為 data-event-key，table 為 null）；
    // 明細列是同一列的 <tr>（attr 為 data-row-event-key，table 為明細表代碼）。
    // 同一範圍的欄位只會看到同一範圍的控制項，不會跨列或跨表頭讀寫。
    function controls(scope, fieldKey) {
        return Array.prototype.filter.call(scope.root.querySelectorAll('[' + scope.attr + ']'), function (control) {
            return control.getAttribute(scope.attr) === fieldKey;
        });
    }

    // 單選按鈕是一組控制項，值是被選中的那一個；沒有選中時為空字串
    function radioValue(list) {
        var checked = list.filter(function (control) { return control.checked; })[0];
        return checked ? checked.value : '';
    }

    // 複選是一組 name="...[]" 的勾選框，值是所有被勾選的選項（陣列）
    function isCheckGroup(list) {
        return list[0].type === 'checkbox' && /\[\]$/.test(list[0].name || '');
    }

    function checkGroupValue(list) {
        return list.filter(function (control) { return control.checked; }).map(function (control) { return control.value; });
    }

    function valueOf(scope, fieldKey) {
        var list = controls(scope, fieldKey);
        if (list.length === 0) return null;
        if (list[0].type === 'radio') return radioValue(list);
        if (isCheckGroup(list)) return checkGroupValue(list);
        return readValue(list[0]);
    }

    function setValue(scope, fieldKey, value) {
        var list = controls(scope, fieldKey);
        if (list.length === 0) return false;

        if (list[0].type === 'radio') {
            var previousChoice = radioValue(list);
            var nextChoice = value == null ? '' : String(value);
            list.forEach(function (control) { control.checked = control.value === nextChoice; });

            if (radioValue(list) === previousChoice) return false;
            list[0].dispatchEvent(new Event('change', { bubbles: true }));

            return true;
        }

        if (isCheckGroup(list)) {
            var chosen = Array.isArray(value) ? value.map(String) : [];
            var before = JSON.stringify(checkGroupValue(list));
            list.forEach(function (control) { control.checked = chosen.indexOf(control.value) !== -1; });

            if (JSON.stringify(checkGroupValue(list)) === before) return false;
            list[0].dispatchEvent(new Event('change', { bubbles: true }));

            return true;
        }

        var control = list[0];
        var previous = readValue(control);
        if (control.type === 'checkbox') control.checked = value === true;
        else control.value = value == null ? '' : String(value);

        if (readValue(control) === previous) return false;
        control.dispatchEvent(new Event('change', { bubbles: true }));

        return true;
    }

    // 正在執行事件的範圍（表單或明細列）。同一範圍內不重入，不同列之間互不影響。
    var busy = new WeakSet();

    function run(scope, changedField, events) {
        if (busy.has(scope.root)) return;
        busy.add(scope.root);

        var pending = [changedField];
        var actions = 0;

        try {
            while (pending.length > 0 && actions < 50) {
                var triggerField = pending.shift();
                events.forEach(function (event) {
                    if (!event.trigger || event.trigger.type !== 'field.changed' || event.trigger.field !== triggerField) return;
                    // 事件只在自己的範圍內觸發：表頭事件不理會明細列的變動，反之亦然
                    if ((event.trigger.table_key || null) !== scope.table) return;
                    if (!matches(event.condition || null, valueOf(scope, triggerField))) return;

                    (event.actions || []).forEach(function (action) {
                        if (!action || action.type !== 'set_value' || typeof action.field !== 'string') return;
                        if (setValue(scope, action.field, action.value)) {
                            actions++;
                            pending.push(action.field);
                        }
                    });
                });
            }

            if (pending.length > 0 && window.console) console.error('[document-builder] 欄位事件超過執行上限，已停止後續處理');
        } finally {
            busy.delete(scope.root);
        }
    }

    function start(form) {
        var events;
        try {
            events = JSON.parse(form.dataset.formEvents || '[]');
        } catch (error) {
            return;
        }
        if (!Array.isArray(events) || events.length === 0) return;

        var headerScope = { root: form, attr: 'data-event-key', table: null };

        function handleHeaderEvent(event) {
            var target = event.target;
            if (target && target.dataset && target.dataset.eventKey) run(headerScope, target.dataset.eventKey, events);
        }

        function handleRowEvent(event) {
            var target = event.target;
            if (!target || !target.dataset || !target.dataset.rowEventKey) return;

            var row = target.closest('tr');
            var table = row && row.closest('.doc-items');
            if (!row || !table) return;

            run({ root: row, attr: 'data-row-event-key', table: table.dataset.table }, target.dataset.rowEventKey, events);
        }

        if (window.jQuery) {
            window.jQuery(form)
                .on('change.documentBuilderEvents input.documentBuilderEvents', '[data-event-key]', handleHeaderEvent)
                .on('change.documentBuilderEvents input.documentBuilderEvents', '[data-row-event-key]', handleRowEvent);
        } else {
            form.addEventListener('change', function (event) { handleHeaderEvent(event); handleRowEvent(event); });
            form.addEventListener('input', function (event) { handleHeaderEvent(event); handleRowEvent(event); });
        }
    }

    // 單選按鈕可以取消：非必填的單選（帶 data-clearable），再點一次已選的選項就清空。
    // 原生 radio 點選後無法取消，所以按下時記下原本是否已選，點擊完成後再取消並通知事件引擎。
    // 點標籤時瀏覽器會再對 input 觸發一次 click，所以只在 click 的目標是該 input 時處理。
    function radioFromTarget(target) {
        if (!target || !target.closest) return null;
        if (target.matches && target.matches('input[type="radio"]')) return target;
        var label = target.closest('label');
        return label && label.control && label.control.type === 'radio' ? label.control : null;
    }

    function installRadioClear() {
        var pressed = null;
        document.addEventListener('mousedown', function (event) {
            var radio = radioFromTarget(event.target);
            pressed = radio && radio.checked && radio.hasAttribute('data-clearable') ? radio : null;
        }, true);
        document.addEventListener('click', function (event) {
            if (!pressed || event.target !== pressed) return;
            var radio = pressed;
            pressed = null;
            if (!radio.checked) return;
            radio.checked = false;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
        }, true);
    }

    installRadioClear();
    document.querySelectorAll('[data-form-events]').forEach(start);
}());
