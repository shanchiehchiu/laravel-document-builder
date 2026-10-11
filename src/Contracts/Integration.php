<?php

declare(strict_types=1);

namespace DocumentBuilder\Contracts;

use DocumentBuilder\Models\DocType;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * 宿主後台的整合點：每種單據要不要出現在宿主的側選單、一般使用者能不能檢視某種單據。
 * 套件只附 NullIntegration（不處理選單、所有登入者都能檢視）；要接自家後台，由宿主專案自己實作。
 */
interface Integration
{
    /** 建立或更新這種單據的選單項目，回傳選單項目 id（存在 doc_types.permission_id）；不處理選單時回傳 null */
    public function syncMenu(DocType $type): ?int;

    /** 刪除這種單據的選單項目與相關權限設定 */
    public function removeMenu(DocType $type): void;

    /** 選單有異動後清除宿主的選單快取；在資料庫交易結束後才呼叫，避免其他請求把舊選單寫回快取 */
    public function flushMenuCache(): void;

    /** 非超級管理員能不能檢視這種單據（超級管理員一律可以，由呼叫端先判斷） */
    public function canViewType(Authenticatable $user, DocType $type): bool;
}
