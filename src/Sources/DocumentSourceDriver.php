<?php

declare(strict_types=1);

namespace DocumentBuilder\Sources;

use DocumentBuilder\Contracts\SourceDriver;
use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocRecord;
use DocumentBuilder\Models\DocType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * 單據：拿單據工廠自己建立的單據當來源，例如請假單引用「專案單」。不需要宿主有任何 model。
 * settings：doc_type（單據代碼）、text_field（顯示在單號後面的欄位，選填）。
 * 搜尋比對單號與 text_field；該種單據停用時搜尋不到，但舊單據引用到的仍可存檔。
 */
class DocumentSourceDriver implements SourceDriver
{
    /** 可以當顯示文字的欄位型別（主檔、勾選不適合） */
    private const TEXT_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'radio'];

    public function key(): string
    {
        return 'document';
    }

    public function label(): string
    {
        return '單據';
    }

    public function search(array $settings, string $term, int $limit): array
    {
        $type = $this->type($settings);
        if (! $type || ! $type->status) {
            return [];
        }
        $field = $settings['text_field'] ?? null;

        return DocRecord::query()
            ->where('doc_type_id', $type->id)
            ->when($term !== '', function (Builder $q) use ($term, $field): void {
                $q->where(function (Builder $w) use ($term, $field): void {
                    $w->where('no', 'like', "%{$term}%");
                    if ($field) {
                        $w->orWhere("data->{$field}", 'like', "%{$term}%");
                    }
                });
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (DocRecord $record): array => [
                'id' => $record->id,
                'name' => $this->labelOf($record, $field),
            ] + $this->extrasPayload($settings, $record))
            ->all();
    }

    /**
     * 附隨欄位：settings 的 extras 是「欄位代碼 => 單據資料的欄位代碼」。沒有附隨欄位時不帶 extras 鍵。
     *
     * @return array<string, array<string, mixed>>
     */
    private function extrasPayload(array $settings, DocRecord $record): array
    {
        $extras = collect((array) ($settings['extras'] ?? []))
            ->map(fn (string $column): mixed => $record->data[$column] ?? null)
            ->all();

        return $extras === [] ? [] : ['extras' => $extras];
    }

    public function find(array $settings, int|string $id): ?array
    {
        $type = $this->type($settings);
        $record = $type ? DocRecord::query()->where('doc_type_id', $type->id)->whereKey((int) $id)->first() : null;

        return $record ? ['id' => $record->id, 'label' => $this->labelOf($record, $settings['text_field'] ?? null)] : null;
    }

    public function keyType(array $settings): string
    {
        return 'integer';
    }

    public function validateSettings(array $settings): array
    {
        $type = $this->type($settings);
        if (! $type) {
            throw ValidationException::withMessages(['settings.doc_type' => '請選擇單據']);
        }

        $field = $settings['text_field'] ?? null;
        if ($field !== null && $field !== '' && ! array_key_exists($field, $this->textFields($type))) {
            throw ValidationException::withMessages(['settings.text_field' => '顯示欄位必須是這種單據的表頭欄位']);
        }

        return ['doc_type' => $type->code, 'text_field' => $field ?: null];
    }

    public function describe(array $settings): string
    {
        $type = $this->type($settings);
        $field = $settings['text_field'] ?? null;
        $label = $type && $field ? ($this->textFields($type)[$field] ?? $field) : null;

        return ($type?->name ?? ($settings['doc_type'] ?? '')).'：單號'.($label ? "＋{$label}" : '');
    }

    /** @return array<string, string> 這種單據可以當顯示文字的表頭欄位：代碼 => 名稱 */
    public function textFields(DocType $type): array
    {
        return $type->activeFields(DocField::SECTION_HEADER)
            ->filter(fn (DocField $field): bool => in_array($field->type, self::TEXT_TYPES, true))
            ->mapWithKeys(fn (DocField $field): array => [$field->key => $field->label])
            ->all();
    }

    private function type(array $settings): ?DocType
    {
        $code = $settings['doc_type'] ?? null;

        return is_string($code) && $code !== '' ? DocType::query()->where('code', $code)->first() : null;
    }

    private function labelOf(DocRecord $record, ?string $field): string
    {
        $text = $field ? $record->value($field) : null;

        return trim($record->no.' '.(is_scalar($text) ? (string) $text : ''));
    }
}
