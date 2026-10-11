<?php

declare(strict_types=1);

namespace DocumentBuilder\Models;

use DocumentBuilder\DocumentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocRecord extends Model
{
    use SoftDeletes;

    protected $fillable = ['doc_type_id', 'doc_date', 'no', 'status_id', 'data', 'created_by', 'updated_by', 'form_revision_id'];

    protected $casts = [
        'doc_date' => 'date:Y-m-d',
        'status_id' => 'integer',
        'form_revision_id' => 'integer',
        'data' => 'array',
    ];

    public function docType(): BelongsTo
    {
        return $this->belongsTo(DocType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(DocumentBuilder::userModel(), 'created_by');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(DocStatus::class, 'status_id');
    }

    public function formRevision(): BelongsTo
    {
        return $this->belongsTo(DocTypeRevision::class, 'form_revision_id');
    }

    /** 所有明細表的明細列（依明細表、列序） */
    public function items(): HasMany
    {
        return $this->hasMany(DocRecordItem::class)->orderBy('table_key')->orderBy('seq')->orderBy('id');
    }

    /** 取自訂欄位的存放值 */
    public function value(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /** 中文不轉成 \uXXXX，列表的關鍵字搜尋才能直接用 LIKE 比對 */
    protected function asJson($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
