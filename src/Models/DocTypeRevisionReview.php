<?php

declare(strict_types=1);

namespace DocumentBuilder\Models;

use DocumentBuilder\DocumentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** 不可變的表單審核事件與當時的驗證結果。 */
class DocTypeRevisionReview extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'doc_type_revision_id', 'reviewer_id', 'decision', 'note', 'validation_json', 'reviewed_at',
    ];

    protected $casts = [
        'validation_json' => 'array',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('表單審核紀錄不得原地更新');
        });

        static::deleting(static function (): never {
            throw new LogicException('表單審核紀錄不得刪除');
        });
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(DocTypeRevision::class, 'doc_type_revision_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(DocumentBuilder::userModel(), 'reviewer_id');
    }
}
