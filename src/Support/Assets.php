<?php

declare(strict_types=1);

namespace DocumentBuilder\Support;

/** 前端資產網址：套件自己的資產（resources/dist）與宿主在設定裡指定的外部資產 */
final class Assets
{
    /** 套件資產的網址，附上檔案修改時間當版本，更新後瀏覽器不會拿到舊的快取 */
    public static function url(string $path): string
    {
        $relative = trim((string) config('document_builder.assets.url'), '/').'/'.ltrim($path, '/');
        $full = public_path($relative);

        return asset($relative).(is_file($full) ? '?v='.filemtime($full) : '');
    }

    /** 設定裡的資產：完整網址原樣使用，相對路徑視為 public 底下的檔案；沒設定時回傳 null */
    public static function external(?string $pathOrUrl): ?string
    {
        if ($pathOrUrl === null || $pathOrUrl === '') {
            return null;
        }

        return preg_match('#^(https?:)?//#', $pathOrUrl) ? $pathOrUrl : asset($pathOrUrl);
    }

    /** 設計器畫布要載入的樣式：宿主的版面樣式（讓畫布長得和填單畫面一樣）＋套件的畫布樣式 */
    public static function canvasStyles(): array
    {
        $host = array_values(array_filter(array_map(
            [self::class, 'external'],
            (array) config('document_builder.assets.canvas_styles', [])
        )));

        return [...$host, self::url('designer-canvas.css')];
    }
}
