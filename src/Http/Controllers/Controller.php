<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/** 套件控制器的基底：只用 Laravel 本身的功能，不依賴宿主的控制器 */
abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
