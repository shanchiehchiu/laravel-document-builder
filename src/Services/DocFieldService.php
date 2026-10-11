<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 欄位定義的新增、修改、封存（排序與取消封存由表單設計器存檔時處理，見 FormLayoutService）。
 * 修改規則：key 建立後不可改；這種單據已有資料時不可改型別、主檔欄位不可改來源。
 */
class DocFieldService
{
    public const ATTRIBUTES = [
        'key' => '欄位代碼', 'label' => '欄位名稱', 'type' => '型別', 'width' => '欄寬',
        'choices' => '固定選項', 'decimals' => '小數位數', 'source' => '主檔來源', 'sum' => '顯示合計',
    ];

    public const MESSAGES = ['key.regex' => '欄位代碼只能用小寫英文、數字、底線，開頭須為英文'];

    /** 單一欄位定義的驗證規則（表單設計器逐欄套用） */
    public static function rules(): array
    {
        return [
            'key' => ['nullable', 'regex:/^[a-z][a-z0-9_]{0,49}$/'],
            'label' => ['required', 'string', 'max:50'],
            'type' => ['required', Rule::in(array_keys(config('document_builder.field_types')))],
            'required' => ['nullable', 'boolean'],
            'width' => ['required', Rule::in(array_keys(config('document_builder.widths')))],
            'show_in_list' => ['nullable', 'boolean'],
            'choices' => ['nullable', 'string', 'required_if:type,select'],
            'decimals' => ['nullable', 'integer', 'min:0', 'max:6'],
            'source' => ['nullable', 'required_if:type,master', Rule::in(array_keys(config('document_builder.masters')))],
            'sum' => ['nullable', 'boolean'],
        ];
    }

    public function create(DocType $type, array $data): DocField
    {
        $section = $data['section'];
        $key = ($data['key'] ?? null) ?: $this->generateKey($type, $section);

        if ($type->fields()->where('section', $section)->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['key' => "欄位代碼 {$key} 已存在"]);
        }

        return $type->fields()->create($this->attributes($data) + [
            'section' => $section,
            'table_key' => $section === DocField::SECTION_ITEM ? $data['table_key'] : null,
            'key' => $key,
            'type' => $data['type'],
            'options' => $this->options($data['type'], $data, $section),
            'seq' => (int) $type->fields()->where('section', $section)->max('seq') + 1,
        ]);
    }

    public function update(DocField $field, array $data): DocField
    {
        $options = $this->options($data['type'], $data, $field->section);

        if ($field->docType->records()->withTrashed()->exists()) {
            if ($data['type'] !== $field->type) {
                throw ValidationException::withMessages(['type' => '這種單據已經有資料，欄位不能改型別']);
            }
            if ($field->type === 'master' && ($options['source'] ?? null) !== $field->masterSource()) {
                throw ValidationException::withMessages(['source' => '這種單據已經有資料，主檔欄位不能改來源']);
            }
        }

        $field->update($this->attributes($data) + ['type' => $data['type'], 'options' => $options]);

        return $field;
    }

    public function archive(DocField $field): void
    {
        $field->update(['archived_at' => now()]);
    }

    private function attributes(array $data): array
    {
        return [
            'label' => $data['label'],
            'required' => (bool) ($data['required'] ?? false),
            'width' => (int) $data['width'],
            'show_in_list' => (bool) ($data['show_in_list'] ?? false),
        ];
    }

    /** 依型別只保留用得到的設定；合計只有明細表的數字欄有 */
    private function options(string $type, array $data, string $section): ?array
    {
        return match ($type) {
            'select' => ['choices' => $this->parseChoices((string) ($data['choices'] ?? ''))],
            'number' => ['decimals' => (int) ($data['decimals'] ?? 0)]
                + ($section === DocField::SECTION_ITEM ? ['sum' => (bool) ($data['sum'] ?? false)] : []),
            'master' => ['source' => $data['source']],
            default => null,
        };
    }

    /** 一行一個選項，去頭尾空白、去空行、去重複 */
    private function parseChoices(string $text): array
    {
        $choices = collect(preg_split('/\R/u', $text))
            ->map(fn (string $choice): string => trim($choice))
            ->filter(fn (string $choice): bool => $choice !== '')
            ->unique()
            ->values()
            ->all();

        if ($choices === []) {
            throw ValidationException::withMessages(['choices' => '固定選項至少要有一個選項']);
        }

        return $choices;
    }

    private function generateKey(DocType $type, string $section): string
    {
        $n = $type->fields()->where('section', $section)->count() + 1;
        while ($type->fields()->where('section', $section)->where('key', "field_{$n}")->exists()) {
            $n++;
        }

        return "field_{$n}";
    }
}
