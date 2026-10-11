<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Controllers;

use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocRecord;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Services\DocRecordService;
use DocumentBuilder\Services\MasterSourceRegistry;
use DocumentBuilder\Services\RecordValidator;
use DocumentBuilder\FormPlatform\FormRevisionService;
use DocumentBuilder\FormPlatform\FormRuntimeRenderer;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Database\Eloquent\Builder;
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
            ->with(['creator:id,name', 'status'])
            ->where('doc_type_id', $docType->id)
            ->when($request->filled('status_id'), fn (Builder $q) => $q->where('status_id', (int) $request->input('status_id')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->where('doc_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->where('doc_date', '<=', $request->input('date_to')))
            ->when($request->filled('keyword'), function (Builder $query) use ($request): void {
                $keyword = '%'.$request->input('keyword').'%';
                $query->where(fn (Builder $query) => $query->where('no', 'like', $keyword)->orWhere('data', 'like', $keyword));
            })
            ->orderByDesc('doc_date')
            ->orderByDesc('id');

        $table = DataTables::eloquent($query)
            ->editColumn('doc_date', fn (DocRecord $r): string => $r->doc_date->format('Y-m-d'))
            ->addColumn('creator_name', fn (DocRecord $r): string => (string) $r->creator?->name)
            ->addColumn('status_name', fn (DocRecord $r): string => (string) $r->status?->name)
            ->addColumn('status_color', fn (DocRecord $r): string => (string) ($r->status?->color ?? 'secondary'))
            ->addColumn('status_locks_editing', fn (DocRecord $r): bool => (bool) $r->status?->locks_editing)
            ->addColumn('revision_locked', fn (DocRecord $r): bool => ! $this->revisions->isCurrentRecordRevision($docType, $r));

        foreach ($listFields as $field) {
            $table->addColumn("f_{$field->key}", fn (DocRecord $r): string => $field->display($r->value($field->key)));
        }

        return $table->make(true);
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

        return response()->json(['message' => __('create').__('success'), 'id' => $record->id]);
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

        return response()->json(['message' => __('edit').__('success'), 'id' => $record->id]);
    }

    public function destroy(DocType $docType, DocRecord $record): JsonResponse
    {
        $this->ensureActive($docType, 'delete');
        $this->ensureCurrentRevision($docType, $record);
        $this->ensureUnlocked($record);
        $this->service->delete($record);

        return response()->json(['message' => __('delete').__('success')]);
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
        $isOutdated = $record !== null && ! $this->revisions->isCurrentRecordRevision($docType, $record);
        $formRevision = $record?->formRevision;

        return view('document-builder::records.form', compact(
            'docType', 'record', 'show', 'rows', 'adjacent', 'items', 'statuses', 'events', 'isOutdated', 'formRevision'
        ));
    }

    /** 停用的單據種類整個不能進入；關掉的按鈕連網址一起擋下，不只是隱藏 */
    private function ensureActive(DocType $docType, ?string $button = null): void
    {
        abort_unless($docType->status, 404);
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

    private function userId(Request $request): int
    {
        return (int) DocumentBuilder::user()->getKey();
    }
}
