<?php

declare(strict_types=1);

namespace DocumentBuilder\Support;

/**
 * 自訂按鈕主色：宿主只存一個 hex，其餘狀態在 PHP 算好，不依賴瀏覽器支援 color-mix()。
 * 文字色依 WCAG 對比度選深字或白字，確保按鈕上的字看得清楚。
 */
final class Theme
{
    public const DEFAULT_PRIMARY = '#0665d0';

    private const DARK_TEXT = '#1f2937';

    /** 目前的主色（格式不對或沒設定時用預設色） */
    public static function primary(): string
    {
        $configured = config('document_builder.theme.primary');

        return self::valid($configured) ? strtolower((string) $configured) : self::DEFAULT_PRIMARY;
    }

    /** 是否和預設色不同；相同時不需要覆寫 */
    public static function custom(): bool
    {
        return self::primary() !== self::DEFAULT_PRIMARY;
    }

    /** 要注入的 CSS 變數（:root 覆寫）；使用預設色時回傳空字串，沿用 theme.css */
    public static function css(): string
    {
        if (! self::custom()) {
            return '';
        }

        $hex = self::primary();
        $rgb = self::rgb($hex);
        $dark = self::mix($rgb, [0, 0, 0], 0.12);
        $text = self::contrast($rgb, self::rgb('#ffffff')) >= self::contrast($rgb, self::rgb(self::DARK_TEXT))
            ? '#ffffff'
            : self::DARK_TEXT;

        return sprintf(
            ':root, .db-app {--db-primary: %s; --db-primary-dark: %s; --db-primary-rgb: %d, %d, %d; --db-primary-text: %s;}',
            $hex, self::hex($dark), $rgb[0], $rgb[1], $rgb[2], $text
        );
    }

    /** 可直接放進 <head> 的 <style>；使用預設色時回傳空字串 */
    public static function style(): string
    {
        $css = self::css();

        return $css === '' ? '' : '<style>'.$css.'</style>';
    }

    public static function valid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...array_map(fn (float $v): int => (int) round($v), $rgb));
    }

    /**
     * @param array{0: int, 1: int, 2: int} $from
     * @param array{0: int, 1: int, 2: int} $to
     * @return array{0: float, 1: float, 2: float}
     */
    private static function mix(array $from, array $to, float $weight): array
    {
        return [
            $from[0] * (1 - $weight) + $to[0] * $weight,
            $from[1] * (1 - $weight) + $to[1] * $weight,
            $from[2] * (1 - $weight) + $to[2] * $weight,
        ];
    }

    /** WCAG 相對亮度 */
    private static function luminance(array $rgb): float
    {
        $c = array_map(function (int $v): float {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /** WCAG 對比度（1 到 21） */
    private static function contrast(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }
}
