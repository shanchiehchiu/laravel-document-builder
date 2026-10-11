<?php

declare(strict_types=1);

namespace DocumentBuilder;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** 宿主整合的共用入口：使用者從哪個 guard 取得、用哪個 model、誰算超級管理員 */
final class DocumentBuilder
{
    /** 超級管理員可以發布、審核、還原版本，並透過 MCP 建立與修改草稿；由宿主定義這個 Gate */
    public const SUPER_ADMIN_ABILITY = 'document-builder.super-admin';

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
}
