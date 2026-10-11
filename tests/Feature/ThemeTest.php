<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Support\Theme;
use DocumentBuilder\Tests\TestCase;

/** 自訂按鈕主色：只存一個 hex，其餘狀態與文字色自動算出 */
class ThemeTest extends TestCase
{
    public function test_沒有設定或設成預設色時_不注入任何覆寫(): void
    {
        $this->assertSame('', Theme::style());

        config(['document_builder.theme.primary' => '#0284C7']);
        $this->assertSame('', Theme::css(), '和預設色相同時沿用 theme.css，不重複輸出');
    }

    public function test_格式不對的色碼退回預設色(): void
    {
        foreach (['red', '#abc', '#zzzzzz', '  #059669', 123] as $bad) {
            config(['document_builder.theme.primary' => $bad]);
            $this->assertSame(Theme::DEFAULT_PRIMARY, Theme::primary(), var_export($bad, true));
        }
    }

    public function test_深色主色配白字_淺色主色配深字(): void
    {
        config(['document_builder.theme.primary' => '#2563eb']);
        $this->assertStringContainsString('--db-primary-text: #ffffff;', Theme::css());

        config(['document_builder.theme.primary' => '#fde047']);
        $this->assertStringContainsString('--db-primary-text: #1f2937;', Theme::css());
    }

    public function test_hover_色比主色深_並輸出_rgb_給聚焦光暈(): void
    {
        config(['document_builder.theme.primary' => '#059669']);
        $css = Theme::css();

        $this->assertStringContainsString('--db-primary: #059669;', $css);
        $this->assertStringContainsString('--db-primary-rgb: 5, 150, 105;', $css);
        $this->assertStringContainsString('--db-primary-dark: #04845c;', $css);
        $this->assertStringContainsString('<style>', Theme::style());
    }

    public function test_自帶版型的頁面與設計器畫布都帶上主色(): void
    {
        config(['document_builder.theme.primary' => '#059669']);
        $admin = $this->makeAdmin();
        $this->makeType('leave', [['key' => 'reason', 'label' => '事由', 'type' => 'text']]);

        $this->actingAs($admin)->get(route('document_builder.home'))
            ->assertOk()->assertSee('--db-primary: #059669;', false);

        $type = \DocumentBuilder\Models\DocType::query()->where('code', 'leave')->firstOrFail();
        $config = $this->actingAs($admin)->get(route('document_builder.designer.edit', $type))->assertOk()->viewData('config');
        $this->assertStringContainsString('--db-primary: #059669;', $config['themeCss']);
    }
}
