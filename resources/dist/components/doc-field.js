/**
 * <doc-field>：單據欄位元件（原型）
 *
 * 屬性（HTML attribute）：name、label、type（text | number | date）、required、disabled、value（只設初始值）
 * 屬性（JS property）：name（唯讀）、value、required
 * 方法：validate()、reset()
 * 事件：change，detail: { name, value }，bubbles 與 composed 皆為 true
 * 外部樣式：::part(label)、::part(input)
 */
(function () {
    'use strict';

    if (customElements.get('doc-field')) return;

    class DocField extends HTMLElement {
        // 讓元件參與外層 <form> 的提交與驗證
        static formAssociated = true;

        // 這些屬性變動時會呼叫 attributeChangedCallback
        static observedAttributes = ['label', 'type', 'required', 'disabled', 'value'];

        #internals = this.attachInternals();
        #input;
        #labelEl;

        constructor() {
            super();
            // mode 設為 open，讓外部仍可透過 shadowRoot 做測試與除錯
            const root = this.attachShadow({ mode: 'open' });
            root.innerHTML = `
                <style>
                    :host { display: grid; gap: 4px; font: 14px/1.4 sans-serif; }
                    :host([required]) label::after { content: ' *'; color: #c00; }
                    input { padding: 6px 8px; border: 1px solid #bbb; border-radius: 4px; }
                    input:invalid { border-color: #c00; }
                </style>
                <label part="label"></label>
                <input part="input">
            `;
            this.#labelEl = root.querySelector('label');
            this.#input = root.querySelector('input');
            this.#input.addEventListener('input', () => this.#onInput());
        }

        // 屬性變動 → 同步到內部的 input
        attributeChangedCallback(name, _old, value) {
            switch (name) {
                case 'label':    this.#labelEl.textContent = value ?? ''; break;
                case 'type':     this.#input.type = value ?? 'text'; break;
                case 'required': this.#input.required = value !== null; break;
                case 'disabled': this.#input.disabled = value !== null; break;
                case 'value':    this.value = value ?? ''; break;
            }
            // required 等屬性會影響驗證結果，變動後要重新同步給外層表單
            this.#sync();
        }

        get name() {
            return this.getAttribute('name') ?? '';
        }

        get value() {
            return this.#input.value;
        }

        set value(v) {
            this.#input.value = v ?? '';
            this.#sync();
        }

        get required() {
            return this.hasAttribute('required');
        }

        set required(on) {
            this.toggleAttribute('required', Boolean(on));
        }

        // 顯示瀏覽器的驗證提示，並回傳是否通過
        validate() {
            this.#input.reportValidity();
            return this.#input.checkValidity();
        }

        reset() {
            this.value = '';
        }

        #onInput() {
            this.#sync();
            // 對外發出事件，讓外層程式與表單都能監聽到
            this.dispatchEvent(new CustomEvent('change', {
                bubbles: true,
                composed: true,
                detail: { name: this.name, value: this.value },
            }));
        }

        // 同步給外層 <form> 的值與驗證狀態
        #sync() {
            this.#internals.setFormValue(this.value);
            // 驗證狀態在 validity 物件上，input 本身沒有 valid 屬性
            const valid = this.#input.validity.valid;
            // 失敗時以 customError 標記，並把瀏覽器產生的訊息交給表單；
            // 部分瀏覽器可能給空字串，規範要求此時訊息不可為空，所以補一段預設文字
            this.#internals.setValidity(
                valid ? {} : { customError: true },
                this.#input.validationMessage || '欄位內容不正確',
                this.#input,
            );
        }
    }

    customElements.define('doc-field', DocField);
})();
