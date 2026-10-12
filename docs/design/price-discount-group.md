# 換算組：單價 ↔ 折數

狀態：草稿（方案 B，已選定；三個業務決定採預設值，見「決定事項」）

## 背景

來源：`cpkm-service/c11305-1` 訂購單（`public/js/order.js`）。每個明細列有「建議價」，使用者可以改單價或改折數，另一欄會跟著換算：

- 折數 = 單價 ÷ 建議價 × 100，取到小數兩位
- 單價 = round(建議價 × 折數 ÷ 100)
- 最後改的那一欄為準
- 列上有促銷價或促銷品項時，不從單價反算折數
- 沒有建議價時，折數留空

document-builder 目前做不到這件事，原因見「現況」。

## 現況（已由程式碼確認）

- 欄位事件只支援 `set_value` 固定值，沒有運算式（`src/FormPlatform/EventSchemaValidator.php`）。
- 事件的觸發欄位與目標欄位都限制在**表頭**（同檔 `:53`、`:88`），明細列內不能連動。
- 兩條事件若互相指向，會被 `hasCycle` 判定為循環依賴。
- 主檔欄位只寫入自己，沒有附帶其他欄位（`src/Services/SourceService.php`）。

## 設計

### 1. 欄位宣告

在明細表的欄位上宣告一組換算關係，只描述「是什麼」，不描述「怎麼算」：

```json
{
  "key": "unit_price_group",
  "kind": "price_discount",
  "table_key": "lines",
  "base": "suggestion_price",
  "price": "unit_amount",
  "rate": "promo_discount",
  "rate_scale": 100,
  "rate_decimals": 2,
  "price_rounding": "integer",
  "guard": { "skip_when_promo": true }
}
```

| 欄位 | 說明 |
|---|---|
| `kind` | 目前只接受 `price_discount`，之後可加其他換算類型 |
| `table_key` | 三個欄位必須屬於同一明細表 |
| `base` | 建議價欄位，由主檔帶入或為隱藏欄位 |
| `price` / `rate` | 被換算的兩欄 |
| `rate_scale` | 折數的基準，100 表示 90 = 9 折 |
| `rate_decimals` | 折數小數位數 |
| `price_rounding` | `integer`（預設，與訂購單一致）或 `decimal` |
| `guard.skip_when_promo` | 列上有促銷時不反算 |

### 2. 執行規則（前端，`resources/dist/form-events.js` 或新模組）

- 使用者改 `price`：反算 `rate`。
- 使用者改 `rate`：計算 `price`，並保留使用者輸入的折數。
- 寫回另一欄時不觸發事件，避免互相呼叫。
- `base` 為空或 0：`rate` 留空，不做計算。
- `guard` 成立：不反算，只保留使用者輸入。

### 3. 驗證（伺服器端，`EventSchemaValidator` 或新的 `ConversionGroupValidator`）

- `kind`、`rate_scale`、`rate_decimals`、`price_rounding` 必須在允許範圍內。
- 三個欄位都必須存在、未封存、型別為數字，且屬於同一 `table_key`。
- 換算組不納入一般事件的循環檢查，由自己的驗證器單獨處理。
- 換算組與一般事件指向同一欄位時，回傳錯誤，不允許並存。

### 4. 不在這一階段做的事

- 任意運算式（方案 A）。
- 「數量 × 單價 = 金額」等其他換算。
- 跨明細表的換算。

## 前置工作

換算組依賴以下兩項：

1. **明細列內的連動**：已完成。
   - 事件的 `trigger` 可加 `table_key`，表示這是該明細表「同一列」內的事件；沒有 `table_key` 的仍是表頭事件。
   - 表頭事件與明細事件互不觸發，目標欄位必須與觸發欄位在同一範圍。
   - 明細欄位在填單頁帶 `data-row-event-key`，與表頭的 `data-event-key` 分開；事件只在自己那一列內查找欄位，動態新增的列也適用。
   - 同一列內兩條事件互相指向，仍會被判定為循環（雙向規則留給換算組處理）。
   - 測試：`tests/Feature/EventSchemaValidatorTest.php`（範圍規則與循環）、`tests/Feature/RecordItemEventMarkupTest.php`（標記輸出）；前端以 headless Chrome 驗證有無 jQuery 兩種路徑。
2. **建議價來源**（已完成，範圍見待辦）：主檔附隨帶入，選了主檔後，同列中代碼相同的欄位會被填入並改為唯讀。

已知限制：伺服器端的 `FormEventEngine` 目前沒有任何呼叫端，事件實際上只在瀏覽器執行，伺服器不會重新計算，這點與表頭事件現況相同。

## 決定事項

| 問題 | 採用的預設值 | 原因 |
|---|---|---|
| 單價取整數 | `price_rounding: integer` | 與訂購單現況一致；改為 `decimal` 即可切換 |
| 沒有建議價 | 折數留空 | 與訂購單現況一致 |
| 促銷保護 | `guard.skip_when_promo: true` | 與訂購單現況一致 |

三項都做成設定值，之後改規則不需要改程式。

## 待辦

- [x] **建議價來源**：採「唯讀建議價欄位 + 主檔附隨帶入」（約定優於配置）。
  - 來源設定 `extras`（欄位代碼 => 模型欄位或單據資料欄位），搜尋結果帶出 `extras`；沒有附隨欄位的來源回傳格式不變。
  - 填單頁：主檔下拉帶 `data-extra-keys`；選取時寫入同一列（`tr`）中代碼相同的欄位，並改為唯讀；清空主檔時同列附隨欄位一併清空；重新選取時覆寫。
  - 新增列後會再鎖定一次（`window.DBMasterExtras.mark`）。
  - 驗證：`tests/Feature/MasterExtrasTest.php`（3 項）；`master-extras.js` 以 headless Chrome 驗證 14/14 項（同列寫入、列範圍隔離、清空、重選、唯讀、金額同步）。測試以 jQuery 事件模擬 select2 的 `select2:select`／`select2:clear`，尚未在完整的 select2 元件上實測。
  - 清空主檔的觸發：同時支援 `select2:select`、`change`（清除鈕、鍵盤、程式 `val(null).trigger('change')`）。`change` 時若沒有 select2 選項資料，只有值為空才清空，程式設值不會誤清。
  - 設計器：欄位代碼命中任一來源的附隨代碼時，屬性面板顯示一行淡色提示（不鎖定、不改畫布外觀）。
  - 決定不做：後台（來源庫）編輯 `extras` 的介面。附隨欄位對應屬於系統架構，維持在設定檔（`document_builder.masters`）定義。
  - 尚未實測：屬性面板提示只在程式層面驗證（PHP 測試確認設計器設定帶出附隨代碼），未在完整設計器中實際開啟欄位代碼輸入框看過畫面。
- [ ] **換算組實作**：
  - [x] 步驟 4-1（前端原型，已完成）：`resources/dist/conversion-group.js`，`DBConversion.register({ base, price, rate, guard })` 註冊後生效。驗證 `conversion-test` 瀏覽器測試 18/18 項（首次選品、驅動方規則、精度不飄移、促銷保護、無促銷欄位、建議價為 0／負數／清空、被動方有焦點不覆寫、無循環寫入）。尚未接進表單的 schema 與填單頁。
  - [x] 步驟 4-2（已完成）：schema 頂層 `conversion_groups`（每組 `{kind: price_discount, table_key, base, price, rate, guard: null | {field}}`），存於 `doc_types.conversion_groups`（遷移 `2026_10_15_000000`）。`ConversionGroupValidator` 檢查同明細表、數字或金額、不重複、一般事件不得設定控管欄位。設計器存檔、草稿、版本還原都會寫入；填單頁從 `data-conversion-groups` 註冊。
    - 驗證：`ConversionGroupValidatorTest`（8 項）、`ConversionGroupRecordTest`（2 項）；真實建立單據頁的瀏覽器流程 6/6 項（註冊、首次選品、驅動方、無 JS 錯誤）。
    - 尚未有設計器介面設定換算組；目前經由 schema（API／MCP）宣告，設計器存檔時會保留既有宣告。
  - [ ] 步驟 4-3：伺服器端重算。
- [ ] **伺服器端重算**：決定換算結果是否由伺服器重新計算。目前事件只在瀏覽器執行，與表頭事件現況相同。
- [x] **業務決定**（已確認）：
  1. 首次選商品：單價與折數皆空時，單價 = 建議價、折數 = 100；已有折數則依折數算單價，不覆蓋。
  2. 促銷判定：宣告式 Guard（`guard.field`）；欄位不存在視為未促銷。
  3. 寫回時機：驅動方為使用者改過並離開的欄位（focusout 且值有變），被動方才寫入。
  4. 建議價為空或 0（或負數）：折數清空，不產生 NaN／Infinity。
- [ ] **提交變更**：目前未提交的內容包括 `doc-field` 原型、明細列連動、本文件；待確認後再 commit。

## 測試計畫

- 單元測試：`EventSchemaValidator` 對換算組的合法與非法組合（缺欄位、跨明細表、型別錯誤、與事件衝突）。
- 前端測試（headless Chrome，比照 `doc-field` 的做法）：
  - 改單價 → 折數正確（建議價 333、單價 283 → 84.98，283 ÷ 333 × 100 = 84.984…）
  - 改折數 → 單價正確（建議價 333、折數 85 → 283）
  - 無建議價 → 折數留空
  - 有促銷 → 不反算
  - 連續改動不會無限迴圈

## 待確認

- 折數輸入為 `decimal` 時，是否要限制最小與最大值（例如 0–100）。
- 換算組在「新增列」時，是否要立即套用一次預設值。
