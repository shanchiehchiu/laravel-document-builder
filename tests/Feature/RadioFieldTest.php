<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Services\DocFieldService;
use DocumentBuilder\Services\RecordValidator;
use DocumentBuilder\Tests\TestCase;
use Illuminate\Validation\ValidationException;

/** 單選按鈕：與固定選項共用選項清單，多一個排列方向（預設水平） */
class RadioFieldTest extends TestCase
{
    public function test_建立單選欄位時_選項與排列方向會存下來_沒指定就是水平(): void
    {
        $type = $this->makeType('visit', [], '拜訪單');

        $field = app(DocFieldService::class)->create($type, [
            'key' => 'mode', 'label' => '模式', 'type' => 'radio', 'width' => 6,
            'section' => DocField::SECTION_HEADER, 'choices' => "電話\n拜訪",
        ]);

        $this->assertSame(['電話', '拜訪'], $field->choices());
        $this->assertSame('horizontal', $field->layout());
    }

    public function test_單選欄位必須有選項_排列方向只能是水平或垂直(): void
    {
        $type = $this->makeType('visit', [], '拜訪單');
        $service = app(DocFieldService::class);

        try {
            $service->create($type, ['key' => 'mode', 'label' => '模式', 'type' => 'radio', 'width' => 6, 'section' => DocField::SECTION_HEADER]);
            $this->fail('沒有選項時應該拒絕');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('choices', $e->errors());
        }

        // 版面儲存時會先用 DocFieldService::rules() 驗證，排列方向不合法要被擋下
        $validation = validator(
            ['label' => '模式', 'type' => 'radio', 'width' => 6, 'choices' => '電話', 'layout' => 'diagonal'],
            DocFieldService::rules(),
        );
        $this->assertArrayHasKey('layout', $validation->errors()->toArray());
    }

    public function test_紀錄只能選單選的其中一個選項(): void
    {
        $type = $this->makeType('visit', [
            ['key' => 'mode', 'label' => '模式', 'type' => 'radio', 'choices' => "電話\n拜訪"],
        ], '拜訪單');
        $field = $type->fields()->where('key', 'mode')->firstOrFail();
        $validator = app(RecordValidator::class);

        $this->assertSame([], $this->failedRules($validator, $field, '電話'));
        $this->assertNotSame([], $this->failedRules($validator, $field, '郵件'), '不在選項中的值要被拒絕');
    }

    public function test_填單頁渲染成單選按鈕_未選時仍會送出欄位_非必填可取消(): void
    {
        $admin = $this->makeAdmin();
        $this->makeType('visit', [
            ['key' => 'mode', 'label' => '模式', 'type' => 'radio', 'choices' => "電話\n拜訪"],
        ], '拜訪單');

        $html = $this->actingAs($admin)->get(route('doc_records.create', 'visit'))->assertOk()->getContent();

        $this->assertStringContainsString('<input type="hidden" name="data[mode]" value="">', $html);
        $this->assertStringContainsString('type="radio" name="data[mode]" value="電話"', $html);
        $this->assertStringContainsString('type="radio" name="data[mode]" value="拜訪"', $html);
        $this->assertStringContainsString('data-clearable', $html);
    }

    /** @return array<int, string> */
    private function failedRules(RecordValidator $validator, DocField $field, string $value): array
    {
        $validation = validator(['mode' => $value], ['mode' => $validator->fieldRules($field)]);

        return array_keys($validation->fails() ? $validation->errors()->toArray() : []);
    }
}
