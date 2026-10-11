<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

/** 發布前以共用 Schema 與 Runtime Renderer 檢查可執行性。 */
class FormPublishValidator
{
    public function __construct(
        private readonly FormSchemaValidator $schemaValidator,
        private readonly FormRuntimeRenderer $renderer,
    ) {}

    /** @return array{valid: bool, errors: array, warnings: array} */
    public function validate(array $schema, string $expectedCode): array
    {
        $result = $this->schemaValidator->validate($schema, $expectedCode);
        if (! $result['valid']) {
            return $result;
        }

        try {
            $this->renderer->rows($schema, $expectedCode);
        } catch (\InvalidArgumentException $exception) {
            $result['errors'][] = ['path' => '$', 'message' => $exception->getMessage()];
            $result['valid'] = false;
        }

        if ($schema['fields'] === []) {
            $result['warnings'][] = ['path' => 'fields', 'message' => '表單沒有任何欄位'];
        }

        return $result;
    }
}
