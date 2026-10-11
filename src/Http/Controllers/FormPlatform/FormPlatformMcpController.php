<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Controllers\FormPlatform;

use DocumentBuilder\Http\Controllers\Controller;
use DocumentBuilder\FormPlatform\FormPlatformMcpServer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 以既有後台登入與權限 middleware 保護 MCP 工具端點。 */
class FormPlatformMcpController extends Controller
{
    public function __construct(private readonly FormPlatformMcpServer $server) {}

    public function handle(Request $request): Response
    {
        return $this->server->handle($request);
    }
}
