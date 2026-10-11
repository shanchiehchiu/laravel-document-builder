<?php

declare(strict_types=1);

namespace DocumentBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 明細表的一列 */
class DocRecordItem extends Model
{
    protected $fillable = ['doc_record_id', 'table_key', 'seq', 'data'];

    protected $casts = [
        'seq' => 'integer',
        'data' => 'array',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(DocRecord::class, 'doc_record_id');
    }

    public function value(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /** 與表頭一致：中文不轉成 \uXXXX */
    protected function asJson($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
