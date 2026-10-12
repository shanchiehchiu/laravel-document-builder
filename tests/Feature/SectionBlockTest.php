<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Services\FormLayoutService;
use DocumentBuilder\Tests\TestCase;
use Illuminate\Validation\ValidationException;

/** 區塊：從區塊標題那列起，之後的列歸進區塊，直到下一個區塊；不巢狀，預設配色 */
class SectionBlockTest extends TestCase
{
    public function test_區塊分組_區塊之前的列自成一段_沒有列的區塊不輸出(): void
    {
        $plain = [['kind' => 'field']];
        $section = [['kind' => 'section', 'text' => '合約資訊']];
        $rows = [$plain, $section, [['kind' => 'field']], [['kind' => 'field']], $section];

        $blocks = FormLayoutService::sectionBlocks($rows);

        $this->assertCount(3, $blocks);
        $this->assertNull($blocks[0]['section']);
        $this->assertSame([$plain], $blocks[0]['rows']);
        $this->assertSame('合約資訊', $blocks[1]['section']['text']);
        $this->assertCount(2, $blocks[1]['rows']);
        $this->assertSame([], $blocks[2]['rows'], '沒有列的區塊仍保留，讓標題可見');
    }

    public function test_沒有區塊時所有列都在無標題段落(): void
    {
        $blocks = FormLayoutService::sectionBlocks([[['kind' => 'field']], [['kind' => 'field']]]);

        $this->assertCount(1, $blocks);
        $this->assertNull($blocks[0]['section']);
        $this->assertCount(2, $blocks[0]['rows']);
    }

    public function test_填單頁依區塊輸出卡片_標題可省略_欄位留在區塊內(): void
    {
        $admin = $this->makeAdmin();
        $type = $this->makeType('contract', [
            ['key' => 'vendor', 'label' => '廠商', 'type' => 'text'],
            ['key' => 'amount_note', 'label' => '備註', 'type' => 'text'],
        ], '維護合約');
        $fieldsByKey = $type->fields->keyBy('key');
        $type->forceFill(['form_layout' => ['rows' => [
            ['cols' => [['width' => 6, 'item' => ['kind' => 'field', 'key' => 'vendor']]]],
            ['cols' => [['width' => 12, 'item' => ['kind' => 'section', 'text' => '客戶資訊']]]],
            ['cols' => [['width' => 6, 'item' => ['kind' => 'field', 'key' => 'amount_note']]]],
            ['cols' => [['width' => 12, 'item' => ['kind' => 'section']]]],
        ]]])->save();
        $this->assertNotNull($fieldsByKey->get('vendor'));

        $html = $this->actingAs($admin)->get(route('doc_records.create', 'contract'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="db-form-section'), '兩個區塊各一個卡片');
        $this->assertStringContainsString('客戶資訊', $html);
        $this->assertSame(2, substr_count($html, 'background: #f8fafc'), '兩個區塊都是同一種中性底色');
        $this->assertStringNotContainsString('border-left: 4px', $html, '不應有左側色條');
        $this->assertStringContainsString('color: #0f172a', $html, '標題為深色粗體');
        $greenStart = strpos($html, 'class="db-form-section');
        $this->assertGreaterThan(strpos($html, 'name="data[vendor]"'), $greenStart, '第一個欄位在區塊之前');
        $this->assertLessThan(strpos($html, 'name="data[amount_note]"'), $greenStart, '區塊之後的欄位不在第一個卡片前');
    }

    public function test_區塊經過_Schema_匯出與驗證都會通過_設計器存檔不會被擋(): void
    {
        $type = $this->makeType('contract', [
            ['key' => 'vendor', 'label' => '廠商', 'type' => 'text'],
        ], '維護合約');
        $type->forceFill(['form_layout' => ['rows' => [
            ['cols' => [['width' => 12, 'item' => ['kind' => 'section', 'text' => '客戶資訊']]]],
            ['cols' => [['width' => 6, 'item' => ['kind' => 'field', 'key' => 'vendor']]]],
        ]]])->save();

        $adapter = app(\DocumentBuilder\Services\DocumentBuilderSchemaAdapter::class);
        $schema = $adapter->export($type->fresh(['fields', 'statuses']));

        $this->assertSame(['type' => 'section', 'text' => '客戶資訊'], $schema['layout']['rows'][0]['cols'][0]['component']);
        $this->assertSame([], app(\DocumentBuilder\FormPlatform\FormSchemaValidator::class)->validate($schema, $type->code)['errors']);
        $payload = $adapter->toDesignerPayload($type->fresh(['fields', 'statuses']), $schema);
        $this->assertSame('客戶資訊', $payload['rows'][0]['cols'][0]['item']['text']);
    }
}
