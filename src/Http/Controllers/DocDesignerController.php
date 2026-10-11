<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Controllers;

use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\Support\Assets;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Services\DocTypeService;
use DocumentBuilder\Services\DocumentBuilderSchemaAdapter;
use DocumentBuilder\Services\FormLayoutService;
use DocumentBuilder\Services\MasterSourceRegistry;
use DocumentBuilder\FormPlatform\ComponentRegistry;
use DocumentBuilder\FormPlatform\FormRevisionService;
use DocumentBuilder\FormPlatform\FormSchemaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * 表單設計器（GrapesJS 全螢幕頁），也是單據設定的編輯頁：
 * 名稱與按鈕在畫布的標題列元件上改，排序、狀態在點畫布空白處（整張單據）時改。
 * 存檔只收版面結構與單據設定，不收 HTML；兩者同一個交易，任一邊驗證失敗都不寫入。
 */
class DocDesignerController extends Controller
{
    public function __construct(
        private readonly FormLayoutService $layout,
        private readonly MasterSourceRegistry $masters,
        private readonly DocTypeService $types,
        private readonly DocumentBuilderSchemaAdapter $schemaAdapter,
        private readonly FormSchemaValidator $schemaValidator,
        private readonly ComponentRegistry $registry,
        private readonly FormRevisionService $revisions,
    ) {}

    public function edit(DocType $type)
    {
        return view('document-builder::designer', [
            'type' => $type,
            'config' => $this->config($type->load(['fields', 'statuses'])),
        ]);
    }

    public function update(Request $request, DocType $type): JsonResponse
    {
        $requestData = $request->all();
        $usesSchema = array_key_exists('schema_version', $requestData);
        if ($usesSchema) {
            $validation = $this->schemaValidator->validate($requestData, $type->code);
            if (! $validation['valid']) {
                $this->rejectSchema($validation['errors']);
            }

            try {
                $payload = $this->schemaAdapter->toDesignerPayload($type, $requestData);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['schema' => $exception->getMessage()]);
            }
            $rows = $payload['rows'];
            $settings = $payload['settings'];
            $events = $payload['events'];
        } else {
            // 舊 rows/settings 用戶端維持相容；Schema 用戶端由共用 Validator 驗證完整設定。
            $settings = $request->validate([
                'settings' => ['sometimes', 'array'],
                'settings.name' => ['required_with:settings', 'string', 'max:50'],
                'settings.status' => ['required_with:settings', 'boolean'],
                'settings.seq' => ['nullable', 'integer', 'min:0'],
                'settings.no_prefix' => ['sometimes', 'nullable', 'string', 'max:20'],
                'settings.no_date_format' => ['sometimes', Rule::in(['Ymd', 'Ym', 'Y'])],
                'settings.no_seq_digits' => ['sometimes', 'integer', 'min:1', 'max:12'],
                // array:key1,key2 會擋下清單以外的按鈕代碼
                'settings.buttons' => ['sometimes', 'array:'.implode(',', array_keys(DocType::BUTTONS))],
                'settings.buttons.*' => ['boolean'],
            ], [], [
                'settings.name' => '單據名稱', 'settings.status' => '狀態', 'settings.seq' => '選單排序',
                'settings.no_prefix' => '單號前綴', 'settings.no_date_format' => '單號日期格式',
                'settings.no_seq_digits' => '流水號位數', 'settings.buttons' => '按鈕',
            ])['settings'] ?? null;
            $rows = (array) $request->input('rows', []);
            $events = null;
        }

        $actorId = DocumentBuilder::user()->getKey();
        if ($type->published_revision_id !== null) {
            if (! $usesSchema) {
                throw ValidationException::withMessages(['schema' => '發布過的表單必須使用 Schema v1 儲存草稿']);
            }
            $this->revisions->saveDraft($type, $requestData, $actorId, '設計器儲存');
        } else {
            $type->getConnection()->transaction(function () use ($rows, $type, $settings, $events, $actorId): void {
                $this->layout->save($type, $rows);
                if ($settings !== null) {
                    $this->types->update($type, $settings);
                }
                if ($events !== null) {
                    $type->forceFill(['event_schema' => $events])->save();
                }

                $schema = $this->schemaAdapter->export($type->fresh(['fields', 'statuses']));
                $validation = $this->schemaValidator->validate($schema, $type->code);
                if (! $validation['valid']) {
                    $this->rejectSchema($validation['errors']);
                }
                $this->revisions->saveDraft($type, $schema, $actorId, '設計器儲存');
            });
        }
        $type = $type->fresh(['fields', 'statuses', 'workingRevision']);
        $schema = $this->revisions->workingSchema($type);
        $projection = $this->revisions->designerProjection($type, $schema);
        $settings = $this->settingsFromSchema($schema);

        return response()->json([
            'message' => '已儲存',
            'components' => $this->layout->canvas($projection),
            'archived' => $this->layout->archivedCells($projection),
            'archivedTables' => $this->layout->archivedTables($projection),
            'settings' => $settings,
            'schema' => $schema,
        ]);
    }

    /** 將 Validator 的路徑錯誤轉成 Laravel 422 格式，讓舊設計器也能顯示欄位位置。 */
    private function rejectSchema(array $errors): never
    {
        $messages = [];
        foreach ($errors as $error) {
            $path = $error['path'] === '$' ? 'root' : $error['path'];
            $messages['schema.'.$path][] = $error['message'];
        }

        throw ValidationException::withMessages($messages);
    }

    private function config(DocType $type): array
    {
        $schema = $this->revisions->workingSchema($type);
        $projection = $this->revisions->designerProjection($type, $schema);

        return [
            'csrf' => csrf_token(),
            'components' => $this->layout->canvas($projection),
            'archived' => $this->layout->archivedCells($projection),
            'archivedTables' => $this->layout->archivedTables($projection),
            'settings' => $this->settingsFromSchema($schema),
            'buttonLabels' => DocType::BUTTONS,
            'registry' => $this->registry->listing(),
            'schema' => $schema,
            'canPublish' => DocumentBuilder::isSuperAdmin(),
            // 已有資料時，既有欄位的主檔來源不能改（型別在設計器本來就不能改）
            'hasRecords' => $type->records()->withTrashed()->exists(),
            'masters' => $this->masters->options(),
            'widths' => config('document_builder.widths'),
            'canvasStyles' => Assets::canvasStyles(),
            'urls' => [
                'save' => route(document_builder_route_name('document_builder.designer.update'), $type),
                'back' => route(document_builder_route_name('document_builder.types.index')),
                'preview' => route(document_builder_route_name('document_builder.types.preview'), $type),
                'publish' => route(document_builder_route_name('document_builder.revisions.publish'), $type),
                'revisions' => route(document_builder_route_name('document_builder.revisions.index'), $type),
            ],
        ];
    }

    private function settingsFromSchema(array $schema): array
    {
        return [
            'name' => $schema['form']['name'],
            'code' => $schema['form']['code'],
            'status' => (bool) $schema['form']['enabled'],
            'seq' => (int) $schema['form']['order'],
            'noPrefix' => (string) $schema['form']['numbering']['prefix'],
            'noDateFormat' => (string) $schema['form']['numbering']['date_format'],
            'noSeqDigits' => (int) $schema['form']['numbering']['sequence_digits'],
            'exampleNo' => $schema['form']['numbering']['prefix']
                .now()->format($schema['form']['numbering']['date_format'])
                .str_pad('1', (int) $schema['form']['numbering']['sequence_digits'], '0', STR_PAD_LEFT),
            'buttons' => collect(DocType::BUTTONS)->map(fn (string $label, string $key): bool => (bool) $schema['form']['buttons'][$key])->all(),
        ];
    }

    /** 加上檔案時間戳，改了 JS／CSS 不必清瀏覽器快取 */
}
