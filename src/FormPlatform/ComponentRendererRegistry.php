<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use LogicException;

/** 只把受控 renderer key 對應至平台內部的 Blade view。 */
class ComponentRendererRegistry
{
    private const COMPONENT_VIEWS = [
        'field.reference' => 'document-builder::records._field',
        'layout.heading' => 'document-builder::records._runtime_heading',
        'layout.note' => 'document-builder::records._runtime_note',
        'layout.spacer' => 'document-builder::records._runtime_spacer',
        'layout.divider' => 'document-builder::records._runtime_divider',
        'layout.table' => 'document-builder::records._table',
    ];

    private const FIELD_RENDERERS = [
        'field.text',
        'field.textarea',
        'field.number',
        'field.amount',
        'field.date',
        'field.checkbox',
        'field.select',
        'field.radio',
        'field.checkgroup',
        'field.multiselect',
        'field.master',
    ];

    public function __construct(private readonly ComponentRegistry $components) {}

    /** 回傳明確登記的 view 名稱；Schema 無法指定 view 路徑。 */
    public function componentView(string $type): string
    {
        return self::COMPONENT_VIEWS[$this->componentRenderer($type)];
    }

    public function componentRenderer(string $type): string
    {
        $renderer = $this->components->component($type)['renderer'] ?? null;
        if (! is_string($renderer) || ! isset(self::COMPONENT_VIEWS[$renderer])) {
            throw new LogicException("元件 {$type} 沒有受控的 Runtime renderer");
        }

        return $renderer;
    }

    /** 回傳欄位型別的穩定 renderer key，供共用輸入 partial 使用。 */
    public function fieldRenderer(string $type): string
    {
        $renderer = $this->components->fieldType($type)['renderer'] ?? null;
        if (! is_string($renderer) || ! in_array($renderer, self::FIELD_RENDERERS, true)) {
            throw new LogicException("欄位型別 {$type} 沒有受控的 Runtime renderer");
        }

        return $renderer;
    }
}
