# document-builder/document-builder

單據建置精靈：管理員用 GrapesJS 視覺化設計單據表單，使用者照著表單填單。包含明細表、自動單號、
單據狀態、版本審核、欄位變更連動，以及給 AI 工具使用的 MCP 介面。

需求：PHP 8.1 以上、Laravel 10。頁面使用 Bootstrap 5、jQuery、select2、SweetAlert2、DataTables。

## 安裝

```bash
composer require document-builder/document-builder
php artisan document-builder:install
```

安裝精靈會詢問使用者 guard 與 model、頁面版型、路由前綴與中介層、側選單整合類別，寫出
`config/document_builder.php`，發布前端資產到 `public/vendor/document-builder`，並執行 migration。

最後在 `AuthServiceProvider` 定義誰是超級管理員（可以發布、審核、還原版本，並透過 MCP 修改草稿）：

```php
Gate::define('document-builder.super-admin', fn ($user) => $user->is_admin);
```

沒有定義時沒有人是超級管理員。

## 設定（config/document_builder.php）

| 鍵 | 說明 |
|---|---|
| `route.prefix`／`route.name`／`route.middleware` | 套件路由的網址前綴、名稱前綴與中介層；中介層要包含登入檢查 |
| `guard`／`user_model`／`users_table` | 登入者從哪個 guard 取得、用哪個 model；版本與審核紀錄的外鍵指向 `users_table` |
| `integration` | 宿主後台整合，實作 `DocumentBuilder\Contracts\Integration`。內建 `NullIntegration`（選單自己加）；要接自家後台的選單與權限，在專案裡實作這個介面 |
| `layout` | 頁面要 extends 的版型，需提供 `content` 區塊與 `scripts` 堆疊；預設是套件附的極簡版型 |
| `assets.canvas_styles` | 設計器畫布載入的版面樣式，讓畫布長得和填單畫面一樣 |
| `masters` | 主檔下拉的來源白名單（model、代碼欄、顯示欄、條件），由宿主註冊 |

沒有寫在宿主設定檔裡的頂層鍵沿用套件預設值。`config/form_platform.php`（元件與欄位型別規格）
一般不需要發布。

## 主要路由（名稱前綴依 `route.name`）

- `document_builder.types.index`：單據設定列表
- `document_builder.designer.edit`：表單設計器
- `doc_records.index`：某種單據的填單列表（`{docType:code}`）
- `document_builder.mcp`：MCP HTTP 入口

## MCP

- HTTP：`document_builder.mcp` 路由，走一般登入與權限。非 localhost 的網址要在 `FORM_PLATFORM_MCP_ALLOWED_HOSTS` 加入主機。
- stdio（只限 local 環境）：`php artisan form-platform:mcp-stdio --admin-id=<超級管理員 id>`

## 發布

```bash
php artisan vendor:publish --tag=document-builder-config   # 設定檔
php artisan vendor:publish --tag=document-builder-views    # 視圖，發布到 resources/views/vendor/document-builder 後可覆寫
php artisan vendor:publish --tag=document-builder-assets   # 前端資產（GrapesJS、設計器、填單連動腳本）
```

套件更新後要重新發布前端資產（加上 `--force`）。
