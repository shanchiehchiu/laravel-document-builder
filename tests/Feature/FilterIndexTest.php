<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Services\FilterIndexService;
use DocumentBuilder\Tests\TestCase;

/** 列表篩選索引：列表顯示的表頭欄位會有虛擬欄位，查詢走它，取消顯示後移除 */
class FilterIndexTest extends TestCase
{
    public function test_列表欄位有篩選索引欄位_查詢走索引_取消後移除(): void
    {
        $type = $this->makeType('leave', [
            ['key' => 'reason', 'label' => '事由', 'type' => 'text', 'show_in_list' => true],
            ['key' => 'days', 'label' => '天數', 'type' => 'number', 'decimals' => 1, 'show_in_list' => true],
        ]);
        $user = $this->makeUser();
        $index = app(FilterIndexService::class);
        $column = FilterIndexService::columnName($type, 'reason');

        $index->sync($type);
        // 數字型別不做索引欄位（精確比對的小數問題）
        $this->assertTrue($index->hasColumn($type, 'reason'));
        $this->assertFalse($index->hasColumn($type, 'days'));

        foreach (['家庭因素', '身體不適'] as $reason) {
            $this->actingAs($user)->postJson(route('doc_records.store', 'leave'), [
                'doc_date' => '2026-10-12', 'data' => ['reason' => $reason, 'days' => '1'],
            ])->assertOk();
        }
        $this->actingAs($user)->getJson(route('doc_records.index', 'leave').'?draw=1&start=0&length=10&'.http_build_query(['field' => ['reason' => '身體不適']]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJsonPath('recordsFiltered', 1);

        // 取消列表顯示後，篩選欄位要移除
        $type->fields()->where('key', 'reason')->update(['show_in_list' => false]);
        $index->sync($type->fresh());
        $this->assertFalse($index->hasColumn($type, 'reason'));
    }

    public function test_封存的欄位不建立篩選索引(): void
    {
        $type = $this->makeType('leave', [
            ['key' => 'reason', 'label' => '事由', 'type' => 'text', 'show_in_list' => true],
        ]);
        $type->fields()->update(['archived_at' => now()]);

        app(FilterIndexService::class)->sync($type);

        $this->assertFalse(app(FilterIndexService::class)->hasColumn($type, 'reason'));
    }
}
