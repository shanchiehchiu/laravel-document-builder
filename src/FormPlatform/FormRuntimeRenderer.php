<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Services\DocumentBuilderSchemaAdapter;
use DocumentBuilder\Services\FormLayoutService;
use InvalidArgumentException;

/** 將已驗證的 Schema v1 轉成共用 Blade Runtime 可安全呈現的元件列。 */
class FormRuntimeRenderer
{
    public function __construct(
        private readonly DocumentBuilderSchemaAdapter $adapter,
        private readonly FormSchemaValidator $validator,
        private readonly ComponentRendererRegistry $renderers,
    ) {}

    /** 正式單據使用目前儲存的 Schema，並走與預覽相同的 renderer。 */
    public function rowsForType(DocType $type, ?array $schema = null): array
    {
        return $this->rows($schema ?? $this->adapter->export($type), $type->code);
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    public function rows(array $schema, ?string $expectedCode = null): array
    {
        $validation = $this->validator->validate($schema, $expectedCode);
        if (! $validation['valid']) {
            $messages = array_map(
                fn (array $error): string => $error['path'].': '.$error['message'],
                $validation['errors']
            );
            throw new InvalidArgumentException('表單 Schema 無法呈現：'.implode('；', $messages));
        }

        $fields = [];
        foreach ($schema['fields'] as $definition) {
            if ($definition['archived']) {
                continue;
            }
            $fields[$this->fieldIdentity($definition['key'], $definition['section'], $definition['table_key'])]
                = $this->field($definition);
        }

        $rows = [];
        foreach ($schema['layout']['rows'] as $row) {
            $cols = [];
            foreach ($row['cols'] as $col) {
                $component = $col['component'];
                $type = $component['type'];
                $view = $this->renderers->componentView($type);
                $base = [
                    'width' => $col['width'],
                    'kind' => $type,
                    'renderer' => $this->renderers->componentRenderer($type),
                    'view' => $view,
                ];

                if ($type === 'field') {
                    $field = $fields[$this->fieldIdentity($component['key'], 'header', null)] ?? null;
                    if (! $field) {
                        throw new InvalidArgumentException('版面參照的表頭欄位不存在');
                    }
                    $cols[] = $base + ['field' => $field];

                    continue;
                }

                if ($type === 'table') {
                    $tableFields = [];
                    foreach ($component['fields'] as $reference) {
                        $field = $fields[$this->fieldIdentity($reference['key'], 'item', $component['key'])] ?? null;
                        if (! $field) {
                            throw new InvalidArgumentException('明細表參照的欄位不存在');
                        }
                        $tableFields[] = $field;
                    }
                    $cols[] = $base + [
                        'width' => 12,
                        'key' => $component['key'],
                        'title' => $component['title'],
                        'fields' => collect($tableFields),
                    ];

                    continue;
                }

                if ($type === 'section') {
                    // 區塊只是分隔標記，佔滿整列；之後的列由 FormLayoutService::sectionBlocks() 歸進區塊
                    $cols[] = $base + ['width' => 12, 'text' => $component['text'] ?? '', 'bg' => $component['bg'] ?? null];

                    continue;
                }

                $cols[] = $base + [
                    'text' => $component['text'] ?? '',
                    'bg' => $component['bg'] ?? null,
                    'border' => $component['border'] ?? null,
                    'style' => $type === 'heading'
                        ? FormLayoutService::headingStyle($component['bg'] ?? null, $component['border'] ?? null)
                        : null,
                ];
            }
            $rows[] = $cols;
        }

        return $rows;
    }

    private function field(array $definition): DocField
    {
        $field = new DocField([
            'section' => $definition['section'],
            'table_key' => $definition['table_key'],
            'key' => $definition['key'],
            'label' => $definition['label'],
            'type' => $definition['type'],
            'required' => $definition['required'],
            'width' => $definition['width'],
            'options' => $definition['options'],
            'show_in_list' => $definition['show_in_list'],
            'seq' => $definition['order'],
            'archived_at' => null,
        ]);
        $field->setAttribute('runtime_renderer', $this->renderers->fieldRenderer($definition['type']));

        return $field;
    }

    private function fieldIdentity(string $key, string $section, ?string $tableKey): string
    {
        return $section.':'.($tableKey ?? '').':'.$key;
    }
}
