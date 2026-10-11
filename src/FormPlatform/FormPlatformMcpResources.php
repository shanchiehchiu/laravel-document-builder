<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use DocumentBuilder\Models\DocType;
use DocumentBuilder\Services\DocTypeService;

/** 提供 AI 可直接讀取的元件、資料來源與 Schema 範本資源。 */
class FormPlatformMcpResources
{
    public function __construct(
        private readonly ComponentRegistry $components,
        private readonly ResourceRegistry $resources,
        private readonly DocTypeService $types,
    ) {}

    /** 回傳不含 renderer 內部資訊的元件登錄資料。 */
    public function componentCatalog(string $uri): array
    {
        $listing = $this->components->listing();

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

    /** 回傳可綁定主檔的公開契約，不含 Model 或資料表名稱。 */
    public function resourceCatalog(string $uri): array
    {
        return ['resources' => $this->resources->listing()];
    }

    /** 回傳可複製後提交給 apply_form_plan 的 Schema v1 範本。 */
    public function schemaTemplate(string $uri): array
    {
        $statuses = array_map(static fn (array $status): array => [
            'code' => $status['code'],
            'name' => $status['name'],
            'is_default' => $status['is_default'],
            'locks_editing' => $status['locks_editing'],
            'color' => $status['color'],
            'order' => $status['seq'],
        ], $this->types->defaultStatuses());

        return [
            'schema_version' => (int) config('form_platform.schema_version'),
            'form' => [
                'code' => 'replace_with_form_code',
                'name' => '請填入單據名稱',
                'enabled' => false,
                'order' => 0,
                'buttons' => array_fill_keys(array_keys(DocType::BUTTONS), true),
                'numbering' => ['prefix' => '', 'date_format' => 'Ymd', 'sequence_digits' => 3],
            ],
            'layout' => ['rows' => [], 'tables' => []],
            'fields' => [],
            'statuses' => $statuses,
            'bindings' => [],
            'events' => [],
        ];
    }

    /** 提供 AI 從提示詞整理需求與呼叫檢查工具的明確規則。 */
    public function requirementValidationRules(string $uri): array
    {
        return [
            'version' => 1,
            'tool' => 'validate_form_requirements',
            'workflow' => [
                '保留使用者提示詞全文，原樣傳入 original_prompt。',
                '將每個可獨立驗收的要求拆成一筆 requirement，不得把多個要求合併成籠統描述。',
                '每筆 requirement 的 source_quote 必須是提示詞中的原文片段，statement 則以繁體中文整理該要求。',
                '已反映在 Schema 的要求標記 mapped，並以 RFC 6901 JSON Pointer 指向一個或多個 Schema 路徑。',
                '目前無法反映或含意不明的要求標記 unresolved，提出能讓使用者選擇的具體問題。',
                '每個由 AI 推定、且會影響 Schema 的決定都列為 assumption，指出 Schema 路徑、影響程度與理由。',
                '低影響假設會列為警告；高影響假設會阻擋套用，並要求提出釐清問題。',
                '呼叫檢查工具後，先處理 blocking_questions；ready_to_apply 為 true 才進入預覽或套用草稿。',
            ],
            'requirement_shape' => [
                'id' => '此份檢查中唯一且穩定的需求識別碼。',
                'statement' => '一項可單獨驗收的需求。',
                'source_quote' => '使用者提示詞中的精確原文摘錄。',
                'status' => ['mapped', 'unresolved'],
                'schema_paths' => 'mapped 時至少一個有效 JSON Pointer；unresolved 時必須是空陣列。',
                'question' => 'unresolved 時必填，且必須能直接詢問使用者。',
            ],
            'assumption_shape' => [
                'schema_path' => '受此假設影響且存在於 Schema 的 JSON Pointer。',
                'statement' => '清楚描述原文未指定、由 AI 推定的內容。',
                'impact' => ['low', 'high'],
                'question' => '高影響假設必填；低影響假設可以省略。',
            ],
            'json_pointer' => [
                'format' => 'RFC 6901；路徑以 / 開頭，陣列索引用十進位索引。',
                'escape' => '物件鍵中的 ~ 寫成 ~0，/ 寫成 ~1。',
                'examples' => ['/form/name', '/fields/0/label', '/layout/rows/0/cols/0/component/key'],
            ],
            'checks' => [
                'requirements 中的 source_quote 是否為 original_prompt 的原文子字串。',
                '每個 mapped requirement 和 assumption 的 Schema 路徑是否存在。',
                '每個未封存欄位是否至少對應一筆 mapped requirement 或 assumption。',
                'Schema 是否通過 Form Schema v1 的結構、元件白名單與事件規則驗證。',
            ],
            'limits' => [
                'MCP 工具不會自行解讀自然語言，也無法證明 AI 拆出的需求完整；AI 必須逐句檢查原文並忠實列出需求。',
                '結構驗證通過只代表 Schema 符合平台契約，不代表原始需求的語意已全部滿足。',
                '使用者明確要求的內容不得改列為假設；無法判定時應標記 unresolved 並詢問。',
            ],
        ];
    }
}
