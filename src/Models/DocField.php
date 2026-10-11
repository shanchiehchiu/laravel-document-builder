<?php

declare(strict_types=1);

namespace DocumentBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocField extends Model
{
    public const SECTION_HEADER = 'header';

    public const SECTION_ITEM = 'item';

    public const SECTIONS = [self::SECTION_HEADER, self::SECTION_ITEM];

    protected $fillable = [
        'doc_type_id', 'section', 'table_key', 'key', 'label', 'type', 'required',
        'width', 'options', 'show_in_list', 'seq', 'archived_at',
    ];

    protected $casts = [
        'required' => 'boolean',
        'show_in_list' => 'boolean',
        'width' => 'integer',
        'seq' => 'integer',
        'options' => 'array',
        'archived_at' => 'datetime',
    ];

    public function docType(): BelongsTo
    {
        return $this->belongsTo(DocType::class);
    }

    /** 固定選項清單（select 型別） */
    public function choices(): array
    {
        return array_values($this->options['choices'] ?? []);
    }

    /** 小數位數（number 型別） */
    public function decimals(): int
    {
        return (int) ($this->options['decimals'] ?? 0);
    }

    /** 明細表的數字欄是否在表格底部顯示合計 */
    public function sums(): bool
    {
        return $this->type === 'number' && (bool) ($this->options['sum'] ?? false);
    }

    /** 主檔來源代碼（master 型別） */
    public function masterSource(): ?string
    {
        return $this->options['source'] ?? null;
    }

    /** 把存放值轉成畫面顯示文字，列表與檢視共用 */
    public function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($this->type) {
            'checkbox' => $value ? '是' : '否',
            'master' => (string) ($value['label'] ?? ''),
            'number' => number_format((float) $value, $this->decimals()),
            default => (string) $value,
        };
    }
}
