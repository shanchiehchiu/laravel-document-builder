<?php

declare(strict_types=1);

namespace DocumentBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocSequence extends Model
{
    protected $fillable = ['doc_type_id', 'period', 'last_no'];

    protected $casts = [
        'last_no' => 'integer',
    ];

    public function docType(): BelongsTo
    {
        return $this->belongsTo(DocType::class);
    }
}
