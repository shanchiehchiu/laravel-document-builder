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
     * 使用者：從哪個 guard 取得登入者、用哪個 model、資料表叫什麼（版本與審核紀錄的外鍵指向這張表）。
     * 誰是超級管理員由宿主定義 Gate：document-builder.super-admin（預設沒有人是）。
     */
    'guard' => 'web',
    'user_model' => 'App\Models\User',
    'users_table' => 'users',

    /*
     * 宿主後台整合（側選單、一般使用者能不能檢視某種單據），實作 DocumentBuilder\Contracts\Integration。
     * 要接自家後台的選單與權限，就在專案裡實作這個介面，把類別名稱填在這裡。
     */
    'integration' => DocumentBuilder\Integrations\NullIntegration::class,

    // 套件頁面要 extends 的版型；需提供 content 區塊與 scripts 堆疊。預設是套件附的極簡版型
    'layout' => 'document-builder::layouts.app',

    /*
     * 前端資產。url：套件資產在 public 底下的位置（vendor:publish --tag=document-builder-assets 發布到這裡）。
     * canvas_styles：設計器畫布要載入的版面樣式，讓畫布長得和填單畫面一樣；可填完整網址或 public 底下的路徑。
     * favicon、datatables_language：選填，沒設定就不載入。
     */
    'assets' => [
        'url' => 'vendor/document-builder',
        'canvas_styles' => ['https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'],
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
        'date' => '日期',
        'checkbox' => '勾選',
        'select' => '固定選項',
        'master' => '主檔下拉',
    ],

    // 欄寬（Bootstrap 12 欄格線）
    'widths' => [3 => '1/4', 4 => '1/3', 6 => '1/2', 12 => '整列'],

    /*
     * 主檔來源白名單：管理員只能從這裡挑，不能輸入任意資料表。宿主專案自行註冊，例如：
     * 'customer' => ['label' => '客戶', 'model' => App\Models\Customer::class, 'code' => 'no', 'text' => 'name',
     *     'where' => ['customer_type_id' => 1], 'active' => []],
     * where：身分條件，搜尋與存檔驗證都套用；active：只在搜尋時套用（停用資料不出現在下拉，但舊單據仍可存檔）
     */
    'masters' => [],

    // 主檔下拉每次搜尋最多回傳筆數
    'master_search_limit' => 20,
];
