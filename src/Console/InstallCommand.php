<?php

declare(strict_types=1);

namespace DocumentBuilder\Console;

use DocumentBuilder\Integrations\NullIntegration;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * 安裝精靈：詢問宿主的使用者、版型、路由與選單整合方式，寫出 config/document_builder.php，
 * 發布前端資產，最後提示要在 AuthServiceProvider 定義的 Gate。
 */
class InstallCommand extends Command
{
    protected $signature = 'document-builder:install
        {--path= : 設定檔寫入位置（預設 config/document_builder.php）}
        {--force : 設定檔已存在時直接覆寫}
        {--no-assets : 不發布前端資產}
        {--no-migrate : 不執行 migration}';

    protected $description = '安裝單據建置：產生設定檔、發布前端資產並執行 migration';

    public function handle(Filesystem $files): int
    {
        $path = $this->option('path') ?: config_path('document_builder.php');

        if ($files->exists($path) && ! $this->option('force')
            && ! $this->confirm("設定檔 {$path} 已存在，要覆寫嗎？", false)) {
            $this->warn('保留既有設定檔，略過設定步驟。');
        } else {
            $files->put($path, $this->buildConfig($files->get(__DIR__.'/../../config/document_builder.php')));
            $this->info("已寫入設定檔：{$path}");
        }

        if (! $this->option('no-assets')) {
            $this->call('vendor:publish', ['--tag' => 'document-builder-assets', '--force' => true]);
        }

        if (! $this->option('no-migrate') && $this->confirm('現在執行 migration 嗎？', true)) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->line('最後一步：在 AuthServiceProvider（Laravel 11 以後是 AppServiceProvider）的 boot() 定義權限，例如：');
        $this->line("    Gate::define('document-builder.super-admin', fn (\$user) => \$user->is_admin);");
        $this->line('超級管理員才能發布、審核、還原版本與透過 MCP 修改草稿；沒有定義時沒有人能做這些事。');
        $this->line('選用：document-builder.design 決定誰能設計單據（預設等於超級管理員），');
        $this->line('document-builder.view-type 決定誰能填寫某種單據（預設登入者都可以）。');
        $this->newLine();
        $this->line('介面網址：'.url(trim((string) config('document_builder.route.prefix'), '/').'/document_builder'));
        $this->line('套件更新後執行 php artisan vendor:publish --tag=document-builder-assets --force 更新前端資產。');

        return self::SUCCESS;
    }

    /** 以套件預設設定檔為底，只替換這些問題對應的那幾行，保留註解 */
    private function buildConfig(string $template): string
    {
        $guard = $this->ask('使用者從哪個 guard 登入？', 'web');
        $userModel = $this->ask('使用者 model？', 'App\\Models\\User');
        $usersTable = $this->ask('使用者資料表？', 'users');
        $keyType = $this->choice('使用者主鍵型別？', ['int', 'uuid', 'ulid'], $this->detectUserKeyType((string) $userModel));
        $nameColumn = $this->ask('使用者名稱欄位？', 'name');
        $layout = $this->ask('頁面要 extends 哪個版型？（需提供 content 區塊與 scripts 堆疊）', 'document-builder::layouts.app');
        $prefix = $this->ask('路由網址前綴？（例如 admin，留空表示沒有）', '');
        $name = $this->ask('路由名稱前綴？（例如 admin.，留空表示沒有）', '');
        $middleware = $this->ask('路由中介層？（逗號分隔，要包含登入檢查）', 'web,auth');
        // 要接自家後台的選單與權限，就填專案裡實作 Integration 的類別；預設不整合
        $integration = $this->ask('側選單與權限的整合類別？（實作 DocumentBuilder\\Contracts\\Integration）', NullIntegration::class);

        $middlewareList = array_values(array_filter(array_map('trim', explode(',', (string) $middleware))));
        $integrationClass = ltrim(trim((string) $integration), '\\') ?: NullIntegration::class;

        $replacements = [
            "        'prefix' => '',\n" => "        'prefix' => ".var_export((string) $prefix, true).",\n",
            "        'name' => '',\n" => "        'name' => ".var_export((string) $name, true).",\n",
            "        'middleware' => ['web', 'auth'],\n" => "        'middleware' => ".$this->exportList($middlewareList).",\n",
            "    'guard' => 'web',\n" => "    'guard' => ".var_export((string) $guard, true).",\n",
            "    'user_model' => 'App\\Models\\User',\n" => "    'user_model' => ".ltrim((string) $userModel, '\\')."::class,\n",
            "    'users_table' => 'users',\n" => "    'users_table' => ".var_export((string) $usersTable, true).",\n",
            "    'user_key_type' => 'int',\n" => "    'user_key_type' => ".var_export((string) $keyType, true).",\n",
            "    'user_name_column' => 'name',\n" => "    'user_name_column' => ".var_export((string) $nameColumn, true).",\n",
            "    'integration' => DocumentBuilder\\Integrations\\NullIntegration::class,\n" => "    'integration' => {$integrationClass}::class,\n",
            "    'layout' => 'document-builder::layouts.app',\n" => "    'layout' => ".var_export((string) $layout, true).",\n",
        ];

        foreach ($replacements as $search => $replace) {
            if (substr_count($template, $search) !== 1) {
                throw new \RuntimeException('套件預設設定檔的格式和安裝精靈不一致：'.trim($search));
            }
            $template = str_replace($search, $replace, $template);
        }

        return $template;
    }

    /** 從使用者 model 推測主鍵型別：用了 HasUuids／HasUlids 或主鍵是字串時是 uuid／ulid，否則是 int */
    private function detectUserKeyType(string $userModel): string
    {
        $class = ltrim($userModel, '\\');
        if (! class_exists($class)) {
            return 'int';
        }

        $traits = class_uses_recursive($class);
        if (in_array('Illuminate\\Database\\Eloquent\\Concerns\\HasUlids', $traits, true)) {
            return 'ulid';
        }
        if (in_array('Illuminate\\Database\\Eloquent\\Concerns\\HasUuids', $traits, true)) {
            return 'uuid';
        }

        return (new $class)->getKeyType() === 'string' ? 'uuid' : 'int';
    }

    private function exportList(array $items): string
    {
        return '['.implode(', ', array_map(static fn (string $item): string => var_export($item, true), $items)).']';
    }
}
