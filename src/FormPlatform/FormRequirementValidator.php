<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

/** 檢查提示詞需求的可追溯性、Schema 對應與結構契約。 */
class FormRequirementValidator
{
    public function __construct(private readonly FormSchemaValidator $schemaValidator) {}

    /**
     * @return array{
     *     ready_to_apply: bool,
     *     validation: array{valid: bool, errors: array, warnings: array},
     *     coverage: array<string, int>,
     *     blocking_questions: array<int, array{type: string, reference: string, question: string}>,
     *     unmapped_schema_fields: array<int, array{key: string, schema_path: string}>
     * }
     */
    public function validate(
        string $originalPrompt,
        array $requirements,
        array $assumptions,
        array $schema
    ): array {
        $errors = [];
        $warnings = [];
        $blockingQuestions = [];
        $mappedPaths = [];
        $assumptionPaths = [];
        $seenRequirementIds = [];
        $mappedCount = 0;
        $unresolvedCount = 0;
        $activeFieldCount = 0;

        foreach ($requirements as $index => $requirement) {
            $path = "requirements.{$index}";
            if (! is_array($requirement)) {
                $errors[] = ['path' => $path, 'message' => '需求項目必須是物件'];

                continue;
            }

            $id = $requirement['id'] ?? null;
            if (! is_string($id) || trim($id) === '') {
                $errors[] = ['path' => $path.'.id', 'message' => '需求必須有識別碼'];
                $id = (string) $index;
            } elseif (isset($seenRequirementIds[$id])) {
                $errors[] = ['path' => $path.'.id', 'message' => '需求識別碼不可重複'];
            }
            $seenRequirementIds[$id] = true;

            $statement = $requirement['statement'] ?? null;
            if (! is_string($statement) || trim($statement) === '') {
                $errors[] = ['path' => $path.'.statement', 'message' => '需求描述不可空白'];
                $statement = '';
            }

            $sourceQuote = $requirement['source_quote'] ?? null;
            if (! is_string($sourceQuote) || trim($sourceQuote) === '') {
                $errors[] = ['path' => $path.'.source_quote', 'message' => '需求必須附上原文摘錄'];
            } elseif (! str_contains($originalPrompt, $sourceQuote)) {
                $errors[] = ['path' => $path.'.source_quote', 'message' => '原文摘錄不在使用者提示詞中，請重新核對來源'];
            }

            $status = $requirement['status'] ?? null;
            $schemaPaths = $requirement['schema_paths'] ?? null;
            if (! is_array($schemaPaths) || ! array_is_list($schemaPaths)) {
                $errors[] = ['path' => $path.'.schema_paths', 'message' => 'Schema 路徑必須是陣列'];
                $schemaPaths = [];
            }

            if ($status === 'mapped') {
                $mappedCount++;
                if ($schemaPaths === []) {
                    $errors[] = ['path' => $path.'.schema_paths', 'message' => '已對應需求至少需要一個 Schema JSON Pointer'];
                }
                foreach ($schemaPaths as $pathIndex => $schemaPath) {
                    if (! is_string($schemaPath) || ! $this->pointerExists($schema, $schemaPath)) {
                        $errors[] = [
                            'path' => "{$path}.schema_paths.{$pathIndex}",
                            'message' => 'Schema JSON Pointer 格式錯誤或指向不存在的內容',
                        ];

                        continue;
                    }
                    $mappedPaths[] = $schemaPath;
                }
            } elseif ($status === 'unresolved') {
                $unresolvedCount++;
                if ($schemaPaths !== []) {
                    $errors[] = ['path' => $path.'.schema_paths', 'message' => '未釐清需求不可同時宣稱已對應 Schema'];
                }
                $question = $requirement['question'] ?? null;
                if (! is_string($question) || trim($question) === '') {
                    $errors[] = ['path' => $path.'.question', 'message' => '未釐清需求必須附上要詢問使用者的問題'];
                } else {
                    $blockingQuestions[] = [
                        'type' => 'requirement',
                        'reference' => $id,
                        'question' => trim($question),
                    ];
                }
            } else {
                $errors[] = ['path' => $path.'.status', 'message' => '需求狀態只能是 mapped 或 unresolved'];
            }
        }

        foreach ($assumptions as $index => $assumption) {
            $path = "assumptions.{$index}";
            if (! is_array($assumption)) {
                $errors[] = ['path' => $path, 'message' => '假設項目必須是物件'];

                continue;
            }

            $schemaPath = $assumption['schema_path'] ?? null;
            if (! is_string($schemaPath) || ! $this->pointerExists($schema, $schemaPath)) {
                $errors[] = ['path' => $path.'.schema_path', 'message' => '假設必須指向存在的 Schema JSON Pointer'];
            } else {
                $assumptionPaths[] = $schemaPath;
            }

            $statement = $assumption['statement'] ?? null;
            if (! is_string($statement) || trim($statement) === '') {
                $errors[] = ['path' => $path.'.statement', 'message' => '假設理由不可空白'];
                $statement = '';
            }

            $impact = $assumption['impact'] ?? null;
            if ($impact === 'low') {
                $warnings[] = [
                    'path' => $path,
                    'message' => '低影響假設：'.trim($statement),
                ];
            } elseif ($impact === 'high') {
                $question = $assumption['question'] ?? null;
                if (! is_string($question) || trim($question) === '') {
                    $question = '請先釐清高影響假設：'.trim($statement);
                    $errors[] = ['path' => $path.'.question', 'message' => '高影響假設必須附上釐清問題'];
                }
                $blockingQuestions[] = [
                    'type' => 'assumption',
                    'reference' => is_string($schemaPath) ? $schemaPath : $path,
                    'question' => trim($question),
                ];
            } else {
                $errors[] = ['path' => $path.'.impact', 'message' => '假設影響程度只能是 low 或 high'];
            }
        }

        $unmappedFields = $this->findUnmappedFields($schema, $mappedPaths, $assumptionPaths);
        foreach ($schema['fields'] ?? [] as $field) {
            if (is_array($field) && ($field['archived'] ?? false) !== true) {
                $activeFieldCount++;
            }
        }
        foreach ($unmappedFields as $field) {
            $fieldIndex = substr($field['schema_path'], strlen('/fields/'));
            $errors[] = [
                'path' => 'fields.'.$fieldIndex,
                'message' => '未封存欄位缺少需求或假設依據，請補上對應或移除欄位',
            ];
        }

        $structural = $this->schemaValidator->validate($schema);
        $validation = [
            'valid' => $structural['valid'] && $errors === [],
            'errors' => array_merge($structural['errors'], $errors),
            'warnings' => array_merge($structural['warnings'], $warnings),
        ];

        return [
            'ready_to_apply' => $validation['valid'] && $blockingQuestions === [],
            'validation' => $validation,
            'coverage' => [
                'requirements' => count($requirements),
                'mapped' => $mappedCount,
                'unresolved' => $unresolvedCount,
                'assumptions' => count($assumptions),
                'active_fields' => $activeFieldCount,
                'unmapped_fields' => count($unmappedFields),
            ],
            'blocking_questions' => $blockingQuestions,
            'unmapped_schema_fields' => $unmappedFields,
        ];
    }

    /** 解析 RFC 6901 JSON Pointer，僅接受指向 Schema 既有值的路徑。 */
    private function pointerExists(array $schema, string $pointer): bool
    {
        if ($pointer === '' || ! str_starts_with($pointer, '/')) {
            return false;
        }

        $current = $schema;
        foreach (explode('/', substr($pointer, 1)) as $encodedToken) {
            if (preg_match('/~(?![01])/', $encodedToken) === 1) {
                return false;
            }

            $token = str_replace(['~1', '~0'], ['/', '~'], $encodedToken);
            if (! is_array($current)) {
                return false;
            }

            if (array_is_list($current)) {
                if (preg_match('/^(0|[1-9][0-9]*)$/', $token) !== 1) {
                    return false;
                }
                $token = (int) $token;
            }

            if (! array_key_exists($token, $current)) {
                return false;
            }
            $current = $current[$token];
        }

        return true;
    }

    /**
     * @return array<int, array{key: string, schema_path: string}>
     */
    private function findUnmappedFields(array $schema, array $mappedPaths, array $assumptionPaths): array
    {
        $unmapped = [];
        foreach ($schema['fields'] ?? [] as $index => $field) {
            if (! is_array($field) || ($field['archived'] ?? false) === true) {
                continue;
            }

            $fieldPath = '/fields/'.$index;
            $isCovered = false;
            foreach (array_merge($mappedPaths, $assumptionPaths) as $sourcePath) {
                if ($sourcePath === $fieldPath || str_starts_with($sourcePath, $fieldPath.'/')) {
                    $isCovered = true;

                    break;
                }
            }
            if (! $isCovered) {
                $unmapped[] = [
                    'key' => is_string($field['key'] ?? null) ? $field['key'] : (string) $index,
                    'schema_path' => $fieldPath,
                ];
            }
        }

        return $unmapped;
    }
}
