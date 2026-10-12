<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use DocumentBuilder\Models\DocType;

/** 驗證 Schema 並以正式 Runtime partial 產生唯讀預覽。 */
class FormSchemaPreviewService
{
    public function __construct(
        private readonly FormSchemaValidator $validator,
        private readonly FormRuntimeRenderer $runtimeRenderer,
    ) {}

    /** @return array{validation: array, html?: string} */
    public function preview(DocType $type, array $schema): array
    {
        return $this->previewSchema($schema, $type->code);
    }

    /** 預覽尚未儲存的 Schema，供 MCP 在建立草稿前先檢查畫面。 */
    public function previewSchema(array $schema, string $expectedFormCode): array
    {
        $validation = $this->validator->validate($schema, $expectedFormCode);
        if (! $validation['valid']) {
            return ['validation' => $validation];
        }

        $html = view('document-builder::records.schema_preview', [
            'formName' => $schema['form']['name'],
            'rows' => $this->runtimeRenderer->rows($schema, $expectedFormCode),
            'labelLeft' => ($schema['form']['label_layout'] ?? 'top') === 'left',
            'record' => null,
            'show' => true,
            'values' => [],
            'items' => collect(),
            'events' => $schema['events'],
        ])->render();

        return ['html' => $html, 'validation' => $validation];
    }
}
