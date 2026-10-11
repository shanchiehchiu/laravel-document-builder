<?php

declare(strict_types=1);

namespace DocumentBuilder\Models;

use Illuminate\Database\Eloquent\Model;

class DocMcpOperation extends Model
{
    protected $fillable = [
        'admin_user_id',
        'operation',
        'idempotency_key',
        'request_hash',
        'doc_type_id',
        'response_data',
    ];

    protected $casts = [
        'doc_type_id' => 'integer',
        'response_data' => 'array',
    ];
}
