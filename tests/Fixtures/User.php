<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** 主鍵是自動遞增整數的使用者（Laravel 預設） */
class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['is_admin' => 'boolean'];
}
