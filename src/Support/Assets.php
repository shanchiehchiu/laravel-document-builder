<?php

declare(strict_types=1);

namespace DocumentBuilder\Support;

/** 前端資產網址：套件自己的資產（resources/dist）與宿主在設定裡指定的外部資產 */
final class Assets
{
    /**
     * 套件資產的網址，附上檔案修改時間當版本，更新後瀏覽器不會拿到舊的快取。
     * 宿主有把資產發布（或連結）到 public 時用靜態檔；沒有時由套件路由直接提供，不必發布。
     */
    public static function url(string $path): string
    {
        $path = ltrim($path, '/');
        $relative = trim((string) config('document_builder.assets.url'), '/').'/'.$path;
        $published = public_path($relative);
        if (is_file($published)) {
            return asset($relative).'?v='.filemtime($published);
        }

        $source = __DIR__.'/../../resources/dist/'.$path;

        return route(document_builder_route_name('document_builder.asset'), ['path' => $path])
            .(is_file($source) ? '?v='.filemtime($source) : '');
    }

    /** 設定裡的資產：完整網址原樣使用，相對路徑視為 public 底下的檔案；沒設定時回傳 null */
    public static function external(?string $pathOrUrl): ?string
    {
        if ($pathOrUrl === null || $pathOrUrl === '') {
            return null;
        }

        return preg_match('#^(https?:)?//#', $pathOrUrl) ? $pathOrUrl : asset($pathOrUrl);
    }

    /**
     * 設計器畫布要載入的樣式：填單畫面的版面樣式（讓畫布長得和填單畫面一樣）＋套件的畫布樣式。
     * 沒設定 canvas_styles 時用套件自帶的 Bootstrap，和自帶版型的填單畫面一致。
     */
    public static function canvasStyles(): array
    {
        $configured = config('document_builder.assets.canvas_styles');
        $host = $configured === null
            ? [self::url('vendor/bootstrap-5.3.3/bootstrap.min.css')]
            : array_values(array_filter(array_map([self::class, 'external'], (array) $configured)));

        return [...$host, self::url('designer-canvas.css')];
    }

    /** DataTables 的語系檔：有設定就用設定的，沒設定時依 app 語系用套件附的繁中或英文 */
    public static function datatablesLanguage(): string
    {
        $configured = self::external(config('document_builder.assets.datatables_language'));
        if ($configured !== null) {
            return $configured;
        }

        $file = str_starts_with((string) app()->getLocale(), 'zh') ? 'zh-Hant.json' : 'en.json';

        return self::url('vendor/datatables-1.13.8/i18n/'.$file);
    }
}
