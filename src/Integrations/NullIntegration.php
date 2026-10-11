<?php

declare(strict_types=1);

namespace DocumentBuilder\Integrations;

use DocumentBuilder\Contracts\Integration;
use DocumentBuilder\Models\DocType;
use Illuminate\Contracts\Auth\Authenticatable;

/** 不和宿主選單整合：選單由宿主自己加，能進到路由的登入者都能檢視單據 */
class NullIntegration implements Integration
{
    public function syncMenu(DocType $type): ?int
    {
        return null;
    }

    public function removeMenu(DocType $type): void
    {
    }

    public function flushMenuCache(): void
    {
    }

    public function canViewType(Authenticatable $user, DocType $type): bool
    {
        return true;
    }
}
