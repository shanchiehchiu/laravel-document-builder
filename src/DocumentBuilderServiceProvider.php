<?php

declare(strict_types=1);

namespace DocumentBuilder;

use DocumentBuilder\Contracts\Integration;
use DocumentBuilder\Http\Middleware\EnsureInstalled;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** 單據建置套件的進入點：註冊設定、路由、視圖、migration 與預設權限；安裝與初始化都在網頁的初始化頁完成 */
class DocumentBuilderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 宿主專案發布的設定只需覆寫要改的頂層鍵，其餘沿用套件預設
        $this->mergeConfigFrom(__DIR__.'/../config/document_builder.php', 'document_builder');
        $this->mergeConfigFrom(__DIR__.'/../config/form_platform.php', 'form_platform');

        $this->app->bind(Integration::class, fn ($app) => $app->make(config('document_builder.integration')));
    }

    public function boot(): void
    {
        // 預設：超級管理員看初始化頁設定的名單（doc_admins），只有超級管理員能設計單據。
        // 宿主在自己的 AuthServiceProvider／AppServiceProvider 重新定義即可（宿主的 provider 比套件晚啟動，會蓋過這裡）
        if (! Gate::has(DocumentBuilder::SUPER_ADMIN_ABILITY)) {
            Gate::define(DocumentBuilder::SUPER_ADMIN_ABILITY, DocumentBuilder::defaultSuperAdminGate());
        }
        if (! Gate::has(DocumentBuilder::DESIGN_ABILITY)) {
            Gate::define(DocumentBuilder::DESIGN_ABILITY, fn ($user): bool => DocumentBuilder::isSuperAdmin($user));
        }

        // 初始化檢查要比路由模型綁定先執行：資料表還沒建立時，綁定 {docType} 會直接出錯
        $kernel = $this->app->make(HttpKernel::class);
        if (method_exists($kernel, 'prependToMiddlewarePriority')) {
            $kernel->prependToMiddlewarePriority(EnsureInstalled::class);
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/document-builder.php');
        // migration 以檔名記錄是否執行過，從專案搬進套件的檔案不會重跑
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'document-builder');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'document-builder');

        if ($this->app->runningInConsole()) {
            $this->commands([Console\McpStdioCommand::class]);

            $this->publishes([
                __DIR__.'/../config/document_builder.php' => config_path('document_builder.php'),
                __DIR__.'/../config/form_platform.php' => config_path('form_platform.php'),
            ], 'document-builder-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/document-builder'),
            ], 'document-builder-views');

            // 選用：前端資產平常由套件路由直接提供；想交給網頁伺服器提供靜態檔時再發布
            $this->publishes([
                __DIR__.'/../resources/dist' => public_path('vendor/document-builder'),
            ], 'document-builder-assets');
        }
    }
}
