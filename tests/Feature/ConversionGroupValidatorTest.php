<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\FormPlatform\ConversionGroupValidator;
use PHPUnit\Framework\TestCase;

/** 換算組宣告：欄位範圍、型別、不重複、與一般事件不衝突 */
class ConversionGroupValidatorTest extends TestCase
{
    public function test_同明細表的數字欄位組成換算組_通過(): void
    {
        $result = $this->validate([$this->group()], $this->fields());

        $this->assertTrue($result['valid'], $this->messages($result));
    }

    public function test_促銷欄位可以不存在_也可以不設定(): void
    {
        $this->assertTrue($this->validate([$this->group(['guard' => ['field' => 'is_promo']])], $this->fields())['valid']);
        $this->assertTrue($this->validate([$this->group(['guard' => null])], $this->fields())['valid']);
    }

    public function test_欄位不在同一明細表_拒絕(): void
    {
        // 折數欄位只存在另一個明細表
        $fields = array_merge(array_filter($this->fields(), fn (array $f): bool => $f['key'] !== 'promo_discount'), [$this->field('promo_discount', 'item', 'other', 'number')]);

        $result = $this->validate([$this->group()], $fields);

        $this->assertFalse($result['valid']);
        $this->assertSame('conversion_groups.0.rate', $result['errors'][0]['path']);
    }

    public function test_非數字或金額的欄位_拒絕(): void
    {
        $fields = array_merge(array_filter($this->fields(), fn (array $f): bool => $f['key'] !== 'promo_discount'), [$this->field('promo_discount', 'item', 'lines', 'text')]);

        $result = $this->validate([$this->group()], $fields);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('數字或金額', $this->messages($result));
    }

    public function test_同一欄位不能重複，也不能同時屬於兩組(): void
    {
        $this->assertFalse($this->validate([$this->group(['rate' => 'base'])], $this->fields())['valid']);

        $result = $this->validate([$this->group(), $this->group()], $this->fields());
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('已屬於另一組', $this->messages($result));
    }

    public function test_一般事件不得設定換算組控管的欄位(): void
    {
        $events = [[
            'trigger' => ['type' => 'field.changed', 'table_key' => 'lines', 'field' => 'qty'],
            'actions' => [['type' => 'set_value', 'field' => 'promo_discount', 'value' => 50]],
        ]];

        $result = $this->validate([$this->group()], $this->fields(), $events);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('由換算組控管', $this->messages($result));
    }

    public function test_不支援的種類與多餘屬性_拒絕(): void
    {
        $this->assertFalse($this->validate([$this->group(['kind' => 'sum'])], $this->fields())['valid']);
        $this->assertFalse($this->validate([$this->group(['extra' => 1])], $this->fields())['valid']);
    }

    public function test_沒有換算組時通過(): void
    {
        $this->assertTrue($this->validate(null, $this->fields())['valid']);
        $this->assertTrue($this->validate([], $this->fields())['valid']);
    }

    private function group(array $override = []): array
    {
        return $override + [
            'kind' => 'price_discount', 'table_key' => 'lines',
            'base' => 'suggestion_price', 'price' => 'unit_amount', 'rate' => 'promo_discount',
            'guard' => ['field' => 'is_promo'],
        ];
    }

    private function fields(): array
    {
        return [
            $this->field('suggestion_price', 'item', 'lines', 'amount'),
            $this->field('unit_amount', 'item', 'lines', 'amount'),
            $this->field('promo_discount', 'item', 'lines', 'number'),
            $this->field('qty', 'item', 'lines', 'number'),
        ];
    }

    private function field(string $key, string $section, string $tableKey, string $type): array
    {
        return ['key' => $key, 'section' => $section, 'table_key' => $tableKey, 'type' => $type, 'archived' => false];
    }

    private function validate(mixed $groups, array $fields, array $events = []): array
    {
        return (new ConversionGroupValidator)->validate($groups, $fields, $events);
    }

    private function messages(array $result): string
    {
        return implode('; ', array_map(fn (array $e) => $e['path'].' '.$e['message'], $result['errors']));
    }
}
