<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Controllers;

use DocumentBuilder\Models\DocType;
use DocumentBuilder\Services\DocTypeService;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocTypeController extends Controller
{
    private const ATTRIBUTES = ['code' => '單據代碼', 'name' => '單據名稱', 'seq' => '排序'];

    public function __construct(private readonly DocTypeService $service) {}

    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::eloquent(DocType::query()->withCount(['fields', 'records']))->make(true);
        }

        return view('document-builder::types.index');
    }

    public function create()
    {
        return view('document-builder::types.create');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'regex:/^[a-z][a-z0-9_]{1,29}$/', Rule::unique('doc_types', 'code')],
            'name' => ['required', 'string', 'max:50'],
            'seq' => ['nullable', 'integer', 'min:0'],
        ], ['code.regex' => '單據代碼只能用小寫英文、數字、底線，開頭須為英文，長度 2～30'], self::ATTRIBUTES);

        $type = $this->service->create($data);

        return response()->json(['message' => __('create').__('success'), 'id' => $type->id]);
    }

    /** 單據設定已併入設計器（標題列元件與整張單據的設定） */
    public function edit(DocType $type): RedirectResponse
    {
        return redirect()->route(document_builder_route_name('document_builder.designer.edit'), $type);
    }

    public function destroy(DocType $type): JsonResponse
    {
        $this->service->delete($type);

        return response()->json(['message' => __('delete').__('success')]);
    }
}
