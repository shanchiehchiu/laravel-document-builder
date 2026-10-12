<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocType;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * 將既有單據模型轉成平台 Schema v1，並轉回目前設計器接受的結構。
 * 這個 Adapter 不直接寫資料；儲存仍由既有設計器 Service 負責。
 */
class DocumentBuilderSchemaAdapter
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly FormLayoutService $layout) {}

    /** 匯出完整單據定義；欄位使用穩定 key 參照，不暴露資料庫主鍵。 */
    public function export(DocType $type): array
    {
        $type->loadMissing(['fields', 'statuses']);

        $rows = array_map(fn (array $cols): array => [
            'cols' => array_map(fn (array $col): array => [
                'width' => (int) $col['width'],
                'component' => $this->component($col),
            ], $cols),
        ], $this->layout->rows($type));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'form' => [
                'code' => $type->code,
                'name' => $type->name,
                'enabled' => (bool) $type->status,
                'order' => (int) $type->seq,
                'buttons' => collect(DocType::BUTTONS)
                    ->map(fn (string $label, string $key): bool => $type->hasButton($key))
                    ->all(),
                'numbering' => [
                    'prefix' => (string) $type->no_prefix,
                    'date_format' => (string) $type->no_date_format,
                    'sequence_digits' => (int) $type->no_seq_digits,
                ],
                'label_layout' => $type->label_layout,
            ],
            'layout' => [
                'rows' => $rows,
                // 此對照表也包含已封存明細表，供 Schema 完整保留歷史識別碼與名稱。
                'tables' => $type->form_layout['tables'] ?? [],
            ],
            'fields' => $type->fields
                ->map(fn (DocField $field): array => [
                    'key' => $field->key,
                    'section' => $field->section,
                    'table_key' => $field->table_key,
                    'label' => $field->label,
                    'type' => $field->type,
                    'required' => (bool) $field->required,
                    'width' => (int) $field->width,
                    'options' => $field->options ?? [],
                    'show_in_list' => (bool) $field->show_in_list,
                    'order' => (int) $field->seq,
                    'archived' => $field->archived_at !== null,
                ])
                ->values()
                ->all(),
            'statuses' => $type->statuses
                ->map(fn ($status): array => [
                    'code' => $status->code,
                    'name' => $status->name,
                    'is_default' => (bool) $status->is_default,
                    'locks_editing' => (bool) $status->locks_editing,
                    'color' => $status->color,
                    'order' => (int) $status->seq,
                ])
                ->all(),
            // 通用資料綁定尚未加入；事件只保存受控 DSL，不包含執行碼。
            'bindings' => [],
            'events' => $type->event_schema ?? [],
            'conversion_groups' => $type->conversion_groups ?? [],
        ];
    }

    /** 把 v1 Schema 轉成現有設計器 PUT 接受的 rows/settings payload。 */
    public function toDesignerPayload(DocType $type, array $schema): array
    {
        if (($schema['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('不支援的 Schema 版本');
        }
        if (($schema['form']['code'] ?? null) !== $type->code) {
            throw new InvalidArgumentException('Schema 單據代碼與目標單據不符');
        }
        $this->assertSupportedShape($schema);
        if (($schema['bindings'] ?? []) !== []) {
            throw new InvalidArgumentException('目前單據建置不支援通用資料綁定');
        }

        $type->loadMissing(['fields', 'statuses']);
        $current = $this->export($type);
        if (Arr::sortRecursive($schema['statuses'] ?? []) !== Arr::sortRecursive($current['statuses'])) {
            throw new InvalidArgumentException('目前設計器不支援修改單據狀態定義');
        }
        $this->assertSupportedMutation($current, $schema);

        $fieldDefinitions = collect($schema['fields'] ?? [])
            ->keyBy(fn (array $field): string => $this->fieldIdentity(
                (string) ($field['key'] ?? ''),
                (string) ($field['section'] ?? ''),
                $field['table_key'] ?? null
            ));
        $rows = [];

        foreach ($schema['layout']['rows'] ?? [] as $row) {
            $cols = [];
            foreach ($row['cols'] ?? [] as $col) {
                $component = $col['component'] ?? [];
                $componentType = (string) ($component['type'] ?? '');
                $width = (int) ($col['width'] ?? 0);

                if ($componentType === 'field') {
                    $cols[] = [
                        'width' => $width,
                        'item' => $this->designerField(
                            $type,
                            $fieldDefinitions,
                            (string) ($component['key'] ?? ''),
                            DocField::SECTION_HEADER,
                            null
                        ),
                    ];

                    continue;
                }

                if ($componentType === 'table') {
                    $tableKey = (string) ($component['key'] ?? '');
                    $persistedTableKey = array_key_exists($tableKey, $current['layout']['tables']) ? $tableKey : null;
                    $items = [];
                    foreach ($component['fields'] ?? [] as $fieldRef) {
                        $items[] = $this->designerField(
                            $type,
                            $fieldDefinitions,
                            (string) ($fieldRef['key'] ?? ''),
                            DocField::SECTION_ITEM,
                            $tableKey
                        );
                    }

                    $cols[] = [
                        'width' => 12,
                        'item' => [
                            'kind' => 'table',
                            // 新表交給既有 Service 依歷史表清單產生 table_N；已登記的表沿用原 key。
                            'key' => $persistedTableKey,
                            'title' => (string) ($component['title'] ?? ''),
                            'fields' => $items,
                        ],
                    ];

                    continue;
                }

                $cols[] = [
                    'width' => $width,
                    'item' => array_filter([
                        'kind' => $componentType,
                        'text' => $component['text'] ?? null,
                        'bg' => $component['bg'] ?? null,
                        'border' => $component['border'] ?? null,
                    ], fn (mixed $value): bool => $value !== null),
                ];
            }
            $rows[] = ['cols' => $cols];
        }

        $form = $schema['form'] ?? [];
        $numbering = $form['numbering'] ?? [];

        return [
            'rows' => $rows,
            'events' => array_values($schema['events'] ?? []),
            'conversion_groups' => array_values($schema['conversion_groups'] ?? []),
            'settings' => [
                'name' => (string) ($form['name'] ?? ''),
                'status' => (bool) ($form['enabled'] ?? false),
                'seq' => (int) ($form['order'] ?? 0),
                'no_prefix' => (string) ($numbering['prefix'] ?? ''),
                'no_date_format' => (string) ($numbering['date_format'] ?? ''),
                'no_seq_digits' => (int) ($numbering['sequence_digits'] ?? 0),
                'label_layout' => (string) ($form['label_layout'] ?? 'top'),
                'buttons' => $form['buttons'] ?? [],
            ],
        ];
    }

    private function component(array $col): array
    {
        if (isset($col['field'])) {
            return ['type' => 'field', 'key' => $col['field']->key];
        }

        if ($col['kind'] === 'table') {
            return [
                'type' => 'table',
                'key' => $col['key'],
                'title' => $col['title'],
                'fields' => $col['fields']->map(fn (DocField $field): array => ['key' => $field->key])->all(),
            ];
        }

        $component = ['type' => $col['kind']];
        if (in_array($col['kind'], ['heading', 'note'], true)) {
            $component['text'] = $col['text'] ?? '';
        }
        if ($col['kind'] === 'section') {
            $component['text'] = $col['text'] ?? '';
        }
        if ($col['kind'] === 'heading') {
            if (! empty($col['bg'])) {
                $component['bg'] = $col['bg'];
            }
            if (! empty($col['border'])) {
                $component['border'] = $col['border'];
            }
        }

        return $component;
    }

    private function designerField(
        DocType $type,
        Collection $definitions,
        string $key,
        string $section,
        ?string $tableKey
    ): array {
        $definition = $definitions->get($this->fieldIdentity($key, $section, $tableKey));
        $field = $type->fields->first(fn (DocField $candidate): bool => $candidate->key === $key
            && $candidate->section === $section
            && $candidate->table_key === $tableKey);

        if ($definition === null) {
            throw new InvalidArgumentException("Schema 欄位 {$key} 不存在或不屬於這種單據");
        }

        $options = $definition['options'] ?? [];

        return [
            'kind' => 'field',
            'id' => $field?->id,
            'key' => $definition['key'],
            'label' => $definition['label'],
            'type' => $definition['type'],
            'required' => (bool) $definition['required'],
            'show_in_list' => (bool) $definition['show_in_list'],
            'choices' => implode("\n", $options['choices'] ?? []),
            'layout' => $options['layout'] ?? null,
            'currency' => $options['currency'] ?? null,
            'decimals' => $options['decimals'] ?? null,
            'source' => $options['source'] ?? null,
            'sum' => $options['sum'] ?? false,
        ];
    }

    /** 防止無法由既有 Service 表達的刪除、封存資料改寫及欄位代碼變更。 */
    private function assertSupportedMutation(array $current, array $schema): void
    {
        $currentFields = collect($current['fields'])->keyBy(fn (array $field): string => $this->fieldIdentity(
            $field['key'], $field['section'], $field['table_key']
        ));
        $schemaFields = collect($schema['fields'] ?? [])->keyBy(fn (array $field): string => $this->fieldIdentity(
            (string) ($field['key'] ?? ''),
            (string) ($field['section'] ?? ''),
            $field['table_key'] ?? null
        ));
        $placedFields = [];
        $activeTables = [];

        foreach ($schema['layout']['rows'] ?? [] as $row) {
            foreach ($row['cols'] ?? [] as $col) {
                $component = $col['component'] ?? [];
                if (($component['type'] ?? null) === 'field') {
                    $placedFields[$this->fieldIdentity((string) ($component['key'] ?? ''), 'header', null)] = true;
                }
                if (($component['type'] ?? null) !== 'table') {
                    continue;
                }

                $tableKey = (string) ($component['key'] ?? '');
                $activeTables[$tableKey] = true;
                foreach ($component['fields'] ?? [] as $fieldReference) {
                    $placedFields[$this->fieldIdentity((string) ($fieldReference['key'] ?? ''), 'item', $tableKey)] = true;
                }
            }
        }

        foreach ($currentFields as $identity => $currentField) {
            $schemaField = $schemaFields->get($identity);
            if ($schemaField === null) {
                throw new InvalidArgumentException('目前設計器不支援移除既有欄位定義或變更欄位代碼');
            }
            if (isset($placedFields[$identity])) {
                continue;
            }

            $currentField['archived'] = false;
            $schemaField['archived'] = false;
            if ($schemaField !== $currentField) {
                throw new InvalidArgumentException('目前設計器不支援修改未放在版面上的封存欄位');
            }
        }

        foreach ($schemaFields as $identity => $schemaField) {
            if (! $currentFields->has($identity) && ! isset($placedFields[$identity])) {
                throw new InvalidArgumentException('新增欄位必須放在版面中');
            }
        }

        $currentTables = $current['layout']['tables'];
        $schemaTables = $schema['layout']['tables'] ?? [];
        $currentActiveTables = [];
        foreach ($current['layout']['rows'] as $row) {
            foreach ($row['cols'] as $col) {
                $component = $col['component'];
                if (($component['type'] ?? null) === 'table') {
                    $currentActiveTables[$component['key']] = true;
                }
            }
        }

        foreach ($currentTables as $key => $title) {
            if (! array_key_exists($key, $schemaTables)) {
                throw new InvalidArgumentException('目前設計器不支援移除已建立的明細表識別碼');
            }
            if (! isset($currentActiveTables[$key]) && $schemaTables[$key] !== $title) {
                throw new InvalidArgumentException('目前設計器不支援修改已封存的明細表定義');
            }
        }
        foreach ($schemaTables as $key => $title) {
            if (! array_key_exists($key, $currentTables) && ! isset($activeTables[$key])) {
                throw new InvalidArgumentException('新增明細表必須放在版面中');
            }
        }
    }

    private function fieldIdentity(string $key, string $section, ?string $tableKey): string
    {
        return implode(':', [$section, (string) $tableKey, $key]);
    }

    /** 拒絕 Adapter 尚未定義的屬性，避免 HTML、CSS 或其他內容被靜默忽略。 */
    private function assertSupportedShape(array $schema): void
    {
        $this->assertOnlyKeys($schema, ['schema_version', 'form', 'layout', 'fields', 'statuses', 'bindings', 'events', 'conversion_groups'], 'Schema');
        $form = $schema['form'] ?? [];
        $this->assertOnlyKeys($form, ['code', 'name', 'enabled', 'order', 'buttons', 'numbering', 'label_layout'], 'Schema form');
        $this->assertOnlyKeys($form['buttons'] ?? [], array_keys(DocType::BUTTONS), 'Schema 按鈕');
        $this->assertOnlyKeys($form['numbering'] ?? [], ['prefix', 'date_format', 'sequence_digits'], 'Schema 單號設定');

        $layout = $schema['layout'] ?? [];
        $this->assertOnlyKeys($layout, ['rows', 'tables'], 'Schema 版面');
        foreach ($layout['rows'] ?? [] as $row) {
            $this->assertOnlyKeys($row, ['cols'], 'Schema 列');
            foreach ($row['cols'] ?? [] as $col) {
                $this->assertOnlyKeys($col, ['width', 'component'], 'Schema 欄');
                $component = $col['component'] ?? [];
                $componentType = $component['type'] ?? '';
                $allowed = match ($componentType) {
                    'field' => ['type', 'key'],
                    'table' => ['type', 'key', 'title', 'fields'],
                    'heading' => ['type', 'text', 'bg', 'border'],
                    'section' => ['type', 'text'],
                    'note' => ['type', 'text'],
                    'spacer' => ['type'],
                    'divider' => ['type'],
                    default => throw new InvalidArgumentException('Schema 含有未登記的元件類型'),
                };
                $this->assertOnlyKeys($component, $allowed, 'Schema 元件');

                foreach ($component['fields'] ?? [] as $fieldRef) {
                    $this->assertOnlyKeys($fieldRef, ['key'], 'Schema 明細欄位參照');
                }
            }
        }

        foreach ($schema['fields'] ?? [] as $field) {
            $this->assertOnlyKeys($field, [
                'key', 'section', 'table_key', 'label', 'type', 'required', 'width', 'options', 'show_in_list', 'order', 'archived',
            ], 'Schema 欄位定義');
            $this->assertOnlyKeys($field['options'] ?? [], ['choices', 'layout', 'currency', 'decimals', 'source', 'sum'], 'Schema 欄位選項');
        }

        foreach ($schema['statuses'] ?? [] as $status) {
            $this->assertOnlyKeys($status, ['code', 'name', 'is_default', 'locks_editing', 'color', 'order'], 'Schema 狀態');
        }

        foreach ($schema['events'] ?? [] as $event) {
            $this->assertOnlyKeys($event, ['trigger', 'condition', 'actions'], 'Schema 事件');
            $this->assertOnlyKeys($event['trigger'] ?? [], ['type', 'field'], 'Schema 事件觸發');
            $this->assertOnlyKeys($event['condition'] ?? [], ['operator', 'value'], 'Schema 事件條件');
            foreach ($event['actions'] ?? [] as $action) {
                $this->assertOnlyKeys($action, ['type', 'field', 'value'], 'Schema 事件動作');
            }
        }
    }

    private function assertOnlyKeys(array $data, array $allowed, string $context): void
    {
        $unknown = array_values(array_diff(array_keys($data), $allowed));
        if ($unknown !== []) {
            throw new InvalidArgumentException("{$context}含有不支援的 Schema 屬性：".implode('、', $unknown));
        }
    }
}
