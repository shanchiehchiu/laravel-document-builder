<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use InvalidArgumentException;
use LogicException;

/**
 * 受控表單元件與欄位型別的唯讀登錄表。
 * 元件 renderer 只使用設定中的穩定代碼，不從 Schema 載入 class、view 或執行碼。
 */
class ComponentRegistry
{
    /** @return array<string, array> 版面元件代碼及其契約 */
    public function components(): array
    {
        return config('form_platform.components', []);
    }

    public function component(string $type): array
    {
        $component = $this->components()[$type] ?? null;
        if ($component === null) {
            throw new InvalidArgumentException("未登記的表單元件：{$type}");
        }

        return $component;
    }

    /** @return array<string, array> 沿用 DocumentBuilder 的欄位型別與繁體中文標籤 */
    public function fieldTypes(): array
    {
        $labels = config('document_builder.field_types', []);
        $definitions = config('form_platform.field_types', []);
        $missing = array_diff(array_keys($labels), array_keys($definitions));
        $unknown = array_diff(array_keys($definitions), array_keys($labels));

        if ($missing !== [] || $unknown !== []) {
            throw new LogicException('表單平台欄位型別登錄與 DocumentBuilder 欄位型別不一致');
        }

        $fieldTypes = [];
        foreach ($labels as $type => $label) {
            $fieldTypes[$type] = $definitions[$type] + ['type' => $type, 'label' => $label];
        }

        return $fieldTypes;
    }

    public function fieldType(string $type): array
    {
        $fieldType = $this->fieldTypes()[$type] ?? null;
        if ($fieldType === null) {
            throw new InvalidArgumentException("未登記的欄位型別：{$type}");
        }

        return $fieldType;
    }

    /** 提供 MCP 元件查詢工具可直接序列化的兩組白名單。 */
    public function listing(): array
    {
        return ['components' => $this->components(), 'field_types' => $this->fieldTypes()];
    }

    /** 驗證版面元件的屬性及其父子元件限制。 */
    public function assertValidComponent(
        string $type,
        array $props,
        ?string $parentType = null,
        array $children = []
    ): void {
        $definition = $this->component($type);
        if ($parentType !== null && ! in_array($parentType, $definition['allowed_parents'], true)) {
            throw new InvalidArgumentException("元件 {$type} 不允許放在 {$parentType} 之下");
        }

        $props = array_replace($definition['default_props'], $props);
        $this->assertProperties($definition['props'], $props, "元件 {$type}");

        if ($type === 'table') {
            $this->assertTableFieldReferences($props['fields'] ?? []);
        }

        foreach ($children as $childType) {
            if (! in_array($childType, $definition['allowed_children'], true)) {
                throw new InvalidArgumentException("元件 {$type} 不允許包含 {$childType} 子元件");
            }
        }
    }

    /** 驗證欄位型別專屬選項；合計選項只允許出現在明細欄位。 */
    public function assertValidFieldOptions(string $type, array $options, string $section): void
    {
        $definition = $this->fieldType($type);
        if (! in_array($section, $definition['allowed_sections'], true)) {
            throw new InvalidArgumentException("欄位型別 {$type} 不允許用於 {$section} 區塊");
        }

        $properties = $definition['options'] + ($definition['section_options'][$section] ?? []);
        $this->assertProperties($properties, $options, "欄位型別 {$type}");
    }

    private function assertProperties(array $definitions, array $values, string $context): void
    {
        $unknown = array_values(array_diff(array_keys($values), array_keys($definitions)));
        if ($unknown !== []) {
            throw new InvalidArgumentException("{$context}含有不支援的屬性：".implode('、', $unknown));
        }

        foreach ($definitions as $name => $definition) {
            if (! array_key_exists($name, $values)) {
                if ($definition['required'] ?? false) {
                    throw new InvalidArgumentException("{$context}缺少必要屬性：{$name}");
                }

                continue;
            }

            $value = $values[$name];
            if ($value === null && ($definition['nullable'] ?? false)) {
                continue;
            }

            if (! $this->matchesType($value, $definition['type'])) {
                throw new InvalidArgumentException("{$context}的 {$name} 型別不正確");
            }

            if (is_string($value)) {
                $length = mb_strlen($value);
                if (($definition['required'] ?? false) && trim($value) === '') {
                    throw new InvalidArgumentException("{$context}的 {$name} 不能空白");
                }
                if (isset($definition['min_length']) && $length < $definition['min_length']) {
                    throw new InvalidArgumentException("{$context}的 {$name} 長度不足");
                }
                if (isset($definition['max_length']) && $length > $definition['max_length']) {
                    throw new InvalidArgumentException("{$context}的 {$name} 超過長度限制");
                }
                if (isset($definition['pattern']) && preg_match($definition['pattern'], $value) !== 1) {
                    throw new InvalidArgumentException("{$context}的 {$name} 格式不正確");
                }
            }

            if (is_int($value) || is_float($value)) {
                if (isset($definition['min']) && $value < $definition['min']) {
                    throw new InvalidArgumentException("{$context}的 {$name} 小於允許範圍");
                }
                if (isset($definition['max']) && $value > $definition['max']) {
                    throw new InvalidArgumentException("{$context}的 {$name} 大於允許範圍");
                }
            }

            if (is_array($value)) {
                if (isset($definition['min_items']) && count($value) < $definition['min_items']) {
                    throw new InvalidArgumentException("{$context}的 {$name} 至少需要 {$definition['min_items']} 項");
                }
                if (isset($definition['max_items']) && count($value) > $definition['max_items']) {
                    throw new InvalidArgumentException("{$context}的 {$name} 超過項目數量限制");
                }
                if (isset($definition['item_type'])) {
                    foreach ($value as $item) {
                        if (! $this->matchesType($item, $definition['item_type'])) {
                            throw new InvalidArgumentException("{$context}的 {$name} 含有型別不正確的項目");
                        }
                    }
                }
            }

            if (isset($definition['enum']) && ! in_array($value, $definition['enum'], true)) {
                throw new InvalidArgumentException("{$context}的 {$name} 不在允許清單內");
            }
            if (isset($definition['enum_from'])) {
                $allowed = array_keys((array) config($definition['enum_from'], []));
                if (! in_array($value, $allowed, true)) {
                    throw new InvalidArgumentException("{$context}的 {$name} 不在允許清單內");
                }
            }
        }
    }

    private function assertTableFieldReferences(array $fields): void
    {
        $seen = [];
        $reference = ['key' => ['type' => 'string', 'required' => true, 'min_length' => 1, 'max_length' => 50, 'pattern' => '/^[a-z][a-z0-9_]{0,49}$/']];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                throw new InvalidArgumentException('明細表欄位參照格式不正確');
            }
            $this->assertProperties($reference, $field, '明細表欄位參照');
            $key = $field['key'];
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("明細表欄位 {$key} 重複");
            }
            $seen[$key] = true;
        }
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            default => false,
        };
    }
}
