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
2. **建議價來源**（尚未完成）：從主檔帶入建議價到明細列，可以是隱藏欄位，也可以是主檔附帶欄位。

已知限制：伺服器端的 `FormEventEngine` 目前沒有任何呼叫端，事件實際上只在瀏覽器執行，伺服器不會重新計算，這點與表頭事件現況相同。

## 決定事項

| 問題 | 採用的預設值 | 原因 |
|---|---|---|
| 單價取整數 | `price_rounding: integer` | 與訂購單現況一致；改為 `decimal` 即可切換 |
| 沒有建議價 | 折數留空 | 與訂購單現況一致 |
| 促銷保護 | `guard.skip_when_promo: true` | 與訂購單現況一致 |

三項都做成設定值，之後改規則不需要改程式。

## 待辦

- [ ] **建議價來源**：決定做法並實作。
  - (A) 隱藏欄位（建議先做）：主檔選取時寫入明細表的隱藏欄位，不需要改來源 API。
  - (B) 主檔附帶欄位：來源回傳額外欄位，影響範圍較大。
- [ ] **換算組實作**：`kind: price_discount`，包括 schema 驗證、前端執行規則、測試。
- [ ] **伺服器端重算**：決定換算結果是否由伺服器重新計算。目前事件只在瀏覽器執行，與表頭事件現況相同。
- [ ] **業務決定確認**：整數單價、無建議價留空、促銷保護，目前是預設值，尚未經業務確認。
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
