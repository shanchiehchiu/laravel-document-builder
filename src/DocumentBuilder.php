<?php

declare(strict_types=1);

namespace DocumentBuilder;

use DocumentBuilder\Contracts\Integration;
use DocumentBuilder\Models\DocType;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** 宿主整合的共用入口：使用者從哪個 guard 取得、用哪個 model、誰算超級管理員 */
final class DocumentBuilder
{
    /** 超級管理員可以發布、審核、還原版本，並透過 MCP 建立與修改草稿；由宿主定義這個 Gate */
    public const SUPER_ADMIN_ABILITY = 'document-builder.super-admin';

    /** 可以進入單據設定、設計器、版本紀錄與來源庫；宿主沒定義時只有超級管理員可以 */
    public const DESIGN_ABILITY = 'document-builder.design';

    /** 選用：宿主定義後，NullIntegration 以它判斷誰能填寫、檢視某種單據（參數是使用者與 DocType） */
    public const VIEW_TYPE_ABILITY = 'document-builder.view-type';

    public static function guard(): string
    {
        return (string) config('document_builder.guard', 'web');
    }

    /** @return class-string<Model> */
    public static function userModel(): string
    {
        return (string) config('document_builder.user_model', 'App\Models\User');
    }

    /** 目前登入的使用者；只接受設定的 user model，避免別的 guard 的使用者混進來 */
    public static function user(): ?Authenticatable
    {
        $user = auth(self::guard())->user();
        $model = self::userModel();

        return $user instanceof $model ? $user : null;
    }

    public static function isSuperAdmin(?Authenticatable $user = null): bool
    {
        $user ??= self::user();

        return $user !== null && Gate::forUser($user)->allows(self::SUPER_ADMIN_ABILITY);
    }

    public static function canDesign(?Authenticatable $user = null): bool
    {
        $user ??= self::user();

        return $user !== null && Gate::forUser($user)->allows(self::DESIGN_ABILITY);
    }

    /**
     * 能不能填寫、檢視這種單據：超級管理員一律可以，其餘交給宿主整合判斷。
     * 刻意不把 design 算進來：設計權限和各單據的檢視權限是兩回事（宿主可能把 design 交給別的機制把關）。
     */
    public static function canViewType(DocType $type, ?Authenticatable $user = null): bool
    {
        $user ??= self::user();
        if ($user === null) {
            return false;
        }

        return self::isSuperAdmin($user)
            || app(Integration::class)->canViewType($user, $type);
    }
}
