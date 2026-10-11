<?php

declare(strict_types=1);

use DocumentBuilder\Http\Controllers\AppearanceController;
use DocumentBuilder\Http\Controllers\AssetController;
use DocumentBuilder\Http\Controllers\DocDesignerController;
use DocumentBuilder\Http\Controllers\DocRecordController;
use DocumentBuilder\Http\Controllers\DocTypeController;
use DocumentBuilder\Http\Controllers\FormPlatform\FormPlatformMcpController;
use DocumentBuilder\Http\Controllers\FormPlatform\FormRevisionController;
use DocumentBuilder\Http\Controllers\FormPlatform\FormSchemaController;
use DocumentBuilder\Http\Controllers\HomeController;
use DocumentBuilder\Http\Controllers\SetupController;
use DocumentBuilder\Http\Controllers\SourceController;
use DocumentBuilder\Http\Middleware\EnsureCanDesign;
use DocumentBuilder\Http\Middleware\EnsureInstalled;
use Illuminate\Support\Facades\Route;

// 前端資產：不需要登入，也不經過 session（只是靜態檔）
Route::prefix(config('document_builder.route.prefix'))
    ->name(config('document_builder.route.name'))
    ->get('document_builder/assets/{path}', [AssetController::class, 'show'])
    ->where('path', '.*')
    ->name('document_builder.asset');

Route::middleware(config('document_builder.route.middleware'))
    ->prefix(config('document_builder.route.prefix'))
    ->name(config('document_builder.route.name'))
    ->group(function () {
        // 安裝精靈：環境檢查、帳號與版型、路由與整合、執行安裝、完成；資料表還沒建立時也要能進來，所以不經過 EnsureInstalled
        Route::prefix('document_builder/setup')->name('document_builder.setup')->controller(SetupController::class)->group(function () {
            Route::get('/', 'show');
            Route::post('check', 'check')->name('.check');
            Route::get('model', 'model')->name('.model');
            Route::post('settings', 'settings')->name('.settings');
            Route::post('run/{task}', 'run')->whereIn('task', ['migrate', 'admin', 'config'])->name('.run');
            Route::post('sample', 'sample')->name('.sample');
            Route::get('users', 'users')->name('.users');
            Route::post('admins', 'addAdmin')->name('.admins.store');
            Route::delete('admins/{admin}', 'removeAdmin')->name('.admins.destroy');
        });

        // 以下頁面都要先完成初始化，否則導向初始化頁
        Route::middleware(EnsureInstalled::class)->group(function () {
            // 單據建置：管理員在後台定義單據種類與欄位
            Route::prefix('document_builder')->name('document_builder.')->group(function () {
                // 首頁：列出目前使用者能填寫的單據；主檔下拉搜尋給填單畫面使用
                Route::get('/', [HomeController::class, 'index'])->name('home');
                Route::get('masters/{source}', [DocRecordController::class, 'master'])->name('masters');

                // 以下是設計功能，只開放給 document-builder.design 的使用者
                Route::middleware(EnsureCanDesign::class)->group(function () {
                    Route::resource('types', DocTypeController::class)->except(['show', 'update']);

                    // 表單設計器（全螢幕 GrapesJS）
                    Route::get('types/{type}/designer', [DocDesignerController::class, 'edit'])->name('designer.edit');
                    Route::put('types/{type}/designer', [DocDesignerController::class, 'update'])->name('designer.update');
                    Route::get('types/{type}/schema', [FormSchemaController::class, 'show'])->name('schema.show');
                    Route::post('types/{type}/schema/validate', [FormSchemaController::class, 'validateSchema'])->name('schema.validate');
                    Route::post('types/{type}/schema/preview', [FormSchemaController::class, 'preview'])->name('schema.preview');
                    Route::get('types/{type}/preview', [FormSchemaController::class, 'previewPage'])->name('types.preview');
                    Route::get('types/{type}/revisions', [FormRevisionController::class, 'index'])->name('revisions.index');
                    Route::get('types/{type}/revisions/{revision}', [FormRevisionController::class, 'show'])->name('revisions.show');
                    Route::post('types/{type}/revisions/{revision}/review', [FormRevisionController::class, 'review'])->name('revisions.review');
                    Route::post('types/{type}/publish', [FormRevisionController::class, 'publish'])->name('revisions.publish');
                    Route::post('types/{type}/revisions/{revision}/restore', [FormRevisionController::class, 'restore'])->name('revisions.restore');
                    Route::match(['GET', 'POST', 'DELETE', 'OPTIONS'], 'mcp', [FormPlatformMcpController::class, 'handle'])->name('mcp');

                    // 外觀設定：按鈕主色（控制器內再檢查超級管理員）
                    Route::get('appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');
                    Route::put('appearance', [AppearanceController::class, 'update'])->name('appearance.update');

                    // 來源庫：主檔下拉的資料來源
                    Route::get('sources/columns', [SourceController::class, 'columns'])->name('sources.columns');
                    Route::get('sources/document-fields/{type:code}', [SourceController::class, 'documentFields'])->name('sources.document_fields');
                    Route::post('sources/preview', [SourceController::class, 'preview'])->name('sources.preview');
                    Route::resource('sources', SourceController::class)->except(['show']);
                });
            });

            // 各自訂單據：共用 DocRecordController；scopeBindings 讓 {record} 經由 $docType->records() 查詢，
            // 不屬於該種單據時 404（父參數用自訂鍵並不會自動限定子參數）
            Route::scopeBindings()->prefix('doc_records/{docType:code}')->name('doc_records.')->controller(DocRecordController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::patch('{record}/status', 'changeStatus')->name('status');
                Route::get('{record}', 'show')->name('show');
                Route::get('{record}/edit', 'edit')->name('edit');
                Route::put('{record}', 'update')->name('update');
                Route::delete('{record}', 'destroy')->name('destroy');
            });
        });
    });
