# document-builder/document-builder

單據建置精靈：管理員用 GrapesJS 視覺化設計單據表單，使用者照著表單填單。包含明細表、自動單號、
單據狀態、版本審核、欄位變更連動、來源庫，以及給 AI 工具使用的 MCP 介面。

可以裝進任何 Laravel 專案：套件自帶完整介面（導覽列、樣式、前端函式庫都在套件裡，不從 CDN 載入），
不依賴宿主的畫面；和宿主之間只有四個接點：登入（guard）、權限（Gate）、使用者 model、資料來源。

## 支援版本

| Laravel | PHP |
|---|---|
| 10 | 8.1 以上 |
| 11 | 8.2 以上 |
| 12 | 8.2 以上 |
| 13 | 8.3 以上 |

資料庫 MySQL、MariaDB、SQLite 都可以。使用者主鍵可以是自動遞增整數、UUID 或 ULID。
介面文字有繁體中文（`zh_TW`、`zh-Hant` 都可以）與英文，依 `app.locale` 切換。

實際驗證過的組合（全新專案＋Breeze 登入，從安裝、設計單據、建立來源到填單都在瀏覽器操作過；宿主也裝了繁中語系）：

| Laravel | PHP | 使用者主鍵 |
|---|---|---|
| 10 | 8.2 | 整數 |
| 11 | 8.2 | 整數 |
| 12 | 8.2 | UUID |
| 13 | 8.4 | ULID |

## 安裝

```bash
composer require document-builder/document-builder
php artisan document-builder:install
```

**先執行安裝精靈，再執行 `php artisan migrate`。** 安裝精靈會依使用者 model 偵測主鍵型別（int／uuid／ulid），
套件資料表裡指向使用者的欄位跟著這個型別建立；如果資料表已經用別的型別建好，安裝精靈會警告。

安裝精靈會詢問 guard、使用者 model 與資料表、主鍵型別、名稱欄位、頁面版型、路由前綴與中介層、整合類別，
寫出 `config/document_builder.php`，發布前端資產到 `public/vendor/document-builder`，並執行 migration。

接著定義權限（Laravel 10 寫在 `AuthServiceProvider`，Laravel 11 以後寫在 `AppServiceProvider` 的 `boot()`）：

```php
Gate::define('document-builder.super-admin', fn ($user) => $user->is_admin);
```

完成後打開 `/document_builder`。

## 權限

| Gate | 用途 | 沒定義時 |
|---|---|---|
| `document-builder.super-admin` | 發布、審核、還原版本，透過 MCP 修改草稿 | 沒有人 |
| `document-builder.design` | 單據設定、設計器、版本紀錄、來源庫 | 等於 super-admin |
| `document-builder.view-type` | 參數是 `($user, DocType $type)`，誰能填寫、檢視某種單據 | 登入者都可以 |

要接自家後台的選單與權限，可以改實作 `DocumentBuilder\Contracts\Integration`，填在設定的 `integration`。

## 來源庫

主檔下拉（例如客戶、產品、員工）的資料來源。管理員在「來源庫」頁面新增，不用改程式：

- **資料模型**：宿主專案的 Eloquent model。預設自動找出 `app/Models` 底下的 model；
  欄位排除 model 的 `$hidden` 與 `sources.hidden_columns`（密碼這類欄位碰不到）。
  可以設「身分條件」（搜尋與存檔都套用，例如客戶欄位不能存廠商）與「可選條件」（只在搜尋時套用，例如排除停用的資料）。
- **單據**：拿單據工廠自己建立的單據當來源，例如請假單引用「專案單」，不需要宿主有任何 model。

已經被欄位使用的來源不能換資料來源、不能刪除，只能停用；停用後舊單據沿用原本的值仍可存檔。
開發者也可以把來源寫在設定的 `masters`（畫面上不能改），和來源庫合併使用。
要接其他資料（例如外部 API），實作 `DocumentBuilder\Contracts\SourceDriver` 後加進 `sources.drivers`。

## 設定（config/document_builder.php）

| 鍵 | 說明 |
|---|---|
| `route.prefix`／`route.name`／`route.middleware` | 套件路由的網址前綴、名稱前綴與中介層；中介層要包含登入檢查 |
| `guard`／`user_model`／`users_table` | 登入者從哪個 guard 取得、用哪個 model、使用者資料表 |
| `user_key_type`／`user_name_column` | 使用者主鍵型別（int／uuid／ulid，執行 migration 前要設好）與顯示名稱的欄位 |
| `layout` | 頁面版型；預設是套件自帶的完整介面，要嵌進自家後台時改成自己的版型 |
| `ui.brand`／`ui.home_url`／`ui.logout_route` | 自帶介面左上角名稱、「回到系統」連結、登出路由 |
| `integration` | 宿主後台整合；預設 `NullIntegration`（不處理選單，檢視權限看 `view-type` Gate） |
| `assets.canvas_styles` | 設計器畫布的樣式；null 表示用套件自帶的 Bootstrap |
| `masters` | 程式定義的主檔來源（畫面上不能改） |
| `sources.drivers`／`sources.models`／`sources.hidden_columns` | 來源庫的驅動、資料模型可選的 model（null 表示自動找）、不開放的欄位 |

沒有寫在宿主設定檔裡的頂層鍵沿用套件預設值。`config/form_platform.php`（元件與欄位型別規格）一般不需要發布。

## 嵌進自家後台

把 `layout` 改成自己的版型。版型要提供 `content` 區塊、`styles` 與 `scripts` 堆疊，並載入
Bootstrap 5、jQuery、jQuery Form、select2、SweetAlert2、DataTables，以及顯示 AJAX 錯誤的全域處理
（可參考套件的 `resources/views/layouts/app.blade.php`）。`assets.canvas_styles` 填自家的樣式，
讓設計器畫布和填單畫面長得一樣。

## 主要路由（名稱前綴依 `route.name`）

- `document_builder.home`：首頁，列出目前使用者能填寫的單據
- `document_builder.types.index`：單據設定
- `document_builder.designer.edit`：表單設計器
- `document_builder.sources.index`：來源庫
- `doc_records.index`：某種單據的填單列表（`{docType:code}`）
- `document_builder.mcp`：MCP HTTP 入口

## MCP

- HTTP：`document_builder.mcp` 路由，需要 `document-builder.design` 權限。非 localhost 的網址要在 `FORM_PLATFORM_MCP_ALLOWED_HOSTS` 加入主機。
- stdio（只限 local 環境）：`php artisan form-platform:mcp-stdio --admin-id=<超級管理員主鍵>`

## 更新

```bash
composer update document-builder/document-builder
php artisan vendor:publish --tag=document-builder-assets --force   # 更新前端資產
php artisan migrate
```

其他可發布的內容：`--tag=document-builder-config`（設定檔）、`--tag=document-builder-views`（視圖，發布到
`resources/views/vendor/document-builder` 後可覆寫）。按鈕文字可在 `lang/vendor/document-builder/{locale}/ui.php` 覆寫。

宿主的驗證訊息與登入畫面要中文時，可安裝 [Laravel-Lang](https://laravel-lang.com)：
`composer require laravel-lang/common --dev`、`php artisan lang:add zh_TW`，再把 `app.locale` 設成 `zh_TW`。

## 開發

```bash
composer install
vendor/bin/phpunit                          # 目前安裝的 Laravel 版本
bin/test-all-versions.sh                    # Laravel 10、11、12、13 各跑一次
bin/test-all-versions.sh 11                 # 只跑 Laravel 13（參數是 testbench 主版本：8／9／10／11）
PHP_BIN=/path/to/php8.4 bin/test-all-versions.sh 11   # 指定 PHP
```

Laravel 13 需要 PHP 8.3 以上；目前的 PHP 不夠新時，腳本會標示「略過」該版本，換 PHP 再跑一次。

測試用 orchestra/testbench 在乾淨的 Laravel 環境裡安裝套件，涵蓋自帶介面、權限、填單、UUID 使用者與來源庫。
