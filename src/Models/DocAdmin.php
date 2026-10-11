<?php

declare(strict_types=1);

namespace DocumentBuilder\Models;

use DocumentBuilder\DocumentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 在初始化頁設定的超級管理員 */
class DocAdmin extends Model
{
    protected $table = 'doc_admins';

    protected $fillable = ['user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(DocumentBuilder::userModel(), 'user_id');
    }
}
