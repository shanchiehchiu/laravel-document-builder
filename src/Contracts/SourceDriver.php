<?php

declare(strict_types=1);

namespace DocumentBuilder\Contracts;

/**
 * 主檔來源的驅動：決定下拉選單的資料從哪裡來、怎麼搜尋、怎麼依 id 找回一筆。
 * settings 是來源庫裡這個來源的設定（或設定檔 masters 的一筆），格式由各驅動自己定義。
 */
interface SourceDriver
{
    /** 驅動代碼，存在 doc_sources.driver */
    public function key(): string;

    /** 顯示在來源庫畫面的名稱 */
    public function label(): string;

    /**
     * 下拉搜尋，只回傳可選的資料（例如排除停用的）。
     *
     * @return array<int, array{id: int|string, name: string}>
     */
    public function search(array $settings, string $term, int $limit): array;

    /**
     * 依 id 找回一筆；不套用「可選」條件，舊單據引用到已停用的資料仍可存檔。
     *
     * @return array{id: int|string, label: string}|null
     */
    public function find(array $settings, int|string $id): ?array;

    /** id 的型別：integer 或 string（UUID、ULID 主鍵） */
    public function keyType(array $settings): string;

    /**
     * 檢查並整理管理員送出的設定，不合法時丟 ValidationException（錯誤鍵用 settings.xxx）。
     *
     * @return array 存進資料庫的設定
     */
    public function validateSettings(array $settings): array;

    /** 來源庫列表上顯示的設定摘要 */
    public function describe(array $settings): string;
}
