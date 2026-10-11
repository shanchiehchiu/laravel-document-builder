<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Controllers;

use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\Models\DocType;

/** 套件首頁：列出目前使用者能填寫的單據 */
class HomeController extends Controller
{
    public function index()
    {
        $types = DocType::query()
            ->where('status', true)
            ->withCount('records')
            ->orderBy('seq')
            ->orderBy('id')
            ->get()
            ->filter(fn (DocType $type): bool => DocumentBuilder::canViewType($type))
            ->values();

        return view('document-builder::home', [
            'types' => $types,
            'canDesign' => DocumentBuilder::canDesign(),
        ]);
    }
}
