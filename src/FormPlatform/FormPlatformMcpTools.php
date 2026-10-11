<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use DocumentBuilder\Services\DocumentBuilderSchemaAdapter;
use DocumentBuilder\Services\MasterSourceRegistry;
use Mcp\Exception\ToolCallException;
use Throwable;

/** MCP 唯讀工具，所有讀取、驗證與呈現都重用既有平台服務。 */
class FormPlatformMcpTools
{
    public function __construct(
        private readonly ComponentRegistry $registry,
        private readonly DocumentBuilderSchemaAdapter $adapter,
        private readonly FormSchemaValidator $validator,
        private readonly FormRequirementValidator $requirementValidator,
        private readonly FormSchemaPreviewService $previewService,
        private readonly FormTypeAccessService $typeAccess,
        private readonly FormDraftService $drafts,
        private readonly FormRevisionService $revisions,
        private readonly ResourceRegistry $resources,
        private readonly MasterSourceRegistry $masters,
    ) {}

    /** 列出可用元件與欄位型別，不公開內部 renderer 對應。 */
    public function listComponents(): array
    {
        $listing = $this->registry->listing();

        foreach ($listing['components'] as &$component) {
            unset($component['renderer']);
        }
        unset($component);

        foreach ($listing['field_types'] as &$fieldType) {
            unset($fieldType['renderer']);
        }
        unset($fieldType);

        return $listing;
    }

    /** 取得登錄元件或欄位型別的受控契約。 */
    public function getComponentSchema(string $component_type): array
    {
        try {
            $definitions = $this->registry->components();
            $definition = $definitions[$component_type] ?? $this->registry->fieldType($component_type);
        } catch (Throwable) {
            throw new ToolCallException('指定的元件或欄位型別未登錄');
        }

        unset($definition['renderer']);

        return $definition;
    }

    /** 依表單代碼讀取有權檢視的 Schema v1。 */
    public function getForm(string $form_code): array
    {
        $type = $this->findAccessibleType($form_code);
        $schema = $type->status
            ? $this->adapter->export($type)
            : $this->revisions->workingSchema($type);
        $revision = $type->status
            ? $type->publishedRevision?->revision
            : $type->workingRevision?->revision;

        return [
            'schema' => $schema,
            'revision' => (int) ($revision ?? 0),
        ];
    }

    /** 驗證建立者目前的工作草稿，回傳可直接定位的錯誤路徑。 */
    public function validateDraft(string $form_code): array
    {
        $type = $this->drafts->findOwnedDraft($form_code);
        $schema = $this->revisions->workingSchema($type);

        return [
            'form_code' => $type->code,
            'revision' => (int) ($type->workingRevision?->revision ?? 0),
            'validation' => $this->validator->validate($schema, $type->code),
        ];
    }

    /** 驗證提交的結構化 Schema，不儲存任何變更。 */
    public function validateForm(string $form_code, array $schema): array
    {
        $type = $this->findAccessibleType($form_code);

        return ['validation' => $this->validator->validate($schema, $type->code)];
    }

    /** 驗證尚未儲存的 Schema；只檢查結構與白名單，不讀取資料庫資料。 */
    public function validateSchema(string $form_code, array $schema): array
    {
        return ['validation' => $this->validator->validate($schema, $form_code)];
    }

    /** 將提示詞需求、假設與 Schema 對照，協助 AI 在套用前找出缺漏與待釐清事項。 */
    public function validateFormRequirements(
        string $original_prompt,
        array $requirements,
        array $assumptions,
        array $schema
    ): array {
        return $this->requirementValidator->validate(
            $original_prompt,
            $requirements,
            $assumptions,
            $schema,
        );
    }

    /** 以正式 Runtime 元件產生安全預覽，不建立或更新正式單據。 */
    public function previewForm(string $form_code, array $schema): array
    {
        $type = $this->findAccessibleType($form_code);

        return $this->previewService->preview($type, $schema);
    }

    /** 預覽尚未儲存的 Schema，讓呼叫端可先檢查再寫入停用草稿。 */
    public function previewSchema(string $form_code, array $schema): array
    {
        return $this->previewService->previewSchema($schema, $form_code);
    }

    /** 預覽建立者目前的工作草稿，不需重新傳送完整 Schema。 */
    public function previewDraft(string $form_code): array
    {
        $type = $this->drafts->findOwnedDraft($form_code);
        $schema = $this->revisions->workingSchema($type);

        return [
            'form_code' => $type->code,
            'revision' => (int) ($type->workingRevision?->revision ?? 0),
        ] + $this->previewService->preview($type, $schema);
    }

    /** 建立由目前超級管理員擁有的停用草稿。 */
    public function createForm(string $form_code, string $form_name, string $idempotency_key): array
    {
        return $this->drafts->createForm($form_code, $form_name, $idempotency_key);
    }

    /** 驗證後以單一交易建立或更新完整停用草稿。 */
    public function applyFormPlan(
        string $form_code,
        array $schema,
        string $idempotency_key,
        ?int $expected_revision = null
    ): array {
        return $this->drafts->applyFormPlan($form_code, $schema, $idempotency_key, $expected_revision);
    }

    /** 僅可由建立者讀寫自己尚未啟用的 MCP 草稿。 */
    public function addComponent(string $form_code, string $component_type, array $props, string $idempotency_key): array
    {
        return $this->drafts->addComponent($form_code, $component_type, $props, $idempotency_key);
    }

    /** 只把通過事件 DSL 驗證的規則寫入建立者自己的停用草稿。 */
    public function setFormEvents(string $form_code, array $events, string $idempotency_key): array
    {
        return $this->drafts->setFormEvents($form_code, $events, $idempotency_key);
    }

    /** 列出可用資源的公開契約，不包含後端 Model 或資料表名稱。 */
    public function listResources(): array
    {
        return ['resources' => $this->resources->listing()];
    }

    /** 取得指定資源的欄位及唯讀操作契約。 */
    public function getResourceSchema(string $resource_code): array
    {
        try {
            return ['resource' => $this->resources->get($resource_code)];
        } catch (\InvalidArgumentException) {
            throw new ToolCallException('指定的資源未登錄');
        }
    }

    /** 只有表單明確使用該主檔資源且使用者有表單檢視權限時才能搜尋。 */
    public function searchResource(string $form_code, string $resource_code, string $query): array
    {
        if (mb_strlen($query) > 100) {
            throw new ToolCallException('搜尋文字不可超過 100 個字元');
        }

        $type = $this->findAccessibleType($form_code);
        $resource = $this->getResourceSchema($resource_code)['resource'];
        $isBound = $type->fields
            ->whereNull('archived_at')
            ->contains(fn ($field): bool => $field->type === 'master'
                && ($field->options['source'] ?? null) === $resource_code);

        if (! $isBound) {
            throw new ToolCallException('指定表單尚未設定此主檔資源');
        }

        $results = $this->masters->search($resource_code, $query);

        return [
            'form_code' => $type->code,
            'resource_code' => $resource_code,
            'results' => array_map(fn (array $result): array => [
                'id' => $result['id'],
                'label' => $result['name'],
            ], $results),
            'max_results' => $resource['search']['max_results'],
        ];
    }

    private function findAccessibleType(string $formCode): \DocumentBuilder\Models\DocType
    {
        try {
            return $this->typeAccess->findViewable($formCode);
        } catch (ToolCallException) {
            return $this->drafts->findOwnedDraft($formCode);
        }
    }
}
