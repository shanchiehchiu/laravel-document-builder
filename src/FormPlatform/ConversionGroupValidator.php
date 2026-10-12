<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

/**
 * 驗證換算組（單價 ↔ 折數）的宣告。換算組不放進 events：events 的循環檢查會把雙向關係判成循環依賴。
 *
 * 每組格式：{kind: price_discount, table_key, base, price, rate, guard: null | {field}}
 * - 三個欄位必須是同一個明細表中、未封存、型別為數字或金額的欄位，且彼此不同。
 * - 同一欄位只能屬於一組。
 * - 一般事件不得設定換算組控管的欄位（base、price、rate），避免兩套規則互相改寫。
 * - guard 的欄位可以不存在（不存在視為未促銷），所以不驗證它的存在。
 */
class ConversionGroupValidator
{
    private const MAX_GROUPS = 10;

    private const KIND = 'price_discount';

    private const ROLES = ['base', 'price', 'rate'];

    private const VALUE_TYPES = ['number', 'amount'];

    /** @return array{valid: bool, errors: array<int, array{path: string, message: string}>} */
    public function validate(mixed $groups, array $fields, array $events = []): array
    {
        $errors = [];
        if ($groups === null || $groups === []) {
            return $this->result([]);
        }
        if (! is_array($groups) || ! array_is_list($groups)) {
            return $this->result([['path' => 'conversion_groups', 'message' => '換算組必須是依序排列的陣列']]);
        }
        if (count($groups) > self::MAX_GROUPS) {
            $errors[] = ['path' => 'conversion_groups', 'message' => '換算組最多只能設定 10 組'];
        }

        $items = $this->itemFields($fields);
        $used = [];
        $controlled = [];

        foreach ($groups as $index => $group) {
            $path = "conversion_groups.{$index}";
            if (! is_array($group) || array_is_list($group)) {
                $errors[] = ['path' => $path, 'message' => '換算組必須是物件'];

                continue;
            }
            $this->onlyKeys($group, ['kind', 'table_key', 'base', 'price', 'rate', 'guard'], $path, $errors);

            if (($group['kind'] ?? null) !== self::KIND) {
                $errors[] = ['path' => $path.'.kind', 'message' => '目前只支援單價與折數的換算'];
            }

            $tableKey = $group['table_key'] ?? null;
            if (! is_string($tableKey) || preg_match('/^[a-z][a-z0-9_]{0,29}$/', $tableKey) !== 1) {
                $errors[] = ['path' => $path.'.table_key', 'message' => '明細表代碼格式不正確'];

                continue;
            }

            $roleFields = [];
            foreach (self::ROLES as $role) {
                $key = $group[$role] ?? null;
                $field = is_string($key) ? ($items[$tableKey][$key] ?? null) : null;
                if ($field === null) {
                    $errors[] = ['path' => $path.'.'.$role, 'message' => '必須是同一明細表中未封存的欄位'];

                    continue;
                }
                if (! in_array($field['type'] ?? null, self::VALUE_TYPES, true)) {
                    $errors[] = ['path' => $path.'.'.$role, 'message' => '換算只能用數字或金額欄位'];
                }
                $roleFields[$role] = $key;
            }

            if (count(array_unique($roleFields)) !== count($roleFields)) {
                $errors[] = ['path' => $path, 'message' => '單價、折數與建議價必須是三個不同的欄位'];
            }

            foreach ($roleFields as $key) {
                $id = $tableKey.':'.$key;
                if (isset($used[$id])) {
                    $errors[] = ['path' => $path, 'message' => "欄位 {$key} 已屬於另一組換算"];
                }
                $used[$id] = true;
                $controlled[$tableKey][$key] = true;
            }

            $guard = $group['guard'] ?? null;
            if ($guard !== null) {
                if (! is_array($guard) || array_is_list($guard)) {
                    $errors[] = ['path' => $path.'.guard', 'message' => '促銷判定必須是物件或留空'];
                } else {
                    $this->onlyKeys($guard, ['field'], $path.'.guard', $errors);
                    if (! is_string($guard['field'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,49}$/', $guard['field']) !== 1) {
                        $errors[] = ['path' => $path.'.guard.field', 'message' => '促銷欄位代碼格式不正確'];
                    }
                }
            }
        }

        // 一般事件不得改寫換算組控管的欄位
        foreach ($events as $eventIndex => $event) {
            $tableKey = $event['trigger']['table_key'] ?? null;
            if (! is_string($tableKey)) {
                continue;
            }
            foreach ($event['actions'] ?? [] as $actionIndex => $action) {
                $target = $action['field'] ?? null;
                if (is_string($target) && isset($controlled[$tableKey][$target])) {
                    $errors[] = [
                        'path' => "events.{$eventIndex}.actions.{$actionIndex}.field",
                        'message' => "欄位 {$target} 由換算組控管，不可由一般事件設定",
                    ];
                }
            }
        }

        return $this->result($errors);
    }

    /** @return array<string, array<string, array<string, mixed>>> 明細表代碼 => 欄位代碼 => 欄位 */
    private function itemFields(array $fields): array
    {
        $index = [];
        foreach ($fields as $field) {
            if (! is_array($field) || ($field['section'] ?? null) !== 'item'
                || ($field['archived'] ?? false) !== false || ! is_string($field['key'] ?? null)
                || ! is_string($field['table_key'] ?? null)) {
                continue;
            }
            $index[$field['table_key']][$field['key']] = $field;
        }

        return $index;
    }

    private function onlyKeys(array $value, array $allowed, string $path, array &$errors): void
    {
        foreach (array_diff(array_keys($value), $allowed) as $key) {
            $errors[] = ['path' => $path.'.'.$key, 'message' => '不支援的換算組屬性'];
        }
    }

    private function result(array $errors): array
    {
        return ['valid' => $errors === [], 'errors' => $errors];
    }
}
