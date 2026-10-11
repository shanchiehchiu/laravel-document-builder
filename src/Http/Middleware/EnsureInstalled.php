<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Middleware;

use Closure;
use DocumentBuilder\Services\SetupService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 套件的資料表還沒建立或需要更新時，導向初始化頁，不讓頁面直接出錯 */
class EnsureInstalled
{
    public function __construct(private readonly SetupService $setup) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->setup->installed()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => '單據建置尚未完成初始化，請先到初始化頁建立資料表'], 503);
            }

            return redirect()->route(document_builder_route_name('document_builder.setup'));
        }

        return $next($request);
    }
}
