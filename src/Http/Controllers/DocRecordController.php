<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Controllers;

use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocRecord;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Services\DocRecordService;
use DocumentBuilder\Services\FilterIndexService;
use DocumentBuilder\Services\MasterSourceRegistry;
use DocumentBuilder\Services\RecordValidator;
use DocumentBuilder\FormPlatform\FormRevisionService;
use DocumentBuilder\FormPlatform\FormRuntimeRenderer;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 所有自訂單據共用這支 Controller，以網址上的 {docType:code} 區分種類
 */
class DocRecordController extends Controller
{
    public function __construct(
        private readonly DocRecordService $service,
        private readonly RecordValidator $validator,
        private readonly MasterSourceRegistry $masters,
        private readonly FormRuntimeRenderer $runtimeRenderer,
        private readonly FormRevisionService $revisions,
        private readonly FilterIndexService $filters,
    ) {}

    public function index(Request $request, DocType $docType)
    {
        $this->ensureActive($docType);
        $listFields = $docType->activeFields(DocField::SECTION_HEADER)->where('show_in_list', true)->values();
        $statuses = $docType->statuses;

        if (! $request->ajax()) {
            return view('document-builder::records.index', compact('docType', 'listFields', 'statuses'));
        }

        $query = DocRecord::query()
            ->with(['creator:'.DocumentBuilder::userColumns(), 'status'])
            ->where('doc_type_id', $docType->id)
            ->when($request->filled('status_id'), fn (Builder $q) => $q->where('status_id', (int) $request->input('status_id')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->where('doc_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->where('doc_date', '<=', $request->input('date_to')))
            ->when(is_array($request->input('field')), function (Builder $query) use ($request, $docType, $listFields): void {
                // 欄位值篩選只接受「列表顯示」的表頭欄位代碼，避免任意鍵進入查詢
                $fields = $listFields->keyBy('key');
                foreach ((array) $request->input('field') as $key => $value) {
                    $field = $fields->get($key);
                    if ($field === null || ! is_scalar($value) || trim((string) $value) === '') {
                        continue;
                    }
                    $value = trim((string) $value);

                    if ($field->type === 'master') {
                        // 主檔值存成 {"id": …, "label": …}，只比對 id；整數主鍵的值必須是數字
                        $source = (string) $field->masterSource();
                        if (! $this->masters->has($source)) {
                            continue;
                        }
                        if ($this->masters->keyType($source) === 'integer') {
                            if (! ctype_digit($value)) {
                                continue;
                            }
                            $value = (int) $value;
                        }
                    }

                    // 已建立篩選索引欄位的走索引；沒有的（例如索引還沒同步）退回 JSON 取值
                    if ($this->filters->hasColumn($docType, $key)) {
                        // 虛擬欄位是文字欄位，整數 id 也用字串比對
                        $query->where(FilterIndexService::columnName($docType, $key), (string) $value);

                        continue;
                    }

                    $query->where($field->type === 'master' ? "data->{$key}->id" : "data->{$key}", $value);
                }
            })
            ->when($request->filled('keyword'), function (Builder $query) use ($request): void {
                $keyword = '%'.addcslashes((string) $request->input('keyword'), '%_\\').'%';
                // 只比對單據的值，不比對 JSON 的鍵名（否則搜「customer」會命中所有單據）
                $query->where(fn (Builder $query) => $query->where('no', 'like', $keyword)->orWhere(fn (Builder $query) => $this->valueContains($query, $keyword)));
            })
            ->orderByDesc('doc_date')
            ->orderByDesc('id');

        $table = DataTables::eloquent($query)
            ->editColumn('doc_date', fn (DocRecord $r): string => $r->doc_date->format('Y-m-d'))
            ->addColumn('creator_name', fn (DocRecord $r): string => (string) DocumentBuilder::userName($r->creator))
            ->addColumn('status_name', fn (DocRecord $r): string => (string) $r->status?->name)
            ->addColumn('status_color', fn (DocRecord $r): string => (string) ($r->status?->color ?? 'secondary'))
            ->addColumn('status_locks_editing', fn (DocRecord $r): bool => (bool) $r->status?->locks_editing)
            ->addColumn('revision_locked', fn (DocRecord $r): bool => ! $this->revisions->isCurrentRecordRevision($docType, $r));

        foreach ($listFields as $field) {
            $table->addColumn("f_{$field->key}", fn (DocRecord $r): string => $field->display($r->value($field->key)));
        }

        return $table->make(true);
    }

    /** JSON 值（不含鍵名）包含關鍵字；MySQL 用 JSON_SEARCH，SQLite 用 json_each */
    private function valueContains(Builder $query, string $keyword): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $query->whereRaw('exists (select 1 from json_each(doc_records.data) where json_each.value like ? escape ?)', [$keyword, '\\']);

            return;
        }

        $query->whereRaw("JSON_SEARCH(doc_records.data, 'one', ?, '\\\\') IS NOT NULL", [$keyword]);
    }

    public function create(DocType $docType)
    {
        $this->ensureActive($docType, 'create');

        return $this->form($docType, null, false);
    }

    public function store(Request $request, DocType $docType): JsonResponse
    {
        $this->ensureActive($docType, 'create');
        $request->merge(['items' => $this->validator->cleanItems($docType, $request->input('items'))]);
        [$rules, $attributes] = $this->validator->rules($docType);

        $record = $this->service->create($docType, $request->validate($rules, [], $attributes), $this->userId($request));

        return response()->json(['message' => __('document-builder::ui.create').__('document-builder::ui.success'), 'id' => $record->id]);
    }

    public function show(DocType $docType, DocRecord $record)
    {
        $this->ensureActive($docType);

        return $this->form($docType, $record, true);
    }

    public function edit(DocType $docType, DocRecord $record)
    {
        $this->ensureActive($docType, 'edit');
        $this->ensureCurrentRevision($docType, $record);
        $this->ensureUnlocked($record);

        return $this->form($docType, $record, false);
    }

    public function update(Request $request, DocType $docType, DocRecord $record): JsonResponse
    {
        $this->ensureActive($docType, 'edit');
        $this->ensureCurrentRevision($docType, $record);
        $this->ensureUnlocked($record);
        $request->merge(['items' => $this->validator->cleanItems($docType, $request->input('items'))]);
        [$rules, $attributes] = $this->validator->rules($docType, $record);

        $this->service->update($docType, $record, $request->validate($rules, [], $attributes), $this->userId($request));

        return response()->json(['message' => __('document-builder::ui.edit').__('document-builder::ui.success'), 'id' => $record->id]);
    }

    public function destroy(DocType $docType, DocRecord $record): JsonResponse
    {
        $this->ensureActive($docType, 'delete');
        $this->ensureCurrentRevision($docType, $record);
        $this->ensureUnlocked($record);
        $this->service->delete($record);

        return response()->json(['message' => __('document-builder::ui.delete').__('document-builder::ui.success')]);
    }

    public function changeStatus(Request $request, DocType $docType, DocRecord $record): JsonResponse
    {
        $this->ensureActive($docType, 'edit');
        $data = $request->validate([
            'status_id' => [
                'required', 'integer',
                Rule::exists('doc_statuses', 'id')->where('doc_type_id', $docType->id),
            ],
        ], [], ['status_id' => '單據狀態']);

        $this->service->changeStatus($docType, $record, (int) $data['status_id'], $this->userId($request));

        return response()->json(['message' => '單據狀態已更新']);
    }

    /** 主檔下拉搜尋，給表單的 .js-select2[data-url] 使用 */
    public function master(Request $request, string $source): JsonResponse
    {
        abort_unless($this->masters->has($source), 404);

        return response()->json($this->masters->search($source, $request->input('search')));
    }

    private function form(DocType $docType, ?DocRecord $record, bool $show)
    {
        $schema = $record
            ? $this->revisions->schemaForRecord($docType, $record)
            : $this->revisions->runtimeSchema($docType);
        $rows = $this->runtimeRenderer->rowsForType($docType, $schema);
        $adjacent = $record && $docType->hasButton('navigation') ? $this->service->adjacent($record) : null;
        $items = $record ? $record->items->groupBy('table_key') : collect();
        $statuses = $docType->statuses;
        $events = $schema['events'] ?? [];
        $conversionGroups = $schema['conversion_groups'] ?? [];
        $isOutdated = $record !== null && ! $this->revisions->isCurrentRecordRevision($docType, $record);
        $formRevision = $record?->formRevision;

        return view('document-builder::records.form', compact(
            'docType', 'record', 'show', 'rows', 'adjacent', 'items', 'statuses', 'events', 'conversionGroups', 'isOutdated', 'formRevision'
        ));
    }

    /** 停用的單據種類整個不能進入；沒有檢視權限回 403；關掉的按鈕連網址一起擋下，不只是隱藏 */
    private function ensureActive(DocType $docType, ?string $button = null): void
    {
        abort_unless($docType->status, 404);
        abort_unless(DocumentBuilder::canViewType($docType), 403);
        abort_if($button !== null && ! $docType->hasButton($button), 404);
    }

    private function ensureUnlocked(DocRecord $record): void
    {
        abort_if((bool) $record->status?->locks_editing, 404);
    }

    private function ensureCurrentRevision(DocType $type, DocRecord $record): void
    {
        abort_unless($this->revisions->isCurrentRecordRevision($type, $record), 404);
    }

    private function userId(Request $request): int|string
    {
        return DocumentBuilder::user()->getKey();
    }
}
