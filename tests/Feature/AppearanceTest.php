<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Models\DocSetting;
use DocumentBuilder\Support\Theme;
use DocumentBuilder\Tests\TestCase;

/** 外觀設定頁：只有超級管理員能改按鈕主色 */
class AppearanceTest extends TestCase
{
    public function test_超級管理員可以儲存主色並立即生效(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('document_builder.appearance.edit'))->assertOk()->assertSee('外觀設定');

        $this->actingAs($admin)->put(route('document_builder.appearance.update'), ['primary' => '#4F46E5'])
            ->assertRedirect(route('document_builder.appearance.edit'));
        $this->assertSame('#4f46e5', DocSetting::get(Theme::KEY));
        $this->assertSame('#4f46e5', Theme::primary());

        $this->actingAs($admin)->get(route('document_builder.home'))->assertSee('--db-primary: #4f46e5;', false);
    }

    public function test_選回預設色時刪除設定_沿用預設(): void
    {
        $admin = $this->makeAdmin();
        DocSetting::query()->create(['key' => Theme::KEY, 'value' => '#4f46e5']);

        $this->actingAs($admin)->put(route('document_builder.appearance.update'), ['primary' => Theme::DEFAULT_PRIMARY]);

        $this->assertNull(DocSetting::get(Theme::KEY));
        $this->assertSame(Theme::DEFAULT_PRIMARY, Theme::primary());
    }

    public function test_格式不對的色碼會被拒絕(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->put(route('document_builder.appearance.update'), ['primary' => 'red'])
            ->assertSessionHasErrors('primary');
        $this->assertNull(DocSetting::get(Theme::KEY));
    }

    public function test_一般使用者看不到也改不了外觀設定(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get(route('document_builder.appearance.edit'))->assertForbidden();
        $this->actingAs($user)->put(route('document_builder.appearance.update'), ['primary' => '#4f46e5'])->assertForbidden();
        $this->assertNull(DocSetting::get(Theme::KEY));
    }
}
