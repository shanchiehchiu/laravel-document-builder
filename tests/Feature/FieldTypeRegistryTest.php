<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\FormPlatform\ComponentRegistry;
use DocumentBuilder\Tests\TestCase;
use LogicException;

/** 表單平台的欄位型別登錄必須與 DocumentBuilder 的欄位型別完全一致 */
class FieldTypeRegistryTest extends TestCase
{
    public function test_兩份欄位型別一致時會回傳全部型別(): void
    {
        $types = app(ComponentRegistry::class)->fieldTypes();

        $this->assertArrayHasKey('checkgroup', $types);
        $this->assertSame(array_keys(config('document_builder.field_types')), array_keys($types));
    }

    public function test_缺少或多餘的型別會在錯誤訊息中指出代碼(): void
    {
        config(['document_builder.field_types' => config('document_builder.field_types') + ['fancy' => '花俏']]);
        config(['form_platform.field_types' => array_diff_key(config('form_platform.field_types'), ['radio' => true]) + ['legacy' => []]]);

        try {
            app(ComponentRegistry::class)->fieldTypes();
            $this->fail('型別不一致時應該拋出例外');
        } catch (LogicException $e) {
            $this->assertStringContainsString('缺少：radio、fancy', $e->getMessage());
            $this->assertStringContainsString('多餘：legacy', $e->getMessage());
        }
    }
}
