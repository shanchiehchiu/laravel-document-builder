<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\Services\MasterSourceRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocMcpOperation;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Services\DocTypeService;
use DocumentBuilder\Services\DocumentBuilderSchemaAdapter;
use DocumentBuilder\Services\FormLayoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mcp\Exception\ToolCallException;

/** MCP 建立草稿並保存寫入稽核與冪等回應。 */
class FormDraftService
{
    public function __construct(
        private readonly DocTypeService $types,
        private readonly DocumentBuilderSchemaAdapter $adapter,
        private readonly FormLayoutService $layout,
        private readonly ComponentRegistry $registry,
        private readonly FormSchemaValidator $schemaValidator,
        private readonly FormRevisionService $revisions,
    ) {}

    /** 僅允許超級管理員建立停用的單據草稿。 */
    public function createForm(string $formCode, string $formName, string $idempotencyKey): array
    {
        $actor = $this->currentAdmin();
        if (! DocumentBuilder::isSuperAdmin($actor)) {
            throw new ToolCallException('只有超級管理員可以建立表單草稿');
        }

        $input = Validator::make([
            'form_code' => $formCode,
            'form_name' => $formName,
            'idempotency_key' => $idempotencyKey,
        ], [
            'form_code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,29}$/'],
            'form_name' => ['required', 'string', 'max:50'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ], [
            'form_code.regex' => '表單代碼只能用小寫英文、數字及底線，開頭須為英文，長度 2～30 字',
        ])->validate();

        return $this->idempotent($actor, 'create_form', $input['idempotency_key'], $input, function (DocMcpOperation $operation) use ($input): array {
            if (DocType::query()->where('code', $input['form_code'])->exists()) {
                throw new ToolCallException('表單代碼已存在');
            }

            $type = $this->types->create([
                'code' => $input['form_code'],
                'name' => $input['form_name'],
                'status' => false,
            ]);
            $schema = $this->adapter->export($type->fresh(['fields', 'statuses']));
            $this->revisions->saveDraft($type, $schema, $operation->admin_user_id, '透過 MCP 建立草稿');
            $operation->doc_type_id = $type->id;

            return [
                'form_code' => $type->code,
                'draft' => true,
                'schema' => $this->adapter->export($type->fresh(['fields', 'statuses'])),
            ];
        });
    }

    /** 一次驗證並儲存完整 Schema；所有資料庫變更與草稿修訂都在同一交易內。 */
    public function applyFormPlan(
        string $formCode,
        array $schema,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        $actor = $this->currentAdmin();
        $input = Validator::make([
            'form_code' => $formCode,
            'idempotency_key' => $idempotencyKey,
            'expected_revision' => $expectedRevision,
        ], [
            'form_code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,29}$/'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'expected_revision' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        $request = [
            'form_code' => $input['form_code'],
            'schema' => $schema,
            'expected_revision' => $input['expected_revision'] ?? null,
        ];

        return $this->idempotent($actor, 'apply_form_plan', $input['idempotency_key'], $request,
            function (DocMcpOperation $operation) use ($actor, $formCode, $schema, $expectedRevision): array {
                $type = DocType::query()->where('code', $formCode)->lockForUpdate()->first();
                if ($type !== null) {
                    $type = $this->findOwnedDraft($formCode, $actor);
                    $currentRevision = (int) ($type->workingRevision?->revision ?? 0);
                    if ($expectedRevision === null) {
                        throw new ToolCallException('修改既有草稿時必須提供 expected_revision');
                    }
                    if ($expectedRevision !== $currentRevision) {
                        throw new ToolCallException("草稿版本已更新，目前版本為 {$currentRevision}；請先重新讀取草稿");
                    }
                    $schema['statuses'] ??= $this->revisions->workingSchema($type)['statuses'];
                } else {
                    if (! DocumentBuilder::isSuperAdmin($actor)) {
                        throw new ToolCallException('只有超級管理員可以建立表單草稿');
                    }
                    if ($expectedRevision !== null) {
                        throw new ToolCallException('建立新草稿時不得提供 expected_revision');
                    }
                    $schema['statuses'] ??= array_map(static fn (array $status): array => [
                        'code' => $status['code'],
                        'name' => $status['name'],
                        'is_default' => $status['is_default'],
                        'locks_editing' => $status['locks_editing'],
                        'color' => $status['color'],
                        'order' => $status['seq'],
                    ], $this->types->defaultStatuses());
                }

                $validation = $this->schemaValidator->validate($schema, $formCode);
                if (! $validation['valid']) {
                    $messages = array_map(
                        fn (array $error): string => $error['path'].': '.$error['message'],
                        $validation['errors']
                    );
                    throw new ToolCallException('草稿 Schema 無效：'.implode('；', $messages));
                }
                if ($schema['form']['enabled']) {
                    throw new ToolCallException('MCP 只可以建立或修改停用草稿；啟用必須經人工審核與發布');
                }

                if ($type === null) {
                    $type = $this->types->create([
                        'code' => $formCode,
                        'name' => $schema['form']['name'],
                        'status' => false,
                        'seq' => $schema['form']['order'],
                    ]);
                    $type->load(['fields', 'statuses']);
                }

                try {
                    $payload = $this->adapter->toDesignerPayload($type, $schema);
                } catch (\InvalidArgumentException $exception) {
                    throw new ToolCallException('草稿 Schema 無法套用：'.$exception->getMessage());
                }

                try {
                    $this->layout->save($type, $payload['rows']);
                    $this->types->update($type, $payload['settings'] + ['status' => false]);
                } catch (ValidationException $exception) {
                    $messages = array_merge(...array_values($exception->errors()));
                    throw new ToolCallException('草稿版面無法儲存：'.implode('；', $messages));
                }

                $type->forceFill(['event_schema' => $payload['events']])->save();
                $type = $type->fresh(['fields', 'statuses']);
                $savedSchema = $this->adapter->export($type);
                $savedValidation = $this->schemaValidator->validate($savedSchema, $formCode);
                if (! $savedValidation['valid']) {
                    throw new ToolCallException('儲存後的草稿 Schema 驗證失敗');
                }

                $revision = $this->revisions->saveDraft(
                    $type,
                    $savedSchema,
                    $actor->getKey(),
                    '透過 MCP 原子套用表單計畫'
                );
                $operation->doc_type_id = $type->id;

                return [
                    'form_code' => $formCode,
                    'draft' => true,
                    'revision' => $revision->revision,
                    'summary' => [
                        'field_count' => count(array_filter($savedSchema['fields'], static fn (array $field): bool => ! $field['archived'])),
                        'table_count' => collect($savedSchema['layout']['rows'])
                            ->flatMap(static fn (array $row): array => $row['cols'])
                            ->filter(static fn (array $col): bool => ($col['component']['type'] ?? null) === 'table')
                            ->count(),
                        'event_count' => count($savedSchema['events']),
                    ],
                    'validation' => $savedValidation,
                ];
            });
    }

    /** 只在建立者自己的停用草稿尾端加入一個登錄元件。 */
    public function addComponent(string $formCode, string $componentType, array $props, string $idempotencyKey): array
    {
        $actor = $this->currentAdmin();
        $idempotencyKey = Validator::make(['idempotency_key' => $idempotencyKey], [
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ])->validate()['idempotency_key'];

        $request = ['form_code' => $formCode, 'component_type' => $componentType, 'props' => $props];

        return $this->idempotent($actor, 'add_component', $idempotencyKey, $request, function (DocMcpOperation $operation) use ($actor, $formCode, $componentType, $props): array {
            $type = $this->findOwnedDraft($formCode, $actor);
            $schema = $this->adapter->export($type);
            [$component, $width, $field] = $this->componentDefinition($componentType, $props, $schema);

            if ($field !== null) {
                $schema['fields'][] = $field;
            }
            if ($componentType === 'table') {
                $schema['layout']['tables'][$component['key']] = $component['title'];
            }
            if ($field !== null && $field['section'] === DocField::SECTION_ITEM) {
                $this->appendFieldToTable($schema, (string) $field['table_key'], $field['key']);
            } else {
                $schema['layout']['rows'][] = ['cols' => [[
                    'width' => $width,
                    'component' => $component,
                ]]];
            }

            $validation = $this->schemaValidator->validate($schema, $type->code);
            if (! $validation['valid']) {
                $messages = array_column($validation['errors'], 'message');
                throw new ToolCallException('元件不符合表單 Schema：'.implode('；', $messages));
            }

            try {
                $payload = $this->adapter->toDesignerPayload($type, $schema);
                $this->layout->save($type, $payload['rows']);
            } catch (ValidationException $exception) {
                $messages = array_merge(...array_values($exception->errors()));
                throw new ToolCallException('元件無法加入草稿：'.implode('；', $messages));
            } catch (\InvalidArgumentException $exception) {
                throw new ToolCallException('元件無法加入草稿：'.$exception->getMessage());
            }

            $type = $type->fresh(['fields', 'statuses']);
            $schema = $this->adapter->export($type);
            $validation = $this->schemaValidator->validate($schema, $type->code);
            if (! $validation['valid']) {
                $messages = array_map(
                    fn (array $error): string => $error['path'].': '.$error['message'],
                    $validation['errors']
                );
                throw new ToolCallException('儲存後的草稿 Schema 驗證失敗：'.implode('；', $messages));
            }

            $operation->doc_type_id = $type->id;
            $this->revisions->saveDraft($type, $schema, $actor->getKey(), '透過 MCP 修改草稿元件');

            return [
                'form_code' => $type->code,
                'draft' => true,
                'component_type' => $componentType,
                'schema' => $schema,
            ];
        });
    }

    /** 只允許建立者修改自己的停用草稿事件，並以 Schema Validator 拒絕任意執行碼。 */
    public function setFormEvents(string $formCode, array $events, string $idempotencyKey): array
    {
        $actor = $this->currentAdmin();
        $idempotencyKey = Validator::make(['idempotency_key' => $idempotencyKey], [
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ])->validate()['idempotency_key'];
        $request = ['form_code' => $formCode, 'events' => $events];

        return $this->idempotent($actor, 'set_form_events', $idempotencyKey, $request, function (DocMcpOperation $operation) use ($actor, $formCode, $events): array {
            $type = $this->findOwnedDraft($formCode, $actor);
            $schema = $this->adapter->export($type);
            $schema['events'] = $events;
            $validation = $this->schemaValidator->validate($schema, $type->code);
            if (! $validation['valid']) {
                $messages = array_map(
                    fn (array $error): string => $error['path'].': '.$error['message'],
                    $validation['errors']
                );
                throw new ToolCallException('事件 Schema 驗證失敗：'.implode('；', $messages));
            }

            $type->forceFill(['event_schema' => $events])->save();
            $type = $type->fresh(['fields', 'statuses']);
            $schema = $this->adapter->export($type);
            $this->revisions->saveDraft($type, $schema, $actor->getKey(), '透過 MCP 修改欄位事件');
            $operation->doc_type_id = $type->id;

            return [
                'form_code' => $type->code,
                'draft' => true,
                'schema' => $schema,
            ];
        });
    }

    /** 讀取建立者自己的停用草稿；一般已啟用表單仍走既有檢視權限服務。 */
    public function findOwnedDraft(string $formCode, ?Authenticatable $actor = null): DocType
    {
        $actor ??= $this->currentAdmin();
        $query = DocType::query()->where('code', $formCode)->where('status', false);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $type = $query->first();

        if (! $type || ! DocMcpOperation::query()
            ->where('admin_user_id', $actor->getKey())
            ->where('doc_type_id', $type->id)
            ->whereIn('operation', ['create_form', 'apply_form_plan'])
            ->exists()) {
            throw new ToolCallException('無法存取指定表單草稿');
        }

        return $type;
    }

    private function currentAdmin(): Authenticatable
    {
        $actor = DocumentBuilder::user();
        if ($actor === null) {
            throw new ToolCallException('請先登入後台');
        }

        return $actor;
    }

    /** 將同一管理員的冪等鍵、操作、請求摘要與回應放在同一交易。 */
    private function idempotent(
        Authenticatable $actor,
        string $operationName,
        string $idempotencyKey,
        array $request,
        \Closure $callback
    ): array {
        $requestHash = hash('sha256', json_encode(
            $this->sortAssociativeKeys(['operation' => $operationName, 'request' => $request]),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return DB::transaction(function () use ($actor, $operationName, $idempotencyKey, $requestHash, $callback): array {
            DocMcpOperation::query()->getConnection()->table('doc_mcp_operations')->insertOrIgnore([
                'admin_user_id' => $actor->getKey(),
                'operation' => $operationName,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $entry = DocMcpOperation::query()
                ->where('admin_user_id', $actor->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals($entry->request_hash, $requestHash)) {
                throw new ToolCallException('冪等鍵已用於不同的請求');
            }
            if ($entry->response_data !== null) {
                return $entry->response_data;
            }

            $response = $callback($entry);
            $entry->response_data = $response;
            $entry->save();

            return $response;
        });
    }

    private function sortAssociativeKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortAssociativeKeys($item);
            }
        }

        return $value;
    }

    /** 將工具輸入轉成目前 Schema v1 的單一元件與可選欄位定義。 */
    private function componentDefinition(string $componentType, array $props, array $schema): array
    {
        $allowed = match ($componentType) {
            'field' => ['key', 'label', 'field_type', 'section', 'table_key', 'required', 'width', 'options', 'show_in_list'],
            'heading' => ['text', 'bg', 'border', 'width'],
            'note' => ['text', 'width'],
            'spacer' => ['width'],
            'divider' => ['width'],
            'table' => ['title'],
            default => throw new ToolCallException('指定的元件類型未登錄'),
        };
        $unknown = array_diff(array_keys($props), $allowed);
        if ($unknown !== []) {
            throw new ToolCallException('元件含有不支援的屬性：'.implode('、', $unknown));
        }

        try {
            return match ($componentType) {
                'field' => $this->fieldDefinition($props, $schema),
                'heading' => $this->textComponent('heading', $props, 12),
                'note' => $this->textComponent('note', $props, 12),
                'spacer' => $this->spacerComponent($props),
                'divider' => $this->dividerComponent($props),
                'table' => $this->tableComponent($props, $schema),
            };
        } catch (ValidationException $exception) {
            $messages = array_merge(...array_values($exception->errors()));
            throw new ToolCallException('元件屬性無效：'.implode('；', $messages));
        }
    }

    private function fieldDefinition(array $props, array $schema): array
    {
        $props['section'] ??= isset($props['table_key']) ? DocField::SECTION_ITEM : DocField::SECTION_HEADER;
        $input = Validator::make($props, [
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,49}$/'],
            'label' => ['required', 'string', 'max:50'],
            'field_type' => ['required', Rule::in(array_keys(config('document_builder.field_types')))],
            'section' => ['required', Rule::in(DocField::SECTIONS)],
            'table_key' => ['nullable', 'required_if:section,item', 'string', 'regex:/^[a-z][a-z0-9_]{0,29}$/'],
            'required' => ['sometimes', 'boolean'],
            'width' => ['sometimes', Rule::in(array_keys(config('document_builder.widths')))],
            'options' => ['sometimes', 'array'],
            'options.choices' => ['sometimes', 'array', 'min:1', 'max:50'],
            'options.choices.*' => ['string', 'max:100'],
            'options.decimals' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'options.source' => ['sometimes', Rule::in(array_keys(app(MasterSourceRegistry::class)->options()))],
            'options.sum' => ['sometimes', 'boolean'],
            'show_in_list' => ['sometimes', 'boolean'],
        ], ['key.regex' => '欄位代碼只能用小寫英文、數字及底線，開頭須為英文'])->validate();

        $section = $input['section'];
        $tableKey = $section === DocField::SECTION_ITEM ? $input['table_key'] : null;
        if ($section === DocField::SECTION_HEADER && isset($input['table_key'])) {
            throw new ToolCallException('表頭欄位不得指定 table_key');
        }
        if ($section === DocField::SECTION_ITEM && ! $this->tableExists($schema, (string) $tableKey)) {
            throw new ToolCallException('找不到指定明細表，請先新增明細表元件');
        }

        if (collect($schema['fields'])->contains(fn (array $field): bool => $field['key'] === $input['key'])) {
            throw new ToolCallException('欄位代碼已存在');
        }

        $fieldType = $input['field_type'];
        $defaultWidth = (int) $this->registry->fieldType($fieldType)['default_width'];
        $order = collect($schema['fields'])
            ->where('section', $section)
            ->where('table_key', $tableKey)
            ->count() + 1;
        $field = [
            'key' => $input['key'],
            'section' => $section,
            'table_key' => $tableKey,
            'label' => $input['label'],
            'type' => $fieldType,
            'required' => (bool) ($input['required'] ?? false),
            'width' => (int) ($input['width'] ?? $defaultWidth),
            'options' => $input['options'] ?? [],
            'show_in_list' => (bool) ($input['show_in_list'] ?? false),
            'order' => $order,
            'archived' => false,
        ];

        return [
            ['type' => 'field', 'key' => $field['key']],
            $field['width'],
            $field,
        ];
    }

    /** 確認明細表存在於目前版面，避免留下沒有容器的明細欄位。 */
    private function tableExists(array $schema, string $tableKey): bool
    {
        foreach ($schema['layout']['rows'] ?? [] as $row) {
            foreach ($row['cols'] ?? [] as $col) {
                $component = $col['component'] ?? [];
                if (($component['type'] ?? null) === 'table' && ($component['key'] ?? null) === $tableKey) {
                    return true;
                }
            }
        }

        return false;
    }

    /** 將明細欄位參照加入指定明細表，維持 Schema 欄位與版面的原子一致。 */
    private function appendFieldToTable(array &$schema, string $tableKey, string $fieldKey): void
    {
        foreach ($schema['layout']['rows'] as &$row) {
            foreach ($row['cols'] as &$col) {
                $component = &$col['component'];
                if (($component['type'] ?? null) === 'table' && ($component['key'] ?? null) === $tableKey) {
                    $component['fields'][] = ['key' => $fieldKey];
                    unset($component, $col, $row);

                    return;
                }
                unset($component);
            }
            unset($col);
        }
        unset($row);

        throw new ToolCallException('找不到指定明細表，無法放入欄位');
    }

    private function textComponent(string $componentType, array $props, int $defaultWidth): array
    {
        $input = Validator::make($props, [
            'text' => ['required', 'string', 'min:1', 'max:500'],
            'bg' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'border' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'width' => ['sometimes', Rule::in(array_keys(config('document_builder.widths')))],
        ])->validate();

        $component = ['type' => $componentType, 'text' => $input['text']];
        foreach (['bg', 'border'] as $color) {
            if ($componentType === 'heading' && ! empty($input[$color])) {
                $component[$color] = strtolower($input[$color]);
            }
        }

        return [$component, (int) ($input['width'] ?? $defaultWidth), null];
    }

    private function spacerComponent(array $props): array
    {
        $input = Validator::make($props, [
            'width' => ['required', Rule::in(array_keys(config('document_builder.widths')))],
        ])->validate();

        return [['type' => 'spacer'], (int) $input['width'], null];
    }

    private function dividerComponent(array $props): array
    {
        $input = Validator::make($props, [
            'width' => ['required', Rule::in(array_keys(config('document_builder.widths')))],
        ])->validate();

        return [['type' => 'divider'], (int) $input['width'], null];
    }

    private function tableComponent(array $props, array $schema): array
    {
        $input = Validator::make($props, ['title' => ['required', 'string', 'min:1', 'max:50']])->validate();
        $tables = $schema['layout']['tables'];
        $number = count($tables) + 1;
        while (array_key_exists("table_{$number}", $tables)) {
            $number++;
        }
        $key = "table_{$number}";

        return [[
            'type' => 'table',
            'key' => $key,
            'title' => trim($input['title']),
            'fields' => [],
        ], 12, null];
    }
}
