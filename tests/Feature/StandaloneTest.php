<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Models\DocRecord;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/** 在沒有任何宿主畫面的乾淨 Laravel 專案裡，套件自己就能完整運作 */
class StandaloneTest extends TestCase
{
    public function test_自帶介面從套件載入函式庫_不依賴宿主畫面或_cdn(): void
    {
        $html = $this->actingAs($this->makeUser())->get(route('document_builder.home'))->assertOk()->getContent();

        $this->assertStringContainsString('/document_builder/assets/vendor/jquery-3.7.1/jquery.min.js', $html);
        $this->assertStringContainsString('/document_builder/assets/css/app.css', $html);
        $this->assertStringContainsString('/document_builder/assets/css/theme.css', $html);
        $this->assertStringNotContainsString('cdn.', $html);
        $this->assertStringContainsString('我的單據', $html);
    }

    public function test_宿主用_zh_TW_或英文語系時介面跟著切換(): void
    {
        $user = $this->makeUser();

        app()->setLocale('zh_TW');
        $this->actingAs($user)->get(route('document_builder.home'))->assertOk()->assertSee('我的單據');

        app()->setLocale('en');
        $this->actingAs($user)->get(route('document_builder.home'))->assertOk()->assertSee('My documents');
    }

    public function test_未登入會導向宿主的登入頁(): void
    {
        $this->get(route('document_builder.home'))->assertRedirect(route('login'));
    }

    public function test_首頁只列出啟用且有權限填寫的單據(): void
    {
        $this->makeType('leave', [], '請假單');
        $this->makeType('expense', [], '費用報銷單');
        $this->makeType('closed', [], '停用的單據')->forceFill(['status' => false])->save();

        // 宿主用 view-type Gate 限制：只有請假單開放給一般使用者
        Gate::define('document-builder.view-type', fn ($user, DocType $type): bool => $type->code === 'leave');

        $this->actingAs($this->makeUser())->get(route('document_builder.home'))
            ->assertOk()->assertSee('請假單')->assertDontSee('費用報銷單')->assertDontSee('停用的單據');

        $this->actingAs($this->makeUser())->get(route('doc_records.index', 'expense'))->assertForbidden();
    }

    public function test_設計功能預設只開放給超級管理員(): void
    {
        $type = $this->makeType();

        $user = $this->makeUser();
        $this->actingAs($user)->get(route('document_builder.types.index'))->assertForbidden();
        $this->actingAs($user)->get(route('document_builder.designer.edit', $type))->assertForbidden();

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('document_builder.types.index'))->assertOk();
        $this->actingAs($admin)->get(route('document_builder.designer.edit', $type))->assertOk();
    }

    public function test_宿主可以另外開放設計權限給非超級管理員(): void
    {
        Gate::define('document-builder.design', fn ($user): bool => $user->email === 'designer@example.com');

        $this->actingAs($this->makeUser(['email' => 'designer@example.com']))
            ->get(route('document_builder.types.index'))->assertOk();
        $this->actingAs($this->makeUser())->get(route('document_builder.types.index'))->assertForbidden();
    }

    public function test_一般使用者可以填單_驗證錯誤回傳_422(): void
    {
        $this->makeType('leave', [
            ['key' => 'reason', 'label' => '事由', 'type' => 'textarea', 'required' => true, 'show_in_list' => true],
            ['key' => 'days', 'label' => '天數', 'type' => 'number', 'decimals' => 1],
        ]);
        $user = $this->makeUser(['name' => '王小明']);

        $this->actingAs($user)->get(route('doc_records.create', 'leave'))->assertOk()->assertSee('事由');

        $this->actingAs($user)->postJson(route('doc_records.store', 'leave'), ['doc_date' => '2026-10-12', 'data' => ['days' => '1']])
            ->assertStatus(422)->assertJsonValidationErrors('data.reason');

        $id = $this->actingAs($user)->postJson(route('doc_records.store', 'leave'), [
            'doc_date' => '2026-10-12', 'data' => ['reason' => '家庭因素', 'days' => '1.5'],
        ])->assertOk()->json('id');

        $record = DocRecord::query()->findOrFail($id);
        $this->assertSame('家庭因素', $record->value('reason'));
        $this->assertSame((string) $user->getKey(), (string) $record->created_by);

        // 列表顯示建立者名稱（名稱欄位可設定，不假設使用者表一定有 name）
        $this->actingAs($user)->getJson(route('doc_records.index', 'leave').'?draw=1&start=0&length=10', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJsonPath('data.0.creator_name', '王小明');

        // 詳情頁唯讀：不出現送出鈕，並由套件自己停用欄位（不依賴宿主版型）；修改頁照常可以送出
        $save = '>'.__('document-builder::ui.save').'</button>';
        $this->actingAs($user)->get(route('doc_records.show', ['leave', $id]))->assertOk()
            ->assertDontSee($save, false)->assertSee("prop('disabled', true)", false);
        $this->actingAs($user)->get(route('doc_records.edit', ['leave', $id]))->assertOk()
            ->assertSee($save, false)->assertDontSee("find('[name]').not('[name=_token], [name=_method]').prop('disabled', true)", false);
    }
}
