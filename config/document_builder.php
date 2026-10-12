<?php

declare(strict_types=1);

/*
 * 單據建置的預設設定。宿主專案用 `php artisan vendor:publish --tag=document-builder-config`
 * 發布到 config/document_builder.php 後覆寫；沒有覆寫的頂層鍵沿用這裡的預設值。
 */
return [
    /*
     * 路由：套件自己註冊後台路由。
     * prefix／name 會加在 document_builder 與 doc_records 兩組路由前面；middleware 要包含登入檢查。
     */
    'route' => [
        'prefix' => '',
        'name' => '',
        'middleware' => ['web', 'auth'],
    ],

    /*
     * 使用者。全部留 null 就會自動偵測，一般不用設定：
     *   guard：Laravel 的預設 guard
     *   user_model／users_table：guard 對應的 user provider 的 model 與它的資料表
     *   user_key_type：int、uuid 或 ulid，從 model 偵測（HasUuids／HasUlids）；套件資料表指向使用者的欄位依它建立
     *   user_name_column：顯示名稱的欄位，使用者表有 name 用 name，否則用 email
     *
     * 權限（Gate，可在宿主的 AuthServiceProvider／AppServiceProvider 定義；沒定義就用預設）：
     *   document-builder.super-admin：發布、審核、還原版本、透過 MCP 修改草稿。預設看「初始化」頁設定的超級管理員名單
     *   document-builder.design：單據設定、設計器、版本紀錄與來源庫。預設等於 super-admin
     *   document-builder.view-type：選用，參數是 (使用者, DocType)，決定誰能填寫某種單據。預設登入者都可以
     */
    'guard' => null,
    'user_model' => null,
    'users_table' => null,
    'user_key_type' => null,
    'user_name_column' => null,

    /*
     * 初始化頁（document_builder/setup）：還沒有任何超級管理員時，登入者可以在這裡把自己設為第一位超級管理員。
     * open_environments 裡的環境（預設本機開發 APP_ENV=local）直接可以設定；其他環境要輸入 token，避免被陌生人搶先。
     */
    'setup' => [
        'token' => env('DOCUMENT_BUILDER_SETUP_TOKEN'),
        'open_environments' => ['local'],
    ],

    /*
     * 宿主後台整合（側選單、一般使用者能不能檢視某種單據），實作 DocumentBuilder\Contracts\Integration。
     * 要接自家後台的選單與權限，就在專案裡實作這個介面，把類別名稱填在這裡。
     */
    'integration' => DocumentBuilder\Integrations\NullIntegration::class,

    /*
     * 套件頁面要 extends 的版型。預設是套件自帶的完整介面（導覽列、樣式、函式庫都在套件裡，不依賴宿主畫面）。
     * 想嵌進自家後台時改成自己的版型：需提供 content 區塊、styles 與 scripts 堆疊，並載入 Bootstrap 5、
     * jQuery、jQuery Form、select2、SweetAlert2、DataTables，以及 AJAX 錯誤提示。
     */
    'layout' => 'document-builder::layouts.app',

    /*
     * 自帶介面的導覽列。brand：左上角名稱（空白時用 menu.builder）；home_url：「回到系統」連結，
     * 設成 null 就不顯示；logout_route：登出的路由名稱（POST），路由不存在時不顯示。
     */
    'ui' => [
        'brand' => null,
        'home_url' => '/',
        'logout_route' => 'logout',
    ],

    /*
     * 前端資產。url：套件資產在 public 底下的位置（vendor:publish --tag=document-builder-assets 發布到這裡）。
     * canvas_styles：設計器畫布要載入的版面樣式，讓畫布長得和填單畫面一樣；可填完整網址或 public 底下的路徑。
     *   null 表示用套件自帶的 Bootstrap（和自帶介面一致）；改用自家版型時填自家的樣式。
     * favicon：選填。datatables_language：null 表示依 app 語系用套件附的繁中或英文語系檔。
     * 所有函式庫都附在套件裡，不從 CDN 載入。
     */
    'assets' => [
        'url' => 'vendor/document-builder',
        'canvas_styles' => null,
        'favicon' => null,
        'datatables_language' => null,
    ],

    // 側選單兩個頂層分組的名稱（migration 與 DocTypeService 以名稱找到這兩列）
    'menu' => [
        'builder' => '單據建置',
        'records' => '自訂單據',
    ],

    // 欄位型別：代碼 => 顯示名稱
    'field_types' => [
        'text' => '單行文字',
        'textarea' => '多行文字',
        'number' => '數字',
        'amount' => '金額／貨幣',
        'date' => '日期',
        'checkbox' => '勾選',
        'select' => '固定選項',
        'radio' => '單選按鈕',
        'checkgroup' => '複選',
        'multiselect' => '多選下拉',
        'master' => '主檔下拉',
    ],

    // 欄寬（Bootstrap 12 欄格線）
    'widths' => [3 => '1/4', 4 => '1/3', 6 => '1/2', 12 => '整列'],

    /*
     * 主檔來源白名單：管理員只能從這裡挑，不能輸入任意資料表。宿主專案自行註冊，例如：
     * 'customer' => ['label' => '客戶', 'model' => App\Models\Customer::class, 'code' => 'no', 'text' => 'name',
     *     'where' => ['customer_type_id' => 1], 'active' => []],
     * where：身分條件，搜尋與存檔驗證都套用；active：只在搜尋時套用（停用資料不出現在下拉，但舊單據仍可存檔）
     * extras：附隨帶入，例如 'extras' => ['suggestion_price' => 'suggested_price']：選了主檔後，同一列中代碼為
     *     suggestion_price 的欄位會被填入並改為唯讀，清空主檔時一併清空。右邊是模型欄位（單據來源則是單據資料的欄位代碼）。
     */
    'masters' => [],

    /*
     * 來源庫：管理員在後台「來源庫」頁面新增主檔來源，和上面的 masters 合併使用（同代碼時以 masters 為準）。
     * drivers：可用的來源驅動（實作 DocumentBuilder\Contracts\SourceDriver），可加上自己寫的驅動。
     * models：「資料模型」驅動可以選的 model；null 表示自動找出 app/Models 底下的所有 Eloquent model。
     * hidden_columns：任何 model 都不開放的欄位；model 的 $hidden 也一律不開放。
     */
    'sources' => [
        'drivers' => [
            DocumentBuilder\Sources\ModelSourceDriver::class,
            DocumentBuilder\Sources\DocumentSourceDriver::class,
        ],
        'models' => null,
        'hidden_columns' => ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'api_token'],
    ],

    // 主檔下拉每次搜尋最多回傳筆數
    'master_search_limit' => 20,
];
