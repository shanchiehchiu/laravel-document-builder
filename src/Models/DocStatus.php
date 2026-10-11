<?php

declare(strict_types=1);

namespace DocumentBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocStatus extends Model
{
    protected $fillable = [
        'doc_type_id', 'code', 'name', 'is_default', 'locks_editing', 'color', 'seq',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'locks_editing' => 'boolean',
        'seq' => 'integer',
    ];

    public function docType(): BelongsTo
    {
        return $this->belongsTo(DocType::class);
    }
}
