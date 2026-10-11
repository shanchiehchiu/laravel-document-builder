<?php

declare(strict_types=1);

namespace DocumentBuilder\Integrations;

use DocumentBuilder\Contracts\Integration;
use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\Models\DocType;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * 不和宿主選單整合：選單由宿主自己加。
 * 誰能填寫、檢視某種單據：宿主有定義 document-builder.view-type Gate 就照它，沒定義時登入者都可以。
 */
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
        if (! Gate::has(DocumentBuilder::VIEW_TYPE_ABILITY)) {
            return true;
        }

        return Gate::forUser($user)->allows(DocumentBuilder::VIEW_TYPE_ABILITY, $type);
    }
}
