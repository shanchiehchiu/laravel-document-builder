<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\FormPlatform\EventSchemaValidator;
use PHPUnit\Framework\TestCase;

/** 欄位變更事件的範圍：表頭事件只看表頭，明細列事件只看同一明細表的同一列 */
class EventSchemaValidatorTest extends TestCase
{
    public function test_表頭事件可以設定同一個表頭的欄位(): void
    {
        $result = $this->validate([
            $this->event(['field' => 'customer'], [['field' => 'memo', 'value' => 'x']]),
        ], [
            $this->field('customer', 'header'),
            $this->field('memo', 'header', type: 'text'),
        ]);

        $this->assertTrue($result['valid'], $this->messages($result));
    }

    public function test_明細列事件可以設定同一明細表同一列的欄位(): void
    {
        $result = $this->validate([
            $this->event(['table_key' => 'lines', 'field' => 'unit_amount'], [
                ['field' => 'promo_discount', 'value' => 100],
            ]),
        ], [
            $this->field('unit_amount', 'item', 'lines', 'number'),
            $this->field('promo_discount', 'item', 'lines', 'number'),
        ]);

        $this->assertTrue($result['valid'], $this->messages($result));
    }

    public function test_明細列事件不能設定表頭欄位(): void
    {
        $result = $this->validate([
            $this->event(['table_key' => 'lines', 'field' => 'unit_amount'], [
                ['field' => 'customer', 'value' => 'ACME'],
            ]),
        ], [
            $this->field('unit_amount', 'item', 'lines', 'number'),
            $this->field('customer', 'header'),
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('events.0.actions.0.field', $result['errors'][0]['path']);
    }

    public function test_表頭事件不能設定明細欄位(): void
    {
        $result = $this->validate([
            $this->event(['field' => 'customer'], [['field' => 'unit_amount', 'value' => 1]]),
        ], [
            $this->field('customer', 'header'),
            $this->field('unit_amount', 'item', 'lines', 'number'),
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('events.0.actions.0.field', $result['errors'][0]['path']);
    }

    public function test_明細列事件不能跨明細表(): void
    {
        $result = $this->validate([
            $this->event(['table_key' => 'lines', 'field' => 'unit_amount'], [
                ['field' => 'rate', 'value' => 100],
            ]),
        ], [
            $this->field('unit_amount', 'item', 'lines', 'number'),
            $this->field('rate', 'item', 'other_lines', 'number'),
        ]);

        $this->assertFalse($result['valid'], '同名欄位在別的明細表裡，不屬於同一範圍');
    }

    public function test_表頭與明細的同名欄位不會互相覆蓋(): void
    {
        // 表頭與明細都有 amount、total 兩個同名欄位：表頭事件只能看到表頭的，明細事件只能看到明細的
        $result = $this->validate([
            $this->event(['field' => 'amount'], [['field' => 'total', 'value' => 1]]),
            $this->event(['table_key' => 'lines', 'field' => 'amount'], [['field' => 'total', 'value' => 2]]),
        ], [
            $this->field('amount', 'header', type: 'number'),
            $this->field('total', 'header', type: 'number'),
            $this->field('amount', 'item', 'lines', 'number'),
            $this->field('total', 'item', 'lines', 'number'),
        ]);

        $this->assertTrue($result['valid'], $this->messages($result));
    }

    public function test_同一列內兩條事件互相指向會被視為循環(): void
    {
        // 換算組需要雙向關係，但目前的一般事件不允許，雙向規則由換算組自己處理
        $result = $this->validate([
            $this->event(['table_key' => 'lines', 'field' => 'unit_amount'], [['field' => 'promo_discount', 'value' => 90]]),
            $this->event(['table_key' => 'lines', 'field' => 'promo_discount'], [['field' => 'unit_amount', 'value' => 100]]),
        ], [
            $this->field('unit_amount', 'item', 'lines', 'number'),
            $this->field('promo_discount', 'item', 'lines', 'number'),
        ]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('循環', $this->messages($result));
    }

    public function test_明細表代碼格式不正確時拒絕(): void
    {
        $result = $this->validate([
            $this->event(['table_key' => 'Bad-Key', 'field' => 'unit_amount'], [['field' => 'promo_discount', 'value' => 90]]),
        ], [
            $this->field('unit_amount', 'item', 'lines', 'number'),
            $this->field('promo_discount', 'item', 'lines', 'number'),
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('events.0.trigger.table_key', $result['errors'][0]['path']);
    }

    /** @return array{valid: bool, errors: array<int, array{path: string, message: string}>} */
    private function validate(array $events, array $fields): array
    {
        return (new EventSchemaValidator)->validate($events, $fields);
    }

    private function event(array $trigger, array $actions): array
    {
        return [
            'trigger' => ['type' => 'field.changed'] + $trigger,
            'actions' => array_map(fn (array $action) => ['type' => 'set_value'] + $action, $actions),
        ];
    }

    private function field(string $key, string $section, ?string $tableKey = null, string $type = 'number'): array
    {
        return [
            'key' => $key,
            'section' => $section,
            'table_key' => $tableKey,
            'type' => $type,
            'archived' => false,
            'required' => false,
            'options' => [],
        ];
    }

    private function messages(array $result): string
    {
        return implode('; ', array_map(fn (array $error) => $error['path'].' '.$error['message'], $result['errors']));
    }
}
