<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests;

use DocumentBuilder\DocumentBuilderServiceProvider;
use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Services\DocFieldService;
use DocumentBuilder\Services\DocTypeService;
use DocumentBuilder\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Orchestra;
use Yajra\DataTables\DataTablesServiceProvider;

/**
 * 套件測試的基底：在乾淨的 Laravel 環境（testbench）裡安裝套件，
 * 不依賴任何宿主專案的模型、畫面或資料。子類別可換成不同主鍵型別的使用者。
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** 使用者 model；UUID 測試改成 UuidUser */
    protected string $userModel = User::class;

    protected string $userKeyType = 'int';

    protected function getPackageProviders($app): array
    {
        return [DataTablesServiceProvider::class, DocumentBuilderServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.locale', 'zh-Hant');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', $this->userModel);
        $app['config']->set('document_builder.user_model', $this->userModel);
        $app['config']->set('document_builder.user_key_type', $this->userKeyType);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function defineRoutes($router): void
    {
        // 宿主專案通常有的登入、登出路由；auth 中介層未登入時會導向 login
        Route::get('login', fn () => 'login')->name('login');
        Route::post('logout', fn () => 'logout')->name('logout');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('document-builder.super-admin', fn ($user): bool => (bool) $user->is_admin);
    }

    protected function makeUser(array $attributes = []): Model
    {
        static $sequence = 0;
        $sequence++;

        return $this->userModel::query()->create($attributes + [
            'name' => '使用者'.$sequence,
            'email' => "user{$sequence}@example.com",
            'password' => bcrypt('password'),
            'is_admin' => false,
        ]);
    }

    protected function makeAdmin(array $attributes = []): Model
    {
        return $this->makeUser($attributes + ['name' => '管理員', 'is_admin' => true]);
    }

    /** 透過 Service 建立單據種類與表頭欄位 */
    protected function makeType(string $code = 'leave', array $fields = [], string $name = '請假單'): DocType
    {
        $type = app(DocTypeService::class)->create(['code' => $code, 'name' => $name]);

        foreach ($fields as $field) {
            app(DocFieldService::class)->create($type, $field + ['section' => DocField::SECTION_HEADER, 'width' => 4]);
        }

        return $type->fresh();
    }
}
