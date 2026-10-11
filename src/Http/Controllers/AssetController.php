<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 直接從套件提供前端資產（resources/dist），宿主不必發布檔案到 public。
 * 網址帶檔案修改時間當版本，所以可以讓瀏覽器長期快取。
 */
class AssetController extends BaseController
{
    private const TYPES = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
    ];

    public function show(string $path): BinaryFileResponse
    {
        $root = realpath(__DIR__.'/../../../resources/dist');
        $file = realpath($root.'/'.$path);
        $extension = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));

        // 只允許 dist 目錄裡、已知類型的檔案，擋掉 ../ 之類的路徑
        abort_unless($file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file) && isset(self::TYPES[$extension]), 404);

        return response()->file($file, [
            'Content-Type' => self::TYPES[$extension],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
