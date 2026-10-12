<?php

declare(strict_types=1);

return [
    'schema_version' => 1,

    'mcp' => [
        // 除 app.url 主機與 localhost 外，Valet 或正式網址需在此加入允許主機。
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FORM_PLATFORM_MCP_ALLOWED_HOSTS', ''))
        ))),
    ],

    // Schema 版面中的元件類型；renderer 是平台內部代碼，不是可執行的 PHP class 或 view 路徑。
    'components' => [
        'field' => [
            'label' => '欄位參照',
            'version' => 1,
            'props' => [
                'key' => ['type' => 'string', 'required' => true, 'min_length' => 1, 'max_length' => 50, 'pattern' => '/^[a-z][a-z0-9_]{0,49}$/'],
            ],
            'default_props' => [],
            'allowed_parents' => ['column', 'table'],
            'allowed_children' => [],
            'renderer' => 'field.reference',
            'capabilities' => ['data_binding' => true, 'events' => ['value_changed']],
        ],
        'heading' => [
            'label' => '區段標題',
            'version' => 1,
            'props' => [
                'text' => ['type' => 'string', 'required' => true, 'min_length' => 1, 'max_length' => 500],
                'bg' => ['type' => 'string', 'nullable' => true, 'pattern' => '/^#[0-9a-fA-F]{6}$/'],
                'border' => ['type' => 'string', 'nullable' => true, 'pattern' => '/^#[0-9a-fA-F]{6}$/'],
            ],
            'default_props' => ['bg' => null, 'border' => null],
            'allowed_parents' => ['column'],
            'allowed_children' => [],
            'renderer' => 'layout.heading',
            'capabilities' => ['data_binding' => false, 'events' => []],
        ],
        'note' => [
            'label' => '說明文字',
            'version' => 1,
            'props' => [
                'text' => ['type' => 'string', 'required' => true, 'min_length' => 1, 'max_length' => 500],
            ],
            'default_props' => [],
            'allowed_parents' => ['column'],
            'allowed_children' => [],
            'renderer' => 'layout.note',
            'capabilities' => ['data_binding' => false, 'events' => []],
        ],
        'divider' => [
            'label' => '分隔線',
            'version' => 1,
            'props' => [],
            'default_props' => [],
            'allowed_parents' => ['column'],
            'allowed_children' => [],
            'renderer' => 'layout.divider',
            'capabilities' => ['data_binding' => false, 'events' => []],
        ],
        'spacer' => [
            'label' => '空白格',
            'version' => 1,
            'props' => [],
            'default_props' => [],
            'allowed_parents' => ['column'],
            'allowed_children' => [],
            'renderer' => 'layout.spacer',
            'capabilities' => ['data_binding' => false, 'events' => []],
        ],
        'table' => [
            'label' => '明細表',
            'version' => 1,
            'props' => [
                'key' => ['type' => 'string', 'required' => true, 'min_length' => 1, 'max_length' => 30, 'pattern' => '/^[a-z][a-z0-9_]{0,29}$/'],
                'title' => ['type' => 'string', 'required' => true, 'min_length' => 1, 'max_length' => 50],
                'fields' => ['type' => 'array', 'required' => true, 'max_items' => 30, 'item_type' => 'array'],
            ],
            'default_props' => ['fields' => []],
            'allowed_parents' => ['column'],
            'allowed_children' => ['field'],
            'renderer' => 'layout.table',
            'capabilities' => ['data_binding' => false, 'events' => []],
        ],
    ],

    // 欄位型別沿用 document_builder.field_types 的繁中標籤，這裡只宣告型別專屬規則。
    'field_types' => [
        'text' => [
            'version' => 1,
            'default_width' => 6,
            'options' => [],
            'section_options' => [],
            'allowed_sections' => ['header', 'item'],
            'renderer' => 'field.text',
            'capabilities' => ['data_binding' => true, 'events' => ['value_changed']],
        ],
        'textarea' => [
            'version' => 1,
            'default_width' => 12,
            'options' => [],
            'section_options' => [],
            'allowed_sections' => ['header', 'item'],
            'renderer' => 'field.textarea',
            'capabilities' => ['data_binding' => true, 'events' => ['value_changed']],
        ],
        'number' => [
            'version' => 1,
            'default_width' => 4,
            'options' => [
                'decimals' => ['type' => 'integer', 'min' => 0, 'max' => 6],
            ],
            'section_options' => [
                'item' => ['sum' => ['type' => 'boolean']],
            ],
            'allowed_sections' => ['header', 'item'],
            'renderer' => 'field.number',
            'capabilities' => ['data_binding' => true, 'events' => ['value_changed']],
        ],
        'date' => [
            'version' => 1,
            'default_width' => 4,
            'options' => [],
            'section_options' => [],
            'allowed_sections' => ['header', 'item'],
            'renderer' => 'field.date',
            'capabilities' => ['data_binding' => true, 'events' => ['value_changed']],
        ],
        'amount' => [
            'version' => 1,
            'default_width' => 4,
            'options' => [
                'decimals' => ['type' => 'integer', 'required' => false, 'min' => 0, 'max' => 6],
                'currency' => ['type' => 'string', 'required' => false, 'max_length' => 5],
            ],
            'section_options' => [],
            'allowed_sections' => ['header', 'item'],
            'renderer' => 'field.amount',
            'capabilities' => ['data_binding' => true, 'events' => ['value_changed']],
        ],
        'checkbox' => [
            'version' => 1,
            'default_width' => 3,
            'options' => [],
            'section_options' => [],
            'allowed_sections' => ['header', 'item'],
            'renderer' => 'field.checkbox',
            'capabilities' => ['data_binding' => true, 'events' => ['value_changed']],
        ],
        'select' => [
            'version' => 1,
            'default_width' => 6,
            'options' => [
                'choices' => ['type' => 'array', 'required' => true, 'min_items' => 1, 'item_type' => 'string'],
            ],
            'section_options' => [],
            'allowed_sections' => ['header', 'item'],
            'renderer' => 'field.select',
            'capabilities' => ['data_binding' => true, 'events' => ['value_changed']],
        ],
        'radio' => [
            'version' => 1,
            'default_width' => 6,
            'options' => [
                'choices' => ['type' => 'array', 'required' => true, 'min_items' => 1, 'item_type' => 'string'],
                'layout' => ['type' => 'string', 'required' => false, 'enum' => ['horizontal', 'vertical']],
            ],
            'section_options' => [],
            'allowed_sections' => ['header', 'item'],
            'renderer' => 'field.radio',
            'capabilities' => ['data_binding' => true, 'events' => ['value_changed']],
        ],
        'checkgroup' => [
            'version' => 1,
            'default_width' => 6,
            'options' => [
                'choices' => ['type' => 'array', 'required' => true, 'min_items' => 1, 'item_type' => 'string'],
                'layout' => ['type' => 'string', 'required' => false, 'enum' => ['horizontal', 'vertical']],
            ],
            'section_options' => [],
            'allowed_sections' => ['header', 'item'],
            'renderer' => 'field.checkgroup',
            'capabilities' => ['data_binding' => true, 'events' => ['value_changed']],
        ],
        'master' => [
            'version' => 1,
            'default_width' => 6,
            'options' => [
                'source' => ['type' => 'string', 'required' => true, 'enum_from' => 'document_builder.masters'],
            ],
            'section_options' => [],
            'allowed_sections' => ['header', 'item'],
            'renderer' => 'field.master',
            'capabilities' => ['data_binding' => true, 'events' => ['value_changed']],
        ],
    ],
];
