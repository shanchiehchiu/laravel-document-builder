<?php

declare(strict_types=1);

namespace DocumentBuilder;

use DocumentBuilder\Contracts\Integration;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** 單據建置套件的進入點：註冊設定、路由、視圖、migration、指令與可發布的資產 */
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
        // 預設沒有人是超級管理員；宿主在自己的 AuthServiceProvider 重新定義（宿主的 provider 比套件晚啟動，會蓋過這裡）
        if (! Gate::has(DocumentBuilder::SUPER_ADMIN_ABILITY)) {
            Gate::define(DocumentBuilder::SUPER_ADMIN_ABILITY, fn (): bool => false);
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/document-builder.php');
        // migration 以檔名記錄是否執行過，從專案搬進套件的檔案不會重跑
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'document-builder');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\McpStdioCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/document_builder.php' => config_path('document_builder.php'),
                __DIR__.'/../config/form_platform.php' => config_path('form_platform.php'),
            ], 'document-builder-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/document-builder'),
            ], 'document-builder-views');

            $this->publishes([
                __DIR__.'/../resources/dist' => public_path('vendor/document-builder'),
            ], 'document-builder-assets');
        }
    }
}
