<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\FormPlatform\FormRevisionService;
use DocumentBuilder\Models\DocRecord;
use DocumentBuilder\Tests\Fixtures\UuidUser;
use DocumentBuilder\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** 宿主的使用者主鍵是 UUID 時，套件資料表與填單、版本流程都要能用 */
class UuidUserTest extends TestCase
{
    protected string $userModel = UuidUser::class;

    public function test_指向使用者的欄位依設定建立成字串型別(): void
    {
        foreach (['doc_records' => 'created_by', 'doc_type_revisions' => 'created_by', 'doc_type_revision_reviews' => 'reviewer_id', 'doc_mcp_operations' => 'admin_user_id'] as $table => $column) {
            $this->assertNotContains(Schema::getColumnType($table, $column), ['integer', 'bigint'], "{$table}.{$column}");
        }
    }

    public function test_uuid_使用者可以填單並記下建立者(): void
    {
        $this->makeType('leave', [['key' => 'reason', 'label' => '事由', 'type' => 'text', 'required' => true, 'show_in_list' => true]]);
        $user = $this->makeUser(['name' => '陳大文']);
        $this->assertTrue(Str::isUuid($user->getKey()));

        $id = $this->actingAs($user)->postJson(route('doc_records.store', 'leave'), [
            'doc_date' => '2026-10-12', 'data' => ['reason' => '出差'],
        ])->assertOk()->json('id');

        $record = DocRecord::query()->with('creator')->findOrFail($id);
        $this->assertSame($user->getKey(), $record->created_by);
        $this->assertSame('陳大文', $record->creator->name);
    }

    public function test_uuid_管理員可以存草稿並在版本紀錄看到名稱(): void
    {
        $type = $this->makeType('leave', [['key' => 'reason', 'label' => '事由', 'type' => 'text']]);
        $admin = $this->makeAdmin(['name' => '林管理']);

        $revisions = app(FormRevisionService::class);
        $revisions->saveDraft($type, $revisions->runtimeSchema($type), $admin->getKey(), '第一版草稿');

        $this->actingAs($admin)->get(route('document_builder.revisions.index', $type))
            ->assertOk()->assertSee('林管理')->assertSee('第一版草稿');
    }
}
