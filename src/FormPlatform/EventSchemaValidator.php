<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

/** 驗證不含程式碼的欄位變更事件，並確保依賴關係沒有循環。 */
class EventSchemaValidator
{
    private const MAX_EVENTS = 20;

    private const MAX_ACTIONS_PER_EVENT = 5;

    private const MAX_TOTAL_ACTIONS = 50;

    /** @return array{valid: bool, errors: array<int, array{path: string, message: string}>} */
    public function validate(mixed $events, mixed $fields): array
    {
        $errors = [];
        if (! is_array($events) || ! array_is_list($events)) {
            return $this->result([['path' => 'events', 'message' => '事件必須是依序排列的陣列']]);
        }
        if (count($events) > self::MAX_EVENTS) {
            $errors[] = ['path' => 'events', 'message' => '事件最多只能設定 20 個'];
        }

        $fieldIndex = $this->fieldIndex(is_array($fields) ? $fields : []);
        $graph = [];
        $actionCount = 0;

        foreach ($events as $eventIndex => $event) {
            $path = "events.{$eventIndex}";
            if (! is_array($event) || array_is_list($event)) {
                $errors[] = ['path' => $path, 'message' => '事件必須是物件'];

                continue;
            }
            $this->onlyKeys($event, ['trigger', 'condition', 'actions'], $path, $errors);

            $trigger = $event['trigger'] ?? null;
            $sourceKey = null;
            $sourceField = null;
            if (! is_array($trigger) || array_is_list($trigger)) {
                $errors[] = ['path' => $path.'.trigger', 'message' => '事件觸發條件必須是物件'];
            } else {
                $this->onlyKeys($trigger, ['type', 'field'], $path.'.trigger', $errors);
                if (($trigger['type'] ?? null) !== 'field.changed') {
                    $errors[] = ['path' => $path.'.trigger.type', 'message' => '目前只支援欄位變更事件'];
                }
                $sourceKey = $trigger['field'] ?? null;
                $sourceField = is_string($sourceKey) ? ($fieldIndex[$sourceKey] ?? null) : null;
                if (! is_string($sourceKey) || $sourceField === null) {
                    $errors[] = ['path' => $path.'.trigger.field', 'message' => '觸發欄位必須是未封存的表頭欄位'];
                    $sourceKey = null;
                }
            }

            if (array_key_exists('condition', $event)) {
                $this->validateCondition($event['condition'], $sourceField, $path.'.condition', $errors);
            }

            $actions = $event['actions'] ?? null;
            if (! is_array($actions) || ! array_is_list($actions) || $actions === []) {
                $errors[] = ['path' => $path.'.actions', 'message' => '事件必須至少有一個動作'];

                continue;
            }
            if (count($actions) > self::MAX_ACTIONS_PER_EVENT) {
                $errors[] = ['path' => $path.'.actions', 'message' => '單一事件最多只能設定 5 個動作'];
            }

            foreach ($actions as $actionIndex => $action) {
                $actionCount++;
                $actionPath = $path.'.actions.'.$actionIndex;
                if (! is_array($action) || array_is_list($action)) {
                    $errors[] = ['path' => $actionPath, 'message' => '事件動作必須是物件'];

                    continue;
                }
                $this->onlyKeys($action, ['type', 'field', 'value'], $actionPath, $errors);
                if (($action['type'] ?? null) !== 'set_value') {
                    $errors[] = ['path' => $actionPath.'.type', 'message' => '目前只支援設定固定欄位值'];
                }

                $targetKey = $action['field'] ?? null;
                $targetField = is_string($targetKey) ? ($fieldIndex[$targetKey] ?? null) : null;
                if (! is_string($targetKey) || $targetField === null) {
                    $errors[] = ['path' => $actionPath.'.field', 'message' => '目標必須是未封存的表頭欄位'];
                } elseif ($sourceKey !== null) {
                    $graph[$sourceKey][] = $targetKey;
                    if ($sourceKey === $targetKey) {
                        $errors[] = ['path' => $actionPath.'.field', 'message' => '事件不得設定自己的觸發欄位'];
                    }
                }

                $actionValue = $action['value'] ?? null;
                $emptyRequiredValue = ($targetField['required'] ?? false) === true
                    && ($actionValue === null || $actionValue === '');
                if ($targetField !== null && ($emptyRequiredValue || ! $this->validValue($targetField, $actionValue))) {
                    $errors[] = ['path' => $actionPath.'.value', 'message' => '動作值不符合目標欄位型別或允許選項'];
                } elseif (! array_key_exists('value', $action)) {
                    $errors[] = ['path' => $actionPath.'.value', 'message' => '設定值不得省略'];
                }
            }
        }

        if ($actionCount > self::MAX_TOTAL_ACTIONS) {
            $errors[] = ['path' => 'events', 'message' => '事件總動作數不得超過 50 個'];
        }
        if ($this->hasCycle($graph)) {
            $errors[] = ['path' => 'events', 'message' => '欄位變更事件不得形成循環依賴'];
        }

        return $this->result($errors);
    }

    private function validateCondition(mixed $condition, ?array $sourceField, string $path, array &$errors): void
    {
        if (! is_array($condition) || array_is_list($condition)) {
            $errors[] = ['path' => $path, 'message' => '事件條件必須是物件'];

            return;
        }

        $this->onlyKeys($condition, ['operator', 'value'], $path, $errors);
        $operator = $condition['operator'] ?? null;
        if (! in_array($operator, ['equals', 'not_equals', 'is_empty'], true)) {
            $errors[] = ['path' => $path.'.operator', 'message' => '條件只能使用 equals、not_equals 或 is_empty'];

            return;
        }
        if ($operator === 'is_empty') {
            if (array_key_exists('value', $condition)) {
                $errors[] = ['path' => $path.'.value', 'message' => 'is_empty 條件不得提供比較值'];
            }

            return;
        }
        if (! array_key_exists('value', $condition)) {
            $errors[] = ['path' => $path.'.value', 'message' => '比較條件必須提供值'];

            return;
        }
        if ($sourceField === null || ! $this->validValue($sourceField, $condition['value'])) {
            $errors[] = ['path' => $path.'.value', 'message' => '比較值不符合觸發欄位型別或允許選項'];
        }
    }

    private function fieldIndex(array $fields): array
    {
        $index = [];
        foreach ($fields as $field) {
            if (! is_array($field) || ($field['section'] ?? null) !== 'header'
                || ($field['archived'] ?? false) !== false || ! is_string($field['key'] ?? null)) {
                continue;
            }
            $index[$field['key']] = $field;
        }

        return $index;
    }

    private function validValue(array $field, mixed $value): bool
    {
        return match ($field['type'] ?? null) {
            'text' => is_string($value) && mb_strlen($value) <= 255,
            'textarea' => is_string($value) && mb_strlen($value) <= 2000,
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'date' => $this->validDate($value),
            'checkbox' => is_bool($value),
            'select' => is_string($value)
                && is_array($field['options']['choices'] ?? null)
                && in_array($value, $field['options']['choices'], true),
            default => false,
        };
    }

    private function validDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function hasCycle(array $graph): bool
    {
        $visiting = [];
        $visited = [];
        $walk = function (string $node) use (&$walk, &$visiting, &$visited, $graph): bool {
            if (isset($visiting[$node])) {
                return true;
            }
            if (isset($visited[$node])) {
                return false;
            }
            $visiting[$node] = true;
            foreach ($graph[$node] ?? [] as $next) {
                if ($walk($next)) {
                    return true;
                }
            }
            unset($visiting[$node]);
            $visited[$node] = true;

            return false;
        };

        foreach (array_keys($graph) as $node) {
            if ($walk($node)) {
                return true;
            }
        }

        return false;
    }

    private function onlyKeys(array $value, array $allowed, string $path, array &$errors): void
    {
        foreach (array_diff(array_keys($value), $allowed) as $key) {
            $errors[] = ['path' => $path.'.'.$key, 'message' => '不支援的事件屬性'];
        }
    }

    private function result(array $errors): array
    {
        return ['valid' => $errors === [], 'errors' => $errors];
    }
}
