<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret'];
}
