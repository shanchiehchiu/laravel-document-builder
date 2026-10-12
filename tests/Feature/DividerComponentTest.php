<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\FormPlatform\ComponentRendererRegistry;
use DocumentBuilder\FormPlatform\FormSchemaValidator;
use DocumentBuilder\Services\DocumentBuilderSchemaAdapter;
use DocumentBuilder\Services\FormLayoutService;
use DocumentBuilder\Tests\TestCase;

/** 分隔線：版面中的純呈現元件，填單頁渲染成水平線，不產生資料值 */
class DividerComponentTest extends TestCase
{
    public function test_分隔線登錄在版面種類與元件庫中(): void
    {
        $this->assertContains('divider', FormLayoutService::KINDS);
        $this->assertSame(
            'document-builder::records._runtime_divider',
            app(ComponentRendererRegistry::class)->componentView('divider'),
        );
    }

    public function test_版面放了分隔線_填單頁會渲染成水平線(): void
    {
        $admin = $this->makeAdmin();
        $type = $this->makeType('visit', [
            ['key' => 'memo', 'label' => '備註', 'type' => 'text'],
        ], '拜訪單');
        $type->forceFill(['form_layout' => ['rows' => [
            ['cols' => [['width' => 12, 'item' => ['kind' => 'divider']]]],
        ]]])->save();

        $html = $this->actingAs($admin)->get(route('doc_records.create', 'visit'))->assertOk()->getContent();

        $this->assertStringContainsString('<hr class="my-2">', $html);
    }

    public function test_設計器存檔的_schema_含分隔線時通過驗證(): void
    {
        $type = $this->makeType('visit', [
            ['key' => 'memo', 'label' => '備註', 'type' => 'text'],
        ], '拜訪單');
        $schema = app(DocumentBuilderSchemaAdapter::class)->export($type->fresh(['fields', 'statuses']));
        $schema['layout']['rows'][] = ['cols' => [['width' => 12, 'component' => ['type' => 'divider']]]];

        $validation = app(FormSchemaValidator::class)->validate($schema, $type->code);
        $this->assertTrue($validation['valid'], json_encode($validation['errors'], JSON_UNESCAPED_UNICODE));
    }
}
