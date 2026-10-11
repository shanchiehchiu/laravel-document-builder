<?php

declare(strict_types=1);

namespace DocumentBuilder;

use DocumentBuilder\Contracts\Integration;
use DocumentBuilder\Models\DocType;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
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

    /** 使用者主鍵的型別：int（自動遞增）、uuid 或 ulid，決定套件資料表裡指向使用者的欄位型別 */
    public static function userKeyType(): string
    {
        $type = (string) config('document_builder.user_key_type', 'int');

        return in_array($type, ['int', 'uuid', 'ulid'], true) ? $type : 'int';
    }

    /** 使用者資料表的主鍵欄位名稱（通常是 id） */
    public static function userKeyName(): string
    {
        $model = self::userModel();

        return (new $model)->getKeyName();
    }

    /** 畫面上顯示使用者名稱用的欄位（預設 name） */
    public static function userNameColumn(): string
    {
        return (string) config('document_builder.user_name_column', 'name');
    }

    /** 預先載入使用者關聯時只取主鍵與名稱欄位，例如 'creator:'.DocumentBuilder::userColumns() */
    public static function userColumns(): string
    {
        return self::userKeyName().','.self::userNameColumn();
    }

    /** 使用者的顯示名稱；名稱欄位空白時退回主鍵，使用者已刪除時回傳 null */
    public static function userName(Model|Authenticatable|null $user): ?string
    {
        if ($user === null) {
            return null;
        }
        $name = data_get($user, self::userNameColumn());
        $key = $user instanceof Model ? $user->getKey() : $user->getAuthIdentifier();

        return $name !== null && $name !== '' ? (string) $name : (string) $key;
    }

    /**
     * migration 用：建立指向使用者的欄位，型別跟著 user_key_type。
     * 要外鍵約束時另外呼叫 userForeign()。
     */
    public static function userColumn(Blueprint $table, string $column): ColumnDefinition
    {
        return match (self::userKeyType()) {
            'uuid' => $table->uuid($column),
            'ulid' => $table->ulid($column),
            default => $table->unsignedBigInteger($column),
        };
    }

    /** migration 用：指向使用者資料表的外鍵，使用者刪除時清成 null */
    public static function userForeign(Blueprint $table, string $column): void
    {
        $table->foreign($column)
            ->references(self::userKeyName())
            ->on((string) config('document_builder.users_table', 'users'))
            ->nullOnDelete();
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
