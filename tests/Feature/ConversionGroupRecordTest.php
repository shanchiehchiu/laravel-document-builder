<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Services\FormLayoutService;
use DocumentBuilder\Tests\TestCase;

/** 換算組在填單頁：宣告的換算組會輸出到表單上，交給 conversion-group.js 註冊 */
class ConversionGroupRecordTest extends TestCase
{
    public function test_設定換算組的單據_填單頁輸出換算組(): void
    {
        $admin = $this->makeAdmin();
        $type = $this->makeType('visit', [], '拜訪單');
        // 明細表放進版面（未放進版面的欄位，填單頁會拒絕呈現）
        app(FormLayoutService::class)->save($type, [['cols' => [['width' => 12, 'item' => [
            // 新明細表的代碼由服務產生（table_N），這裡不指定
            'kind' => 'table', 'key' => null, 'title' => '明細', 'fields' => [
                ['key' => 'suggestion_price', 'label' => '建議價', 'type' => 'amount', 'width' => 4],
                ['key' => 'unit_amount', 'label' => '單價', 'type' => 'amount', 'width' => 4],
                ['key' => 'promo_discount', 'label' => '折數', 'type' => 'number', 'width' => 4],
            ],
        ]]]]]);
        $type = $type->fresh();
        $tableKey = (string) array_key_first($type->form_layout['tables']);
        $type->forceFill(['conversion_groups' => [[
            'kind' => 'price_discount', 'table_key' => $tableKey,
            'base' => 'suggestion_price', 'price' => 'unit_amount', 'rate' => 'promo_discount', 'guard' => null,
        ]]])->save();

        $html = $this->actingAs($admin)->get(route('doc_records.create', 'visit'))->assertOk()->getContent();

        $this->assertStringContainsString('data-conversion-groups="', $html);
        $this->assertStringContainsString('&quot;base&quot;:&quot;suggestion_price&quot;', $html);
    }

    public function test_沒有換算組的單據_輸出空清單(): void
    {
        $admin = $this->makeAdmin();
        $this->makeType('visit', [], '拜訪單');

        $html = $this->actingAs($admin)->get(route('doc_records.create', 'visit'))->assertOk()->getContent();

        $this->assertStringContainsString('data-conversion-groups="[]"', $html);
    }
}
