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

    function controls(form, fieldKey) {
        return Array.prototype.filter.call(form.querySelectorAll('[data-event-key]'), function (control) {
            return control.dataset.eventKey === fieldKey;
        });
    }

    function valueOf(form, fieldKey) {
        var control = controls(form, fieldKey)[0];
        return control ? readValue(control) : null;
    }

    function setValue(form, fieldKey, value) {
        var control = controls(form, fieldKey)[0];
        if (!control) return false;

        var previous = readValue(control);
        if (control.type === 'checkbox') control.checked = value === true;
        else control.value = value == null ? '' : String(value);

        if (readValue(control) === previous) return false;
        control.dispatchEvent(new Event('change', { bubbles: true }));

        return true;
    }

    function start(form) {
        var events;
        try {
            events = JSON.parse(form.dataset.formEvents || '[]');
        } catch (error) {
            return;
        }
        if (!Array.isArray(events) || events.length === 0) return;

        var processing = false;
        function run(changedField) {
            if (processing) return;
            processing = true;
            var pending = [changedField];
            var actions = 0;

            while (pending.length > 0 && actions < 50) {
                var triggerField = pending.shift();
                events.forEach(function (event) {
                    if (!event.trigger || event.trigger.type !== 'field.changed' || event.trigger.field !== triggerField) return;
                    if (!matches(event.condition || null, valueOf(form, triggerField))) return;

                    (event.actions || []).forEach(function (action) {
                        if (!action || action.type !== 'set_value' || typeof action.field !== 'string') return;
                        if (setValue(form, action.field, action.value)) {
                            actions++;
                            pending.push(action.field);
                        }
                    });
                });
            }

            if (pending.length > 0 && window.console) console.error('[document-builder] 欄位事件超過執行上限，已停止後續處理');
            processing = false;
        }

        function handleFieldEvent(event) {
            var target = event.target;
            if (!processing && target && target.dataset && target.dataset.eventKey) run(target.dataset.eventKey);
        }

        if (window.jQuery) {
            window.jQuery(form).on('change.documentBuilderEvents input.documentBuilderEvents', '[data-event-key]', handleFieldEvent);
        } else {
            form.addEventListener('change', handleFieldEvent);
            form.addEventListener('input', handleFieldEvent);
        }
    }

    document.querySelectorAll('[data-form-events]').forEach(start);
}());
