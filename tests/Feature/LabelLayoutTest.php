<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\FormPlatform\FormSchemaValidator;
use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Services\DocTypeService;
use DocumentBuilder\Services\DocumentBuilderSchemaAdapter;
use DocumentBuilder\Tests\TestCase;

/** 整張單據的表頭欄位標籤位置：上方（預設）或左側 */
class LabelLayoutTest extends TestCase
{
    public function test_新單據預設標籤在上方(): void
    {
        $type = $this->makeType('visit', [], '拜訪單');

        $this->assertSame('top', $type->label_layout);
        $this->assertFalse($type->labelOnLeft());
    }

    public function test_標籤位置可以設成左側並存下來(): void
    {
        $type = $this->makeType('visit', [], '拜訪單');
        app(DocTypeService::class)->update($type, ['name' => '拜訪單', 'label_layout' => 'left']);

        $this->assertSame('left', $type->fresh()->label_layout);
        $this->assertTrue($type->fresh()->labelOnLeft());
    }

    public function test_Schema_匯出與匯入會帶上標籤位置(): void
    {
        $type = $this->makeType('visit', [
            ['key' => 'note', 'label' => '備註', 'type' => 'text'],
        ], '拜訪單');
        app(DocTypeService::class)->update($type, ['name' => '拜訪單', 'label_layout' => 'left']);

        $schema = app(DocumentBuilderSchemaAdapter::class)->export($type->fresh(['fields', 'statuses']));
        $this->assertSame('left', $schema['form']['label_layout']);
        $this->assertSame('left', app(DocumentBuilderSchemaAdapter::class)->toDesignerPayload($type->fresh(['fields', 'statuses']), $schema)['settings']['label_layout']);
    }

    public function test_Schema_驗證只接受_top_或_left_且舊Schema_沒有這個鍵仍有效(): void
    {
        $type = $this->makeType('visit', [], '拜訪單');
        $schema = app(DocumentBuilderSchemaAdapter::class)->export($type->fresh(['fields', 'statuses']));
        $validator = app(FormSchemaValidator::class);

        $schema['form']['label_layout'] = 'left';
        $this->assertSame([], $validator->validate($schema, $type->code)['errors']);

        $schema['form']['label_layout'] = 'center';
        $this->assertNotSame([], $validator->validate($schema, $type->code)['errors']);

        unset($schema['form']['label_layout']);
        $this->assertSame([], $validator->validate($schema, $type->code)['errors']);
    }

    public function test_設計器儲存會拒絕不合法的標籤位置(): void
    {
        $admin = $this->makeAdmin();
        $type = $this->makeType('visit', [], '拜訪單');

        $this->actingAs($admin)
            ->putJson(route('document_builder.designer.update', $type), ['settings' => [
                'name' => '拜訪單', 'status' => true, 'seq' => 0, 'no_prefix' => '', 'no_date_format' => 'Ymd',
                'no_seq_digits' => 3, 'label_layout' => 'center', 'buttons' => array_fill_keys(array_keys(DocType::BUTTONS), true),
            ]])
            ->assertUnprocessable();
    }

    public function test_填單頁依設定輸出左側或上方的標籤排列(): void
    {
        $admin = $this->makeAdmin();
        $type = $this->makeType('visit', [
            ['key' => 'note', 'label' => '備註', 'type' => 'text'],
        ], '拜訪單');

        $top = $this->actingAs($admin)->get(route('doc_records.create', 'visit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('width: 7em;', $top, '預設不應出現左側標籤排列');

        app(DocTypeService::class)->update($type, ['name' => '拜訪單', 'label_layout' => 'left']);
        $left = $this->actingAs($admin)->get(route('doc_records.create', 'visit'))->assertOk()->getContent();
        $this->assertStringContainsString('width: 7em;', $left);
        $this->assertStringContainsString('name="data[note]"', $left);
    }
}
