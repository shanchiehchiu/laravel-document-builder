<?php

declare(strict_types=1);

namespace DocumentBuilder\Models;

use Illuminate\Database\Eloquent\Model;

/** 來源庫裡的一個主檔來源（管理員在後台設定） */
class DocSource extends Model
{
    protected $table = 'doc_sources';

    protected $fillable = ['code', 'label', 'driver', 'settings', 'status'];

    protected $casts = [
        'settings' => 'array',
        'status' => 'boolean',
    ];
}
