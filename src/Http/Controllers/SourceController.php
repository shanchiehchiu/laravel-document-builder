<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Controllers;

use DocumentBuilder\Models\DocSource;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Services\MasterSourceRegistry;
use DocumentBuilder\Services\SourceService;
use DocumentBuilder\Sources\DocumentSourceDriver;
use DocumentBuilder\Sources\ModelCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 來源庫：管理主檔下拉的資料來源（只開放給 document-builder.design 的使用者，路由已擋） */
class SourceController extends Controller
{
    public function __construct(
        private readonly SourceService $service,
        private readonly MasterSourceRegistry $registry,
        private readonly ModelCatalog $catalog,
    ) {}

    public function index()
    {
        $drivers = $this->registry->drivers();
        $describe = fn (string $driver, array $settings): string => isset($drivers[$driver]) ? $drivers[$driver]->describe($settings) : '';

        // 設定檔裡的來源：程式定義，只列出來，不能在畫面上改
        $configured = collect((array) config('document_builder.masters', []))
            ->map(fn (array $def, string $code): array => [
                'code' => $code,
                'label' => $def['label'],
                'driver' => isset($drivers[$def['driver'] ?? 'model']) ? $drivers[$def['driver'] ?? 'model']->label() : '',
                'summary' => $describe($def['driver'] ?? 'model', collect($def)->except(['label', 'driver'])->all()),
                'usage' => $this->service->usageCount($code),
            ])
            ->values();

        $sources = DocSource::query()->orderBy('id')->get()->map(fn (DocSource $source): array => [
            'model' => $source,
            'driver' => isset($drivers[$source->driver]) ? $drivers[$source->driver]->label() : $source->driver,
            'summary' => $describe($source->driver, (array) $source->settings),
            'usage' => $this->service->usageCount($source->code),
        ]);

        return view('document-builder::sources.index', compact('configured', 'sources'));
    }

    public function create()
    {
        return $this->form(new DocSource(['driver' => 'model', 'settings' => [], 'status' => true]));
    }

    public function store(Request $request): JsonResponse
    {
        $source = $this->service->create($request->all());

        return response()->json(['message' => '來源已新增', 'id' => $source->id]);
    }

    public function edit(DocSource $source)
    {
        return $this->form($source);
    }

    public function update(Request $request, DocSource $source): JsonResponse
    {
        $this->service->update($source, $request->all());

        return response()->json(['message' => '來源已更新', 'id' => $source->id]);
    }

    public function destroy(DocSource $source): JsonResponse
    {
        $this->service->delete($source);

        return response()->json(['message' => '來源已刪除']);
    }

    /** 某個資料模型可以用的欄位 */
    public function columns(Request $request): JsonResponse
    {
        $model = (string) $request->input('model');
        abort_unless($this->catalog->allows($model), 404);

        return response()->json(['columns' => $this->catalog->columns($model)]);
    }

    /** 某種單據可以當顯示文字的欄位 */
    public function documentFields(DocType $type, DocumentSourceDriver $driver): JsonResponse
    {
        return response()->json(['fields' => $driver->textFields($type)]);
    }

    /** 用還沒存的設定試搜尋 */
    public function preview(Request $request): JsonResponse
    {
        return response()->json(['results' => $this->service->preview($request->all(), (string) $request->input('term'))]);
    }

    private function form(DocSource $source)
    {
        return view('document-builder::sources.form', [
            'source' => $source,
            'usage' => $source->exists ? $this->service->usageCount($source->code) : 0,
            'drivers' => collect($this->registry->drivers())->map->label()->all(),
            'models' => $this->catalog->models(),
            'types' => DocType::query()->orderBy('seq')->orderBy('id')->get(['id', 'code', 'name']),
        ]);
    }
}
