<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Services\DocFieldService;
use DocumentBuilder\Services\RecordValidator;
use DocumentBuilder\Tests\TestCase;

/** 金額／貨幣：存檔一律純數值，千分位與幣別只出現在顯示層 */
class AmountFieldTest extends TestCase
{
    public function test_建立金額欄位時_小數位預設為2_幣別可選(): void
    {
        $type = $this->makeType('visit', [], '拜訪單');

        $plain = app(DocFieldService::class)->create($type, [
            'key' => 'price', 'label' => '單價', 'type' => 'amount', 'width' => 4, 'section' => DocField::SECTION_HEADER,
        ]);
        $this->assertSame(2, $plain->decimals());
        $this->assertSame('', $plain->currency());

        $withCurrency = app(DocFieldService::class)->create($type, [
            'key' => 'total', 'label' => '總計', 'type' => 'amount', 'width' => 4, 'section' => DocField::SECTION_HEADER,
            'decimals' => 0, 'currency' => ' NT$ ',
        ]);
        $this->assertSame(0, $withCurrency->decimals());
        $this->assertSame('NT$', $withCurrency->currency());
    }

    public function test_金額只接受數字，存檔時四捨五入到小數位(): void
    {
        $type = $this->makeType('visit', [
            ['key' => 'price', 'label' => '單價', 'type' => 'amount', 'decimals' => 2],
        ], '拜訪單');
        $field = $type->fields()->where('key', 'price')->firstOrFail();
        $validator = app(RecordValidator::class);

        $this->assertSame([], $this->failedRules($validator, $field, '12500.5'));
        $this->assertNotSame([], $this->failedRules($validator, $field, '一萬'), '非數字要被拒絕');
    }

    public function test_填單頁_送出的是隱藏純數字_畫面顯示千分位與幣別(): void
    {
        $admin = $this->makeAdmin();
        $this->makeType('visit', [
            ['key' => 'price', 'label' => '單價', 'type' => 'amount', 'decimals' => 2, 'currency' => 'NT$'],
        ], '拜訪單');

        $html = $this->actingAs($admin)->get(route('doc_records.create', 'visit'))->assertOk()->getContent();

        $this->assertStringContainsString('<span class="input-group-text">NT$</span>', $html);
        $this->assertStringContainsString('<input type="hidden" name="data[price]" value="" data-amount-raw', $html);
        $this->assertStringNotContainsString('name="data[price]" value="" data-amount-display', $html, '顯示框不能有 name');
    }

    /** @return array<int, string> */
    private function failedRules(RecordValidator $validator, DocField $field, string $value): array
    {
        $validation = validator(['price' => $value], ['price' => $validator->fieldRules($field)]);

        return array_keys($validation->fails() ? $validation->errors()->toArray() : []);
    }
}
