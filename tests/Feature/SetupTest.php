<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\Integrations\NullIntegration;
use DocumentBuilder\Models\DocAdmin;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Tests\Fixtures\User;
use DocumentBuilder\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/** 網頁安裝精靈：不執行任何指令，在網頁上完成安裝與超級管理員設定 */
class SetupTest extends TestCase
{
    protected bool $hostDefinesSuperAdmin = false;

    private string $configDir;

    protected function setUp(): void
    {
        parent::setUp();
        // 設定檔寫到暫存目錄，不動到 testbench 自己的 config
        $this->configDir = sys_get_temp_dir().'/document_builder_config_'.uniqid();
        mkdir($this->configDir);
        $this->app->useConfigPath($this->configDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->configDir.'/*') ?: []);
        @rmdir($this->configDir);
        parent::tearDown();
    }

    /** 模擬剛 composer require、還沒建立套件資料表的專案 */
    private function uninstall(): void
    {
        $files = collect(glob(__DIR__.'/../../database/migrations/*.php'))->map(fn (string $f): string => basename($f, '.php'));
        Schema::disableForeignKeyConstraints();
        foreach (['doc_admins', 'doc_sources', 'doc_type_revision_reviews', 'doc_mcp_operations', 'doc_record_items', 'doc_records', 'doc_sequences', 'doc_statuses', 'doc_fields', 'doc_type_revisions', 'doc_types'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
        DB::table('migrations')->whereIn('migration', $files)->delete();
    }

    /** local：模擬本機開發環境（不用 token）；其他：模擬正式環境 */
    private function env(string $environment): void
    {
        config()->set('document_builder.setup.open_environments', $environment === 'local' ? ['testing'] : []);
    }

    private function settings(array $override = []): array
    {
        return array_replace_recursive([
            'guard' => 'web',
            'user_model' => User::class,
            'users_table' => 'users',
            'user_key_type' => 'int',
            'user_name_column' => 'name',
            'layout' => 'document-builder::layouts.app',
            'route' => ['prefix' => '', 'name' => '', 'middleware' => 'web,auth'],
            'integration' => NullIntegration::class,
        ], $override);
    }

    public function test_還沒安裝時_頁面導向安裝精靈(): void
    {
        $this->uninstall();
        $user = $this->makeUser();

        $this->actingAs($user)->get(route('document_builder.home'))->assertRedirect(route('document_builder.setup'));
        $this->actingAs($user)->getJson(route('doc_records.index', 'leave'))->assertStatus(503);
        $this->actingAs($user)->get(route('document_builder.setup'))->assertOk()
            ->assertSee('環境與相依性檢查')->assertSee('帳號體系與版型')->assertSee('開始初始化安裝');
    }

    public function test_本機環境_走完精靈_成為第一位超級管理員並寫出設定檔(): void
    {
        $this->env('local');
        $this->uninstall();
        $installer = $this->makeUser();

        $this->actingAs($installer)->postJson(route('document_builder.setup.check'))->assertOk()->assertJsonPath('passed', true);
        $this->actingAs($installer)->getJson(route('document_builder.setup.model', ['model' => User::class]))
            ->assertOk()->assertJsonPath('table', 'users')->assertJsonPath('key_type', 'int')->assertJsonPath('name_column', 'name');

        $preview = $this->actingAs($installer)->postJson(route('document_builder.setup.settings'), $this->settings(['route' => ['prefix' => 'admin']]))
            ->assertOk()->json();
        $this->assertStringContainsString("'prefix' => 'admin'", $preview['config']);
        $this->assertStringEndsWith('/admin/document_builder/setup', $preview['url']);
        // 重新打開精靈時帶出剛才填的值
        $this->actingAs($installer)->get(route('document_builder.setup'))->assertOk()->assertSee('value="web,auth"', false)->assertSee('value="admin"', false);

        $this->actingAs($installer)->postJson(route('document_builder.setup.run', 'migrate'))->assertOk()->assertJsonPath('ok', true);
        $this->assertTrue(Schema::hasTable('doc_types'));
        $this->actingAs($installer)->postJson(route('document_builder.setup.run', 'admin'))->assertOk();
        $this->assertTrue(DocumentBuilder::isSuperAdmin($installer));
        $this->actingAs($installer)->postJson(route('document_builder.setup.run', 'config'))->assertOk()->assertJsonPath('ok', true);

        $written = require $this->configDir.'/document_builder.php';
        $this->assertSame('admin', $written['route']['prefix']);
        $this->assertSame(['web', 'auth'], $written['route']['middleware']);
        $this->assertArrayNotHasKey('setup', $written, 'token 留在 .env，不寫進設定檔');

        // 安裝完成後精靈鎖定：別人進不來，也不能再認領
        $other = $this->makeUser();
        $this->actingAs($other)->get(route('document_builder.setup'))->assertNotFound();
        $this->actingAs($other)->postJson(route('document_builder.setup.run', 'admin'))->assertForbidden();
    }

    public function test_正式環境要先輸入_env_裡的初始化_token(): void
    {
        $this->env('production');
        $user = $this->makeUser();

        $this->actingAs($user)->get(route('document_builder.setup'))->assertOk()->assertSee('DOCUMENT_BUILDER_SETUP_TOKEN');
        $this->actingAs($user)->postJson(route('document_builder.setup.check'))
            ->assertStatus(422)->assertJsonPath('errors.token.0', '這個環境要先在 .env 設定 DOCUMENT_BUILDER_SETUP_TOKEN');

        config()->set('document_builder.setup.token', 's3cret-token');
        $this->actingAs($user)->postJson(route('document_builder.setup.check'), ['token' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('errors.token.0', '初始化 token 不正確');
        $this->actingAs($user)->postJson(route('document_builder.setup.settings'), $this->settings())->assertForbidden();

        $this->actingAs($user)->postJson(route('document_builder.setup.check'), ['token' => 's3cret-token'])->assertOk();
        $this->actingAs($user)->postJson(route('document_builder.setup.settings'), $this->settings())->assertOk();
        $this->actingAs($user)->postJson(route('document_builder.setup.run', 'admin'))->assertOk();
        $this->assertTrue(DocumentBuilder::isSuperAdmin($user));
    }

    public function test_精靈設定會檢查版型存在與_guard_有登入(): void
    {
        $this->env('local');
        $user = $this->makeUser();

        $this->actingAs($user)->postJson(route('document_builder.setup.settings'), $this->settings(['layout' => 'layouts.not-exists']))
            ->assertStatus(422)->assertJsonValidationErrors('layout');
        $this->actingAs($user)->postJson(route('document_builder.setup.settings'), $this->settings(['integration' => 'App\\Nope']))
            ->assertStatus(422)->assertJsonValidationErrors('integration');

        config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
        $this->actingAs($user)->postJson(route('document_builder.setup.settings'), $this->settings(['guard' => 'admin']))
            ->assertStatus(422)->assertJsonValidationErrors('guard');
    }

    public function test_設定檔保留既有的其他設定(): void
    {
        $this->env('local');
        file_put_contents($this->configDir.'/document_builder.php', "<?php return ['masters' => ['customer' => ['label' => '客戶']], 'master_search_limit' => 50];");
        $user = $this->makeUser();

        $config = $this->actingAs($user)->postJson(route('document_builder.setup.settings'), $this->settings())->assertOk()->json('config');
        $this->assertStringContainsString("'customer' =>", $config);
        $this->assertStringContainsString("'master_search_limit' => 50", $config);
    }

    public function test_匯入範本_建立樣品申請單並可以打開設計器(): void
    {
        $admin = $this->makeUser();
        DocAdmin::query()->create(['user_id' => $admin->getKey()]);

        $url = $this->actingAs($admin)->postJson(route('document_builder.setup.sample'))->assertOk()->json('url');
        $type = DocType::query()->where('code', 'sample')->firstOrFail();
        $this->assertSame('樣品申請單', $type->name);
        $this->assertCount(6, $type->fields);
        $this->actingAs($admin)->get($url)->assertOk();

        // 再匯入一次不會撞代碼
        $this->actingAs($admin)->postJson(route('document_builder.setup.sample'))->assertOk();
        $this->assertTrue(DocType::query()->where('code', 'sample_2')->exists());
    }

    public function test_超級管理員在精靈的完成頁管理名單_至少留一位(): void
    {
        $admin = $this->makeUser(['name' => '主管理員']);
        DocAdmin::query()->create(['user_id' => $admin->getKey()]);
        $colleague = $this->makeUser(['name' => '王設計', 'email' => 'wang@example.com']);

        $found = $this->actingAs($admin)->getJson(route('document_builder.setup.users').'?search=wang')->assertOk()->json();
        $this->assertSame($colleague->getKey(), $found[0]['id']);

        $this->actingAs($admin)->postJson(route('document_builder.setup.admins.store'), ['user_id' => $colleague->getKey()])->assertOk();
        $this->assertTrue(DocumentBuilder::isSuperAdmin($colleague));
        $this->actingAs($admin)->get(route('document_builder.setup'))->assertOk()->assertSee('王設計');

        $this->actingAs($admin)->deleteJson(route('document_builder.setup.admins.destroy', DocAdmin::query()->where('user_id', $colleague->getKey())->first()))->assertOk();
        $this->actingAs($admin)->deleteJson(route('document_builder.setup.admins.destroy', DocAdmin::query()->first()))
            ->assertStatus(422)->assertJsonValidationErrors('admin');

        $this->actingAs($colleague)->postJson(route('document_builder.setup.admins.store'), ['user_id' => $colleague->getKey()])->assertForbidden();
    }

    public function test_套件更新後有新的資料表_超級管理員套用_一般使用者看到等待訊息(): void
    {
        $admin = $this->makeUser();
        DocAdmin::query()->create(['user_id' => $admin->getKey()]);
        Schema::drop('doc_sources');
        DB::table('migrations')->where('migration', '2026_10_12_000000_create_doc_sources_table')->delete();

        $user = $this->makeUser();
        $this->actingAs($user)->get(route('document_builder.home'))->assertRedirect(route('document_builder.setup'));
        $this->actingAs($user)->get(route('document_builder.setup'))->assertOk()->assertSee('請超級管理員到這一頁完成更新');
        $this->actingAs($user)->postJson(route('document_builder.setup.run', 'migrate'))->assertForbidden();

        $this->actingAs($admin)->postJson(route('document_builder.setup.run', 'migrate'))->assertOk();
        $this->assertTrue(Schema::hasTable('doc_sources'));
        $this->actingAs($user)->get(route('document_builder.home'))->assertOk();
    }

    public function test_宿主用程式定義超級管理員時_以程式為準(): void
    {
        $this->env('local');
        Gate::define('document-builder.super-admin', fn ($user): bool => $user->email === 'boss@example.com');
        $boss = $this->makeUser(['email' => 'boss@example.com']);

        $this->actingAs($this->makeUser())->get(route('document_builder.setup'))->assertNotFound();
        $this->actingAs($boss)->get(route('document_builder.setup'))->assertOk()->assertSee('由專案的程式定義');
        $this->actingAs($boss)->postJson(route('document_builder.setup.settings'), $this->settings())->assertOk();
        $this->actingAs($boss)->postJson(route('document_builder.setup.run', 'admin'))->assertOk()->assertJsonPath('log', '超級管理員由專案的程式定義（document-builder.super-admin Gate），略過');
        $this->assertSame(0, DocAdmin::query()->count());
    }

    public function test_前端資產由套件路由提供_不必發布(): void
    {
        $this->get(route('document_builder.asset', ['path' => 'vendor/bootstrap-5.3.3/bootstrap.min.css']))
            ->assertOk()->assertHeader('Cache-Control')
            ->tap(fn ($response) => $this->assertStringStartsWith('text/css', (string) $response->headers->get('Content-Type')));
        $this->get(route('document_builder.asset', ['path' => '../../composer.json']))->assertNotFound();
        $this->get(route('document_builder.asset', ['path' => 'missing.js']))->assertNotFound();

        $html = $this->actingAs($this->makeUser())->get(route('document_builder.setup'))->getContent();
        $this->assertStringContainsString('/document_builder/assets/vendor/jquery-3.7.1/jquery.min.js?v=', $html);
        $this->assertStringContainsString('/document_builder/assets/css/install.css?v=', $html);
    }
}
