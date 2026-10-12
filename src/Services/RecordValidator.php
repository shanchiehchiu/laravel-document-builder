<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocRecord;
use DocumentBuilder\Models\DocType;
use Closure;
use Illuminate\Validation\Rule;

/**
 * 依欄位定義動態組出驗證規則；前端檢查只是輔助，伺服器端是唯一依據
 */
class RecordValidator
{
    /** 每個明細表最多幾列 */
    public const MAX_ITEMS = 500;

    public function __construct(private readonly MasterSourceRegistry $masters) {}

    /**
     * @param  DocRecord|null  $record  修改時傳入，主檔欄位維持原值時不重新檢查存在與否
     * @return array{0: array, 1: array} [規則, 欄位顯示名稱]
     */
    public function rules(DocType $type, ?DocRecord $record = null): array
    {
        $rules = ['doc_date' => ['required', 'date_format:Y-m-d']];
        $attributes = ['doc_date' => '單據日期'];

        foreach ($type->activeFields(DocField::SECTION_HEADER) as $field) {
            $rules["data.{$field->key}"] = $this->fieldRules($field, $record?->value($field->key));
            $attributes["data.{$field->key}"] = $field->label;
        }

        // 明細表：必填以「每一列」為單位檢查；:position 會換成第幾列（從 1 起算）
        foreach ($type->tables() as $tableKey => $title) {
            $rules["items.{$tableKey}"] = ['nullable', 'array', 'max:'.self::MAX_ITEMS];
            $rules["items.{$tableKey}.*._id"] = ['nullable', 'integer'];
            $existing = $record?->items->where('table_key', $tableKey);

            foreach ($type->tableFields($tableKey) as $field) {
                $current = $field->type === 'master' && $existing ? $existing->map->value($field->key)->filter()->values()->all() : null;
                $rules["items.{$tableKey}.*.{$field->key}"] = $this->fieldRules($field, $current);
                $attributes["items.{$tableKey}.*.{$field->key}"] = "{$title}第 :position 列的{$field->label}";
            }
        }

        return [$rules, $attributes];
    }

    /**
     * 去掉沒填任何值的明細列（勾選欄位沒勾送出的 0 也算沒填），並重新編號；
     * 版面上沒有的明細表一律丟掉，不讓請求寫進已移除的明細表。
     */
    public function cleanItems(DocType $type, mixed $items): array
    {
        $items = is_array($items) ? $items : [];
        $clean = [];

        foreach ($type->tables() as $tableKey => $title) {
            $keys = $type->tableFields($tableKey)->pluck('key')->all();
            $clean[$tableKey] = collect(is_array($items[$tableKey] ?? null) ? $items[$tableKey] : [])
                ->filter(fn (mixed $row): bool => is_array($row) && collect($keys)
                    ->contains(fn (string $key): bool => ! in_array($row[$key] ?? null, [null, '', '0'], true)))
                ->values()
                ->all();
        }

        return $clean;
    }

    /** @param  mixed  $current  目前存放值（修改時）；明細表的主檔欄位傳入原本各列的值清單 */
    public function fieldRules(DocField $field, mixed $current = null): array
    {
        // 勾選欄位沒有「沒填」的狀態，必填對它沒有意義
        $base = $field->required && $field->type !== 'checkbox' ? ['required'] : ['nullable'];

        return array_merge($base, match ($field->type) {
            'text' => ['string', 'max:255'],
            'textarea' => ['string', 'max:5000'],
            'number', 'amount' => ['numeric'],
            'date' => ['date_format:Y-m-d'],
            'checkbox' => ['boolean'],
            'select', 'radio' => [Rule::in($field->choices())],
            // 主檔 id 依來源而定：整數主鍵或 UUID／ULID 字串
            'master' => [...$this->masterKeyRules($field), $this->masterRule($field, $current)],
            default => [],
        });
    }

    /**
     * 主檔 id 的型別規則。來源目前不可用（例如在來源庫被停用）時不檢查型別，
     * 交給 masterRule：沿用舊單據原本的值可以存檔，選新的值會回報「選擇的資料不存在」。
     */
    private function masterKeyRules(DocField $field): array
    {
        $source = (string) $field->masterSource();
        if (! $this->masters->has($source)) {
            return [];
        }

        return $this->masters->keyType($source) === 'integer' ? ['integer'] : ['string', 'max:64'];
    }

    private function masterRule(DocField $field, mixed $current): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field, $current): void {
            // 沿用原本選的資料：即使主檔已停用或刪除也允許存檔（明細表傳入的是這張單據原本各列的值）
            $kept = is_array($current) && array_key_exists('id', $current) ? [$current] : (array) $current;
            if (collect($kept)->contains(fn (mixed $old): bool => is_array($old) && (string) ($old['id'] ?? '') === (string) $value)) {
                return;
            }
            if (! $this->masters->has((string) $field->masterSource())
                || $this->masters->find((string) $field->masterSource(), $value) === null) {
                $fail("{$field->label}選擇的資料不存在");
            }
        };
    }
}
