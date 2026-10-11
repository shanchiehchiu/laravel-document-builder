<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Controllers;

use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\Models\DocSetting;
use DocumentBuilder\Support\Assets;
use DocumentBuilder\Support\Theme;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/** 外觀設定：按鈕主色。只有超級管理員可以改，改了之後所有頁面與設計器畫布立即生效 */
class AppearanceController extends Controller
{
    /** 色票：第一個是預設（c11305-1 的藍） */
    public const PRESETS = [
        '#0284c7' => '預設藍',
        '#2563eb' => '科技藍',
        '#4f46e5' => '靛青',
        '#1e293b' => '商務灰黑',
        '#ea580c' => '活力橙',
    ];

    public function edit(): Response
    {
        $this->ensureSuperAdmin();

        return response()->view('document-builder::appearance', [
            'primary' => Theme::primary(),
            'presets' => self::PRESETS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->ensureSuperAdmin();

        $data = $request->validate([
            'primary' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [], ['primary' => '按鈕主色']);

        $primary = strtolower($data['primary']);
        if ($primary === Theme::DEFAULT_PRIMARY) {
            DocSetting::query()->where('key', Theme::KEY)->delete();
        } else {
            DocSetting::query()->updateOrCreate(['key' => Theme::KEY], ['value' => $primary]);
        }

        return redirect()->route(document_builder_route_name('document_builder.appearance.edit'))
            ->with('status', '已儲存外觀設定');
    }

    private function ensureSuperAdmin(): void
    {
        abort_unless(DocumentBuilder::isSuperAdmin(), 403);
    }
}
