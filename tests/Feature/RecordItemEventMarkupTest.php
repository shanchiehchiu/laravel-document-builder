<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Tests\TestCase;

/** 填單頁的事件標記：明細列用 data-row-event-key，表頭維持 data-event-key，兩者不能混用 */
class RecordItemEventMarkupTest extends TestCase
{
    public function test_明細列的欄位帶列內事件標記_不帶表頭標記(): void
    {
        $field = $this->field('unit_amount', 'item', 'lines');

        $html = view('document-builder::records._item_row', [
            'fields' => collect([$field]),
            'tableKey' => 'lines',
            'index' => 0,
            'item' => null,
            'show' => false,
        ])->render();

        $this->assertStringContainsString('data-row-event-key="unit_amount"', $html);
        $this->assertStringNotContainsString('data-event-key=', $html);
    }

    public function test_表頭欄位仍然只帶表頭事件標記(): void
    {
        $field = $this->field('customer', 'header');

        $html = view('document-builder::records._input', [
            'field' => $field,
            'name' => 'data[customer]',
            'inputId' => 'field-customer',
            'eventKey' => 'customer',
            'value' => null,
        ])->render();

        $this->assertStringContainsString('data-event-key="customer"', $html);
        $this->assertStringNotContainsString('data-row-event-key', $html);
    }

    private function field(string $key, string $section, ?string $tableKey = null): DocField
    {
        $field = new DocField([
            'section' => $section,
            'table_key' => $tableKey,
            'key' => $key,
            'label' => $key,
            'type' => 'number',
            'required' => false,
            'width' => 12,
            'options' => [],
            'show_in_list' => false,
            'seq' => 1,
            'archived_at' => null,
        ]);
        $field->setAttribute('runtime_renderer', 'field.number');

        return $field;
    }
}
