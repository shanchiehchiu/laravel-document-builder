<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Controllers\FormPlatform;

use DocumentBuilder\Http\Controllers\Controller;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\FormPlatform\FormRevisionService;
use DocumentBuilder\FormPlatform\FormSchemaPreviewService;
use DocumentBuilder\FormPlatform\FormSchemaValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 提供受後台權限保護的唯讀 Schema 匯出與驗證端點。 */
class FormSchemaController extends Controller
{
    public function __construct(
        private readonly FormSchemaValidator $validator,
        private readonly FormSchemaPreviewService $previewService,
        private readonly FormRevisionService $revisions,
    ) {}

    /** 匯出既有單據結構並回傳目前版本的驗證結果。 */
    public function show(DocType $type): JsonResponse
    {
        $schema = $this->revisions->workingSchema($type);

        return response()->json([
            'schema' => $schema,
            'validation' => $this->validator->validate($schema, $type->code),
        ]);
    }

    /** 驗證送入的 Schema，不寫入單據、欄位或版面資料。 */
    public function validateSchema(Request $request, DocType $type): JsonResponse
    {
        $result = $this->validator->validate($request->all(), $type->code);

        return response()->json(['validation' => $result], $result['valid'] ? 200 : 422);
    }

    /** 使用正式表單共用的 Runtime 元件呈現 Schema 預覽；不建立單據或保存資料。 */
    public function preview(Request $request, DocType $type): JsonResponse
    {
        $result = $this->previewService->preview($type, $request->all());

        return response()->json($result, $result['validation']['valid'] ? 200 : 422);
    }

    /** 以獨立頁面顯示目前草稿的唯讀預覽，不進入正式新增流程。 */
    public function previewPage(DocType $type): View
    {
        $schema = $this->revisions->workingSchema($type);
        $result = $this->previewService->preview($type, $schema);

        return view('document-builder::records.preview', [
            'docType' => $type,
            'previewHtml' => $result['html'] ?? null,
            'validation' => $result['validation'],
        ]);
    }
}
