<?php

declare(strict_types=1);

namespace DocumentBuilder\Models;

use Illuminate\Database\Eloquent\Model;

/** 套件的設定（key／value），在外觀設定頁調整 */
class DocSetting extends Model
{
    protected $table = 'doc_settings';

    protected $fillable = ['key', 'value'];

    public static function get(string $key): ?string
    {
        return static::query()->where('key', $key)->value('value');
    }
}
