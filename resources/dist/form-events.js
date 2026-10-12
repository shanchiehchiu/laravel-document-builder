(function () {
    'use strict';

    function readValue(control) {
        if (control.type === 'checkbox') return control.checked;
        if (control.type === 'number') return control.value === '' ? '' : Number(control.value);
        return control.value;
    }

    function matches(condition, value) {
        if (!condition) return true;
        if (condition.operator === 'equals') return value === condition.value;
        if (condition.operator === 'not_equals') return value !== condition.value;
        if (condition.operator === 'is_empty') return value === '' || value === null;
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

    function valueOf(scope, fieldKey) {
        var control = controls(scope, fieldKey)[0];
        return control ? readValue(control) : null;
    }

    function setValue(scope, fieldKey, value) {
        var control = controls(scope, fieldKey)[0];
        if (!control) return false;

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

    document.querySelectorAll('[data-form-events]').forEach(start);
}());
