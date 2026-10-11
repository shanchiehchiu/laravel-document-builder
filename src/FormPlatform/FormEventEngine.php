<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use InvalidArgumentException;

/** 以事件陣列順序執行受控欄位值更新，不執行使用者提供的程式碼。 */
class FormEventEngine
{
    private const MAX_ACTIONS = 50;

    /** @return array{values: array, changed_fields: array<int, string>, action_count: int} */
    public function evaluate(array $events, array $values, string $changedField): array
    {
        $queue = [$changedField];
        $changedFields = [];
        $actionCount = 0;

        while ($queue !== []) {
            $triggerField = array_shift($queue);
            foreach ($events as $event) {
                if (($event['trigger']['type'] ?? null) !== 'field.changed'
                    || ($event['trigger']['field'] ?? null) !== $triggerField
                    || ! $this->conditionMatches($event['condition'] ?? null, $values[$triggerField] ?? null)) {
                    continue;
                }

                foreach ($event['actions'] ?? [] as $action) {
                    if (($action['type'] ?? null) !== 'set_value' || ! is_string($action['field'] ?? null)) {
                        continue;
                    }
                    $target = $action['field'];
                    $nextValue = $action['value'] ?? null;
                    if (($values[$target] ?? null) === $nextValue) {
                        continue;
                    }

                    $values[$target] = $nextValue;
                    $actionCount++;
                    if ($actionCount > self::MAX_ACTIONS) {
                        throw new InvalidArgumentException('事件執行超過 50 個變更，已停止後續處理');
                    }
                    if (! in_array($target, $changedFields, true)) {
                        $changedFields[] = $target;
                    }
                    $queue[] = $target;
                }
            }
        }

        return [
            'values' => $values,
            'changed_fields' => $changedFields,
            'action_count' => $actionCount,
        ];
    }

    private function conditionMatches(?array $condition, mixed $value): bool
    {
        if ($condition === null) {
            return true;
        }

        return match ($condition['operator'] ?? null) {
            'equals' => $value === ($condition['value'] ?? null),
            'not_equals' => $value !== ($condition['value'] ?? null),
            'is_empty' => $value === null || $value === '',
            default => false,
        };
    }
}
