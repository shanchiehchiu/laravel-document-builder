<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/** 主鍵是 UUID 的使用者 */
class UuidUser extends User
{
    use HasUuids;
}
