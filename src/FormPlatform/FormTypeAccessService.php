<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use DocumentBuilder\Contracts\Integration;
use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\Models\DocType;
use Mcp\Exception\ToolCallException;

/** MCP 工具共用的單據檢視權限檢查。 */
class FormTypeAccessService
{
    public function __construct(private readonly Integration $integration) {}

    /** 找出可供目前使用者檢視的啟用單據，拒絕時不透露單據是否存在。 */
    public function findViewable(string $code): DocType
    {
        $user = DocumentBuilder::user();
        $type = DocType::query()->where('code', $code)->first();

        if (! $user || ! $type || ! $type->status) {
            throw new ToolCallException('無法存取指定單據');
        }

        if (DocumentBuilder::isSuperAdmin($user) || $this->integration->canViewType($user, $type)) {
            return $type;
        }

        throw new ToolCallException('無法存取指定單據');
    }
}
