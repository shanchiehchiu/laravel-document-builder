<?php

declare(strict_types=1);

namespace DocumentBuilder\Models;

use DocumentBuilder\DocumentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** 追加式表單 Schema 修訂；已寫入的修訂資料不可原地更新。 */
class DocTypeRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'doc_type_id', 'revision', 'kind', 'schema_version', 'schema_json', 'parent_revision_id',
        'change_summary', 'created_by', 'published_by', 'published_at', 'created_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'schema_version' => 'integer',
        'schema_json' => 'array',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('表單修訂不可原地更新，請建立新的草稿修訂');
        });
    }

    public function docType(): BelongsTo
    {
        return $this->belongsTo(DocType::class);
    }

    public function parentRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_revision_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(DocumentBuilder::userModel(), 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(DocumentBuilder::userModel(), 'published_by');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(DocTypeRevisionReview::class, 'doc_type_revision_id')->orderByDesc('id');
    }

    public function latestReview(): HasOne
    {
        return $this->hasOne(DocTypeRevisionReview::class, 'doc_type_revision_id')->latestOfMany();
    }
}
