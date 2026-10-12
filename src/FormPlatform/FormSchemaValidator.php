<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use DocumentBuilder\Models\DocType;
use InvalidArgumentException;

/** 依據 v1 契約與 Component Registry 驗證表單定義，錯誤皆附上可定位的 Schema 路徑。 */
class FormSchemaValidator
{
    private array $errors = [];

    public function __construct(
        private readonly ComponentRegistry $registry,
        private readonly EventSchemaValidator $eventValidator,
        private readonly ConversionGroupValidator $conversionValidator,
    ) {}

    /** @return array{valid: bool, errors: array<int, array{path: string, message: string}>, warnings: array<int, array{path: string, message: string}>} */
    public function validate(array $schema, ?string $expectedFormCode = null): array
    {
        $this->errors = [];
        $this->onlyKeys($schema, ['schema_version', 'form', 'layout', 'fields', 'statuses', 'bindings', 'events', 'conversion_groups'], '');

        if (($schema['schema_version'] ?? null) !== config('form_platform.schema_version')) {
            $this->error('schema_version', '不支援的表單 Schema 版本');

            return $this->result();
        }

        foreach (['form', 'layout', 'fields', 'statuses', 'bindings', 'events'] as $key) {
            if (! array_key_exists($key, $schema)) {
                $this->error($key, '缺少必要的 Schema 區塊');
            }
        }

        $form = $schema['form'] ?? null;
        if (is_array($form)) {
            $this->validateForm($form);
            if ($expectedFormCode !== null && ($form['code'] ?? null) !== $expectedFormCode) {
                $this->error('form.code', 'Schema 單據代碼與目標單據不符');
            }
        } else {
            $this->error('form', '單據設定必須是物件');
        }

        $fields = $schema['fields'] ?? null;
        $fieldIndex = is_array($fields) ? $this->validateFields($fields) : [];
        if (! is_array($fields)) {
            $this->error('fields', '欄位定義必須是陣列');
        }

        $layout = $schema['layout'] ?? null;
        $placedFields = [];
        $placedTables = [];
        if (is_array($layout)) {
            $this->validateLayout($layout, $fieldIndex, $placedFields, $placedTables);
        } else {
            $this->error('layout', '版面定義必須是物件');
        }

        foreach ($fieldIndex as $identity => $field) {
            if (! $field['archived'] && ! isset($placedFields[$identity])) {
                $this->error($field['path'].'.key', '未封存欄位必須放在版面中');
            }
        }

        $statuses = $schema['statuses'] ?? null;
        if (is_array($statuses)) {
            $this->validateStatuses($statuses);
        } else {
            $this->error('statuses', '狀態定義必須是陣列');
        }

        if (! is_array($schema['bindings'] ?? null)) {
            $this->error('bindings', '目前版本的資料綁定區塊必須是陣列');
        } elseif ($schema['bindings'] !== []) {
            $this->error('bindings', '目前版本尚不支援通用資料綁定');
        }

        foreach ($this->eventValidator->validate($schema['events'] ?? null, $fields ?? [])['errors'] as $error) {
            $this->error($error['path'], $error['message']);
        }

        foreach ($this->conversionValidator->validate($schema['conversion_groups'] ?? null, $fields ?? [], is_array($schema['events'] ?? null) ? $schema['events'] : [])['errors'] as $error) {
            $this->error($error['path'], $error['message']);
        }

        return $this->result();
    }

    private function validateForm(array $form): void
    {
        $path = 'form';
        $this->onlyKeys($form, ['code', 'name', 'enabled', 'order', 'buttons', 'numbering'], $path);
        $this->string($form, 'code', $path, 30, '/^[a-z][a-z0-9_]*$/');
        $this->string($form, 'name', $path, 50);
        $this->boolean($form, 'enabled', $path);
        $this->integer($form, 'order', $path, 0);

        $buttons = $form['buttons'] ?? null;
        if (! is_array($buttons)) {
            $this->error($path.'.buttons', '按鈕設定必須是物件');
        } else {
            $buttonKeys = array_keys(DocType::BUTTONS);
            $this->onlyKeys($buttons, $buttonKeys, $path.'.buttons');
            foreach ($buttonKeys as $key) {
                if (! array_key_exists($key, $buttons)) {
                    $this->error($path.'.buttons.'.$key, '缺少必要的按鈕設定');
                } elseif (! is_bool($buttons[$key])) {
                    $this->error($path.'.buttons.'.$key, '按鈕設定必須是布林值');
                }
            }
        }

        $numbering = $form['numbering'] ?? null;
        if (! is_array($numbering)) {
            $this->error($path.'.numbering', '單號設定必須是物件');

            return;
        }

        $this->onlyKeys($numbering, ['prefix', 'date_format', 'sequence_digits'], $path.'.numbering');
        if (! array_key_exists('prefix', $numbering)) {
            $this->error($path.'.numbering.prefix', '缺少必要的單號前綴設定');
        } elseif ($numbering['prefix'] !== null) {
            $this->string($numbering, 'prefix', $path.'.numbering', 20, allowEmpty: true);
        }
        if (! in_array($numbering['date_format'] ?? null, ['Ymd', 'Ym', 'Y'], true)) {
            $this->error($path.'.numbering.date_format', '日期格式只能是 Ymd、Ym 或 Y');
        }
        $this->integer($numbering, 'sequence_digits', $path.'.numbering', 1, 12);
    }

    /** @return array<string, array{section: string, table_key: ?string, archived: bool, path: string}> */
    private function validateFields(array $fields): array
    {
        $index = [];
        $seen = [];
        if (! array_is_list($fields)) {
            $this->error('fields', '欄位定義必須是依序排列的陣列');
        }

        foreach ($fields as $position => $field) {
            $path = "fields.{$position}";
            if (! is_array($field)) {
                $this->error($path, '欄位定義必須是物件');

                continue;
            }

            $this->onlyKeys($field, [
                'key', 'section', 'table_key', 'label', 'type', 'required', 'width', 'options', 'show_in_list', 'order', 'archived',
            ], $path);

            $key = $field['key'] ?? null;
            $section = $field['section'] ?? null;
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,49}$/', $key) !== 1) {
                $this->error($path.'.key', '欄位代碼格式不正確');
            }
            if (! in_array($section, ['header', 'item'], true)) {
                $this->error($path.'.section', '欄位區塊只能是 header 或 item');
            }

            $tableKey = $field['table_key'] ?? null;
            if (! array_key_exists('table_key', $field)) {
                $this->error($path.'.table_key', '欄位定義必須包含 table_key，表頭欄位請填 null');
            }
            if ($section === 'header' && $tableKey !== null) {
                $this->error($path.'.table_key', '表頭欄位不得指定明細表代碼');
            }
            if ($section === 'item' && (! is_string($tableKey) || preg_match('/^[a-z][a-z0-9_]{0,29}$/', $tableKey) !== 1)) {
                $this->error($path.'.table_key', '明細欄位必須指定有效的明細表代碼');
            }

            $this->string($field, 'label', $path, 50);
            if (! is_string($field['type'] ?? null)) {
                $this->error($path.'.type', '欄位型別必須是文字');
            } else {
                try {
                    $this->registry->fieldType($field['type']);
                } catch (InvalidArgumentException $exception) {
                    $this->error($path.'.type', $exception->getMessage());
                }
            }
            $this->boolean($field, 'required', $path);
            $this->width($field['width'] ?? null, $path.'.width');
            if (! is_array($field['options'] ?? null)) {
                $this->error($path.'.options', '欄位選項必須是物件');
            } elseif (is_string($field['type'] ?? null) && in_array($section, ['header', 'item'], true)) {
                try {
                    $this->registry->assertValidFieldOptions($field['type'], $field['options'], $section);
                } catch (InvalidArgumentException $exception) {
                    $this->error($path.'.options', $exception->getMessage());
                }
            }
            $this->boolean($field, 'show_in_list', $path);
            $this->integer($field, 'order', $path, 0);
            $this->boolean($field, 'archived', $path);

            if (is_string($key) && in_array($section, ['header', 'item'], true)) {
                $identity = $section.':'.$key;
                if (isset($seen[$identity])) {
                    $this->error($path.'.key', '欄位代碼在同一區塊中重複');
                } else {
                    $seen[$identity] = true;
                    $index[$identity] = [
                        'section' => $section,
                        'table_key' => is_string($tableKey) ? $tableKey : null,
                        'archived' => ($field['archived'] ?? false) === true,
                        'path' => $path,
                    ];
                }
            }
        }

        return $index;
    }

    /** @param array<string, array{section: string, table_key: ?string, archived: bool, path: string}> $fieldIndex */
    private function validateLayout(array $layout, array $fieldIndex, array &$placedFields, array &$placedTables): void
    {
        $this->onlyKeys($layout, ['rows', 'tables'], 'layout');
        $tables = $layout['tables'] ?? null;
        if (! is_array($tables)) {
            $this->error('layout.tables', '明細表清單必須是物件');
            $tables = [];
        }
        foreach ($tables as $key => $title) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,29}$/', $key) !== 1) {
                $this->error('layout.tables.'.$key, '明細表代碼格式不正確');
            }
            if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 50) {
                $this->error('layout.tables.'.$key, '明細表名稱必須是 1 到 50 個字');
            }
        }

        $rows = $layout['rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100) {
            $this->error('layout.rows', '版面列數必須是 0 到 100 列的陣列');

            return;
        }

        foreach ($rows as $rowIndex => $row) {
            $rowPath = "layout.rows.{$rowIndex}";
            if (! is_array($row)) {
                $this->error($rowPath, '版面列必須是物件');

                continue;
            }
            $this->onlyKeys($row, ['cols'], $rowPath);
            $cols = $row['cols'] ?? null;
            if (! is_array($cols) || ! array_is_list($cols) || $cols === [] || count($cols) > 12) {
                $this->error($rowPath.'.cols', '每列必須有 1 到 12 格');

                continue;
            }

            $rowHasTable = false;
            foreach ($cols as $colIndex => $col) {
                $colPath = "{$rowPath}.cols.{$colIndex}";
                if (! is_array($col)) {
                    $this->error($colPath, '版面欄位必須是物件');

                    continue;
                }
                $this->onlyKeys($col, ['width', 'component'], $colPath);
                $width = $col['width'] ?? null;
                $this->width($width, $colPath.'.width');
                $component = $col['component'] ?? null;
                if (! is_array($component)) {
                    $this->error($colPath.'.component', '版面元件必須是物件');

                    continue;
                }
                $type = $component['type'] ?? null;
                if (! is_string($type) || ! array_key_exists($type, $this->registry->components())) {
                    $this->error($colPath.'.component.type', '未登記的表單元件類型');

                    continue;
                }

                $definition = $this->registry->component($type);
                $propKeys = array_keys($definition['props']);
                $componentKeys = array_merge(['type'], $propKeys);
                $unknownProps = array_diff(array_keys($component), $componentKeys);
                foreach ($unknownProps as $unknownProp) {
                    $this->error($colPath.'.component.'.$unknownProp, '不支援的元件屬性');
                }
                try {
                    $props = array_intersect_key($component, array_flip($propKeys));
                    $tableFields = is_array($component['fields'] ?? null) ? $component['fields'] : [];
                    $this->registry->assertValidComponent($type, $props, 'column', $type === 'table'
                        ? array_fill(0, count($tableFields), 'field')
                        : []);
                } catch (InvalidArgumentException $exception) {
                    $this->error($colPath.'.component', $exception->getMessage());
                }

                if ($type === 'table') {
                    $rowHasTable = true;
                    $tableKey = $component['key'] ?? null;
                    if (count($cols) !== 1 || $width !== 12) {
                        $this->error($colPath, '明細表必須獨佔一列並使用整列寬度');
                    }
                    if (is_string($tableKey)) {
                        if (isset($placedTables[$tableKey])) {
                            $this->error($colPath.'.component.key', '明細表代碼在版面中重複');
                        }
                        $placedTables[$tableKey] = true;
                        if (! array_key_exists($tableKey, $tables)) {
                            $this->error($colPath.'.component.key', '明細表代碼未登記於 layout.tables');
                        } elseif (($tables[$tableKey] ?? null) !== ($component['title'] ?? null)) {
                            $this->error($colPath.'.component.title', '明細表名稱與 layout.tables 不一致');
                        }

                        foreach ($tableFields as $fieldIndexInTable => $reference) {
                            $refPath = $colPath.'.component.fields.'.$fieldIndexInTable.'.key';
                            $key = is_array($reference) ? ($reference['key'] ?? null) : null;
                            $identity = 'item:'.$key;
                            $this->placeField($identity, 'item', $tableKey, $refPath, $fieldIndex, $placedFields);
                        }
                    }
                } elseif ($type === 'field') {
                    $key = $component['key'] ?? null;
                    $identity = 'header:'.$key;
                    $this->placeField($identity, 'header', null, $colPath.'.component.key', $fieldIndex, $placedFields);
                }
            }

            if ($rowHasTable && count($cols) !== 1) {
                $this->error($rowPath.'.cols', '明細表必須獨佔一列');
            }
        }
    }

    /** @param array<string, array{section: string, table_key: ?string, archived: bool, path: string}> $fieldIndex */
    private function placeField(string $identity, string $section, ?string $tableKey, string $path, array $fieldIndex, array &$placedFields): void
    {
        $field = $fieldIndex[$identity] ?? null;
        if ($field === null) {
            $this->error($path, '版面參照的欄位不存在');

            return;
        }
        if ($field['section'] !== $section || $field['table_key'] !== $tableKey) {
            $this->error($path, '欄位參照的區塊或明細表不相符');
        }
        if (isset($placedFields[$identity])) {
            $this->error($path, '欄位識別碼在版面中重複');
        }
        $placedFields[$identity] = true;
    }

    private function validateStatuses(array $statuses): void
    {
        $seen = [];
        $defaultCount = 0;
        $colors = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];
        if (! array_is_list($statuses)) {
            $this->error('statuses', '狀態定義必須是依序排列的陣列');
        }

        foreach ($statuses as $position => $status) {
            $path = "statuses.{$position}";
            if (! is_array($status)) {
                $this->error($path, '狀態定義必須是物件');

                continue;
            }
            $this->onlyKeys($status, ['code', 'name', 'is_default', 'locks_editing', 'color', 'order'], $path);
            $this->string($status, 'code', $path, 50, '/^[a-z][a-z0-9_]*$/');
            $this->string($status, 'name', $path, 50);
            $this->boolean($status, 'is_default', $path);
            $this->boolean($status, 'locks_editing', $path);
            if (! in_array($status['color'] ?? null, $colors, true)) {
                $this->error($path.'.color', '狀態顏色不在允許清單內');
            }
            $this->integer($status, 'order', $path, 0);

            if (is_string($status['code'] ?? null)) {
                if (isset($seen[$status['code']])) {
                    $this->error($path.'.code', '狀態代碼重複');
                }
                $seen[$status['code']] = true;
            }
            if (($status['is_default'] ?? false) === true) {
                $defaultCount++;
            }
        }

        if ($defaultCount !== 1) {
            $this->error('statuses', '必須且只能有一個預設狀態');
        }
    }

    private function width(mixed $value, string $path): void
    {
        if (! is_int($value) || ! array_key_exists($value, config('document_builder.widths', []))) {
            $this->error($path, '欄寬必須是設計器支援的 3、4、6 或 12');
        }
    }

    private function string(
        array $data,
        string $key,
        string $path,
        int $maxLength,
        ?string $pattern = null,
        bool $nullable = false,
        bool $allowEmpty = false
    ): void {
        $value = $data[$key] ?? null;
        if ($nullable && $value === null) {
            return;
        }
        if (! is_string($value) || (! $allowEmpty && trim($value) === '') || mb_strlen($value) > $maxLength
            || ($pattern !== null && preg_match($pattern, $value) !== 1)) {
            $this->error($path.'.'.$key, '欄位必須是符合格式與長度限制的文字');
        }
    }

    private function boolean(array $data, string $key, string $path): void
    {
        if (! is_bool($data[$key] ?? null)) {
            $this->error($path.'.'.$key, '欄位必須是布林值');
        }
    }

    private function integer(array $data, string $key, string $path, int $min, ?int $max = null): void
    {
        $value = $data[$key] ?? null;
        if (! is_int($value) || $value < $min || ($max !== null && $value > $max)) {
            $range = $max === null ? "至少 {$min}" : "介於 {$min} 到 {$max}";
            $this->error($path.'.'.$key, "欄位必須是 {$range} 的整數");
        }
    }

    private function onlyKeys(array $data, array $allowed, string $path): void
    {
        foreach (array_diff(array_keys($data), $allowed) as $key) {
            $this->error($path === '' ? (string) $key : $path.'.'.$key, '不支援的 Schema 屬性');
        }
    }

    private function error(string $path, string $message): void
    {
        $this->errors[] = ['path' => $path === '' ? '$' : $path, 'message' => $message];
    }

    private function result(): array
    {
        return ['valid' => $this->errors === [], 'errors' => $this->errors, 'warnings' => []];
    }
}
