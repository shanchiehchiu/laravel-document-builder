<?php

declare(strict_types=1);

namespace DocumentBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class DocType extends Model
{
    /** 選單列 link 的前綴，後面接單據代碼 */
    public const MENU_LINK_PREFIX = 'doc_records.';

    /** 可在單據設定開關的基礎按鈕；檢視與回列表固定顯示，不在此列 */
    public const BUTTONS = [
        'create' => '新增',
        'edit' => '編輯',
        'delete' => '刪除',
        'navigation' => '上一筆／下一筆',
    ];

    protected $fillable = [
        'code', 'name', 'status', 'seq', 'permission_id', 'form_layout', 'buttons',
        'no_prefix', 'no_date_format', 'no_seq_digits', 'event_schema', 'working_revision_id', 'published_revision_id',
    ];

    protected $casts = [
        'status' => 'boolean',
        'seq' => 'integer',
        'working_revision_id' => 'integer',
        'published_revision_id' => 'integer',
        'form_layout' => 'array',
        'buttons' => 'array',
        'event_schema' => 'array',
        'no_seq_digits' => 'integer',
    ];

    public function fields(): HasMany
    {
        return $this->hasMany(DocField::class)->orderBy('seq')->orderBy('id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(DocRecord::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(DocStatus::class)->orderBy('seq')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DocTypeRevision::class)->orderByDesc('revision');
    }

    public function workingRevision(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DocTypeRevision::class, 'working_revision_id');
    }

    public function publishedRevision(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DocTypeRevision::class, 'published_revision_id');
    }

    public function defaultStatus(): HasOne
    {
        return $this->hasOne(DocStatus::class)->where('is_default', true);
    }

    public function sequences(): HasMany
    {
        return $this->hasMany(DocSequence::class);
    }

    /** 某區塊未封存的欄位（依排序） */
    public function activeFields(string $section = DocField::SECTION_HEADER): Collection
    {
        return $this->fields
            ->where('section', $section)
            ->whereNull('archived_at')
            ->values();
    }

    /** 版面上的明細表 [key => 名稱]，依版面由上而下 */
    public function tables(): array
    {
        $tables = [];
        foreach ($this->form_layout['rows'] ?? [] as $row) {
            foreach ($row['cols'] ?? [] as $col) {
                if (($col['item']['kind'] ?? null) === 'table') {
                    $tables[$col['item']['key']] = (string) ($col['item']['title'] ?? '');
                }
            }
        }

        return $tables;
    }

    /** 某個明細表未封存的欄位（依排序） */
    public function tableFields(string $tableKey): Collection
    {
        return $this->activeFields(DocField::SECTION_ITEM)->where('table_key', $tableKey)->values();
    }

    /** 按鈕是否開啟；舊資料沒有設定時一律視為開啟 */
    public function hasButton(string $key): bool
    {
        return (bool) ($this->buttons[$key] ?? true);
    }

    public function menuLink(): string
    {
        return self::MENU_LINK_PREFIX.$this->code;
    }
}
