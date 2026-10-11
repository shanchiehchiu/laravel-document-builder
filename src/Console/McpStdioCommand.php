<?php

declare(strict_types=1);

namespace DocumentBuilder\Console;

use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\FormPlatform\FormPlatformMcpServer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/** 僅在本機開發環境啟動給 AI 工具（例如 Codex）使用的表單 MCP stdio 程序；以指定的超級管理員身分操作。 */
class McpStdioCommand extends Command
{
    protected $signature = 'form-platform:mcp-stdio {--admin-id= : 用於本機 MCP 操作的超級管理員 ID}';

    protected $description = '以本機 stdio 提供單據建置 MCP';

    public function handle(FormPlatformMcpServer $server): int
    {
        if (! app()->environment('local')) {
            $this->error('單據建置 stdio MCP 只允許在 local 環境啟動');

            return self::FAILURE;
        }

        $model = DocumentBuilder::userModel();
        $admin = $model::query()->find((int) $this->option('admin-id'));

        // 是否啟用等條件由宿主定義在 document-builder.super-admin Gate 裡
        if (! $admin || ! DocumentBuilder::isSuperAdmin($admin)) {
            $this->error('指定的使用者不存在，或不是超級管理員');

            return self::FAILURE;
        }

        Auth::guard(DocumentBuilder::guard())->setUser($admin);

        return $server->runStdio();
    }
}
