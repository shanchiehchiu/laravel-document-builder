<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Services\DocFieldService;
use DocumentBuilder\Services\DocRecordService;
use DocumentBuilder\Services\RecordValidator;
use DocumentBuilder\Tests\TestCase;

/** 多選下拉：與複選同樣以字串陣列儲存，填單頁改用 select2 多選 */
class MultiSelectFieldTest extends TestCase
{
    public function test_建立多選下拉時_選項會存下來(): void
    {
        $type = $this->makeType('visit', [], '拜訪單');

        $field = app(DocFieldService::class)->create($type, [
            'key' => 'regions', 'label' => '地區', 'type' => 'multiselect', 'width' => 6,
            'section' => DocField::SECTION_HEADER, 'choices' => "北部\n中部\n南部",
        ]);

        $this->assertSame(['北部', '中部', '南部'], $field->choices());
    }

    public function test_只能選選項中的值_可以多選_不接受單一字串(): void
    {
        $field = $this->multiselectField(required: false);
        $validator = app(RecordValidator::class);

        $this->assertSame([], $this->failedRules($validator, $field, ['北部', '南部']));
        $this->assertSame([], $this->failedRules($validator, $field, []));
        $this->assertNotSame([], $this->failedRules($validator, $field, ['東部']), '不在選項中的值要被拒絕');
        $this->assertNotSame([], $this->failedRules($validator, $field, '北部'), '必須是陣列');
    }

    public function test_存檔時沒選的存成空陣列(): void
    {
        $admin = $this->makeAdmin();
        $type = $this->makeType('visit', [
            ['key' => 'regions', 'label' => '地區', 'type' => 'multiselect', 'choices' => "北部\n中部"],
        ], '拜訪單');
        $service = app(DocRecordService::class);

        $picked = $service->create($type, ['doc_date' => '2026-10-15', 'data' => ['regions' => ['北部', '', '中部']]], $admin->getKey());
        $this->assertSame(['北部', '中部'], $picked->data['regions']);

        $none = $service->create($type, ['doc_date' => '2026-10-15', 'data' => []], $admin->getKey());
        $this->assertSame([], $none->data['regions']);
    }

    public function test_填單頁渲染成多選下拉_已選的會選取(): void
    {
        $admin = $this->makeAdmin();
        $type = $this->makeType('visit', [
            ['key' => 'regions', 'label' => '地區', 'type' => 'multiselect', 'choices' => "北部\n中部\n南部"],
        ], '拜訪單');
        $record = app(DocRecordService::class)->create($type, ['doc_date' => '2026-10-15', 'data' => ['regions' => ['中部']]], $admin->getKey());

        $create = $this->actingAs($admin)->get(route('doc_records.create', 'visit'))->assertOk()->getContent();
        $this->assertStringContainsString('<select name="data[regions][]" multiple', $create);
        $this->assertStringContainsString('js-choice-multi', $create);

        $edit = $this->actingAs($admin)->get(route('doc_records.edit', [$type->code, $record]))->assertOk()->getContent();
        preg_match('/<select name="data\[regions\]\[\]".*?<\/select>/s', $edit, $select);
        $this->assertStringContainsString('<option value="中部" selected>', $select[0] ?? '');
        $this->assertStringNotContainsString('<option value="北部" selected>', $select[0] ?? '');
    }

    public function test_列表顯示用頓號串接(): void
    {
        $field = $this->multiselectField(required: false);

        $this->assertSame('北部、南部', $field->display(['北部', '南部']));
    }

    private function multiselectField(bool $required): DocField
    {
        $type = $this->makeType('visit', [
            ['key' => 'regions', 'label' => '地區', 'type' => 'multiselect', 'required' => $required, 'choices' => "北部\n中部\n南部"],
        ], '拜訪單');

        return $type->fields()->where('key', 'regions')->firstOrFail();
    }

    /** @return array<int, string> */
    private function failedRules(RecordValidator $validator, DocField $field, mixed $value): array
    {
        $validation = validator(['regions' => $value], ['regions' => $validator->fieldRules($field)]);

        return array_keys($validation->fails() ? $validation->errors()->toArray() : []);
    }
}
