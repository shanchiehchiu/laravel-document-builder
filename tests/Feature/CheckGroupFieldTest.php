<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Services\DocFieldService;
use DocumentBuilder\Services\DocRecordService;
use DocumentBuilder\Services\RecordValidator;
use DocumentBuilder\Tests\TestCase;

/** 複選標籤組：與固定選項共用選項清單，值為陣列；存檔一律是純字串陣列 */
class CheckGroupFieldTest extends TestCase
{
    public function test_建立複選欄位時_選項與排列方向會存下來_沒指定就是水平(): void
    {
        $type = $this->makeType('visit', [], '拜訪單');

        $field = app(DocFieldService::class)->create($type, [
            'key' => 'tags', 'label' => '標籤', 'type' => 'checkgroup', 'width' => 6,
            'section' => DocField::SECTION_HEADER, 'choices' => "急件\n需回覆",
        ]);

        $this->assertSame(['急件', '需回覆'], $field->choices());
        $this->assertSame('horizontal', $field->layout());
    }

    public function test_紀錄只能選選項中的值_可以多選_不在選項中的要被拒絕(): void
    {
        $field = $this->checkgroupField(required: false);
        $validator = app(RecordValidator::class);

        $this->assertSame([], $this->failedRules($validator, $field, ['急件', '需回覆']));
        $this->assertSame([], $this->failedRules($validator, $field, []), '沒勾選也合法（非必填）');
        $this->assertNotSame([], $this->failedRules($validator, $field, ['郵件']), '不在選項中的值要被拒絕');
        $this->assertNotSame([], $this->failedRules($validator, $field, '急件'), '必須是陣列，不接受單一字串');
    }

    public function test_必填時至少要勾一項(): void
    {
        $field = $this->checkgroupField(required: true);
        $validator = app(RecordValidator::class);

        $this->assertNotSame([], $this->failedRules($validator, $field, []));
        $this->assertSame([], $this->failedRules($validator, $field, ['急件']));
    }

    public function test_存檔時沒勾的欄位存成空陣列_空字串會被濾掉(): void
    {
        $type = $this->makeType('visit', [
            ['key' => 'tags', 'label' => '標籤', 'type' => 'checkgroup', 'choices' => "急件\n需回覆"],
        ], '拜訪單');
        $admin = $this->makeAdmin();
        $service = app(DocRecordService::class);

        $picked = $service->create($type, ['doc_date' => '2026-10-15', 'data' => ['tags' => ['急件', '', '需回覆']]], $admin->getKey());
        $this->assertSame(['急件', '需回覆'], $picked->data['tags']);

        // 瀏覽器沒勾任何一項時不會送出這個欄位，也要存成 []
        $none = $service->create($type, ['doc_date' => '2026-10-15', 'data' => []], $admin->getKey());
        $this->assertSame([], $none->data['tags']);
    }

    public function test_填單頁渲染成陣列名稱的勾選框_已選的會勾起來(): void
    {
        $admin = $this->makeAdmin();
        $type = $this->makeType('visit', [
            ['key' => 'tags', 'label' => '標籤', 'type' => 'checkgroup', 'choices' => "急件\n需回覆"],
        ], '拜訪單');
        $record = app(DocRecordService::class)->create($type, ['doc_date' => '2026-10-15', 'data' => ['tags' => ['需回覆']]], $admin->getKey());

        $create = $this->actingAs($admin)->get(route('doc_records.create', 'visit'))->assertOk()->getContent();
        $this->assertStringContainsString('type="checkbox" name="data[tags][]" value="急件"', $create);
        $this->assertStringNotContainsString('name="data[tags]" value=""', $create, '複選不需要空值的隱藏欄位');

        $edit = $this->actingAs($admin)->get(route('doc_records.edit', [$type->code, $record]))->assertOk()->getContent();
        // 逐一檢查每個勾選框：只有「需回覆」應該是勾選狀態
        preg_match_all('/<input[^>]*name="data\[tags\]\[\]"[^>]*>/', $edit, $inputs);
        $checked = array_map(
            fn (string $input): bool => str_contains($input, ' checked'),
            $inputs[0],
        );
        $this->assertCount(2, $inputs[0]);
        $this->assertSame([false, true], $checked, '「急件」未勾、「需回覆」已勾');
    }

    public function test_列表顯示用頓號串接_沒有值時是空字串(): void
    {
        $field = $this->checkgroupField(required: false);

        $this->assertSame('急件、需回覆', $field->display(['急件', '需回覆']));
        $this->assertSame('', $field->display([]));
    }

    private function checkgroupField(bool $required): DocField
    {
        $type = $this->makeType('visit', [
            ['key' => 'tags', 'label' => '標籤', 'type' => 'checkgroup', 'required' => $required, 'choices' => "急件\n需回覆"],
        ], '拜訪單');

        return $type->fields()->where('key', 'tags')->firstOrFail();
    }

    /** @return array<int, string> */
    private function failedRules(RecordValidator $validator, DocField $field, mixed $value): array
    {
        $validation = validator(['tags' => $value], ['tags' => $validator->fieldRules($field)]);

        return array_keys($validation->fails() ? $validation->errors()->toArray() : []);
    }
}
