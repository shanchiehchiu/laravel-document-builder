<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Middleware;

use Closure;
use DocumentBuilder\DocumentBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 單據設定、設計器、版本與來源庫只開放給 document-builder.design 的使用者。
 * 用套件設定的 guard 取得使用者，不依賴宿主預設的 guard。
 */
class EnsureCanDesign
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(DocumentBuilder::canDesign(), 403);

        return $next($request);
    }
}
