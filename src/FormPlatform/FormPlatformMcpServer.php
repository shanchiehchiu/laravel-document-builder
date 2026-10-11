<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use Mcp\Schema\Annotations;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** 將 Laravel 後台請求接到官方 MCP Streamable HTTP transport。 */
class FormPlatformMcpServer
{
    public function handle(Request $request): Response
    {
        $psrRequest = (new PsrHttpFactory)->createRequest($request);
        $transport = new StreamableHttpTransport($psrRequest, middleware: $this->middleware());
        $psrResponse = $this->server()->run($transport);

        return (new HttpFoundationFactory)->createResponse($psrResponse);
    }

    /** 以本機 stdio 啟動，讓 Codex 不必轉送瀏覽器登入 Cookie 或 Token。 */
    public function runStdio(): int
    {
        return $this->server(stdio: true)->run(new StdioTransport);
    }

    private function server(bool $stdio = false): Server
    {
        $readOnly = new ToolAnnotations(readOnlyHint: true, destructiveHint: false);
        $draftWrite = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, openWorldHint: false);
        app()->bind(FormPlatformMcpTools::class);
        app()->bind(FormPlatformMcpResources::class);
        $builder = Server::builder()
            ->setServerInfo('Document Builder Form Platform', '0.1.0', '受控的表單結構查詢、驗證與預覽工具')
            ->setInstructions('先讀取 form-platform://validation-rules/form-request-v1，從使用者原始提示詞整理逐項需求與明確假設，再呼叫 validate_form_requirements 檢查需求覆蓋和 Schema。若有阻擋問題，先向使用者釐清；驗證通過後才預覽或套用草稿。這項驗證能檢查引用、路徑與結構，不會代替 AI 判斷原文的語意。')
            ->setContainer(app())
            ->setSession($stdio
                ? new InMemorySessionStore(3600)
                : new Psr16SessionStore(cache()->store(), 'form-platform-mcp-', 3600));

        $builder
            ->addResource(
                [FormPlatformMcpResources::class, 'componentCatalog'],
                'form-platform://components',
                'components',
                '表單元件與欄位型別規格',
                '已登錄的版面元件、欄位型別、允許屬性與限制。',
                'application/json',
                annotations: new Annotations(priority: 0.9),
            )
            ->addResource(
                [FormPlatformMcpResources::class, 'resourceCatalog'],
                'form-platform://data-sources',
                'data-sources',
                '可綁定資料來源',
                '可供表單使用的唯讀主檔資源及搜尋契約。',
                'application/json',
                annotations: new Annotations(priority: 0.8),
            )
            ->addResource(
                [FormPlatformMcpResources::class, 'schemaTemplate'],
                'form-platform://schema/v1',
                'schema-v1-template',
                '表單 Schema v1 範本',
                '建立停用草稿時可複製的完整 Schema 範本，狀態定義由平台固定提供。',
                'application/json',
                annotations: new Annotations(priority: 0.9),
            )
            ->addResource(
                [FormPlatformMcpResources::class, 'requirementValidationRules'],
                'form-platform://validation-rules/form-request-v1',
                'form-request-validation-v1',
                '表單需求檢查規則',
                '引導 AI 將使用者原文拆成可追溯需求、標示假設，並在套用草稿前檢查需求覆蓋。',
                'application/json',
                annotations: new Annotations(priority: 1.0),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'listComponents'],
                'list_components',
                '列出表單元件',
                '列出可用的版面元件與欄位型別契約。',
                $readOnly,
                self::emptyInputSchema(),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'getComponentSchema'],
                'get_component_schema',
                '讀取元件規格',
                '取得指定版面元件或欄位型別的受控屬性契約。',
                $readOnly,
                self::objectInputSchema([
                    'component_type' => ['type' => 'string', 'minLength' => 1],
                ], ['component_type']),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'getForm'],
                'get_form',
                '讀取表單 Schema',
                '依目前後台使用者的單據權限讀取表單 Schema v1。',
                $readOnly,
                self::objectInputSchema([
                    'form_code' => ['type' => 'string', 'minLength' => 1],
                ], ['form_code']),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'validateDraft'],
                'validate_draft',
                '驗證目前草稿',
                '讀取建立者目前的工作草稿版本並驗證，不需重新傳送完整 Schema。',
                $readOnly,
                self::objectInputSchema([
                    'form_code' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 30],
                ], ['form_code']),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'previewDraft'],
                'preview_draft',
                '預覽目前草稿',
                '使用正式 Runtime renderer 預覽建立者目前的工作草稿，不建立正式單據。',
                $readOnly,
                self::objectInputSchema([
                    'form_code' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 30],
                ], ['form_code']),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'validateForm'],
                'validate_form',
                '驗證表單 Schema',
                '依指定單據代碼驗證 Schema，不儲存任何資料。',
                $readOnly,
                self::schemaInputSchema(),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'previewForm'],
                'preview_form',
                '預覽表單',
                '以正式 Runtime renderer 產生安全預覽，不建立正式單據。',
                $readOnly,
                self::schemaInputSchema(),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'validateSchema'],
                'validate_schema',
                '驗證未儲存 Schema',
                '在建立或更新草稿前驗證完整 Schema，不讀取或寫入資料庫資料。',
                $readOnly,
                self::schemaInputSchema(),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'validateFormRequirements'],
                'validate_form_requirements',
                '檢查提示詞需求覆蓋',
                '比對使用者原始提示詞、逐項需求、明確假設與完整 Schema。檢查原文摘錄、Schema JSON Pointer、未對應欄位及結構規則；不會自行理解或補猜需求。',
                $readOnly,
                self::formRequirementsInputSchema(),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'previewSchema'],
                'preview_schema',
                '預覽未儲存 Schema',
                '在建立或更新草稿前用正式 Runtime renderer 預覽完整 Schema，不建立單據或寫入資料。',
                $readOnly,
                self::schemaInputSchema(),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'listResources'],
                'list_resources',
                '列出可用資源',
                '列出表單可綁定的受控唯讀資源，不公開後端資料表資訊。',
                $readOnly,
                self::emptyInputSchema(),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'getResourceSchema'],
                'get_resource_schema',
                '讀取資源規格',
                '取得指定資源的公開欄位與搜尋上限。',
                $readOnly,
                self::objectInputSchema([
                    'resource_code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                ], ['resource_code']),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'searchResource'],
                'search_resource',
                '搜尋表單資源',
                '只搜尋目前使用者可檢視且已綁定該主檔資源的表單；回傳欄位僅含識別碼與顯示名稱。',
                $readOnly,
                self::objectInputSchema([
                    'form_code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                    'resource_code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                    'query' => ['type' => 'string', 'maxLength' => 100],
                ], ['form_code', 'resource_code', 'query']),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'createForm'],
                'create_form',
                '建立表單草稿',
                '建立停用的表單草稿；只有超級管理員可以呼叫，請提供不重複的冪等鍵。',
                $draftWrite,
                self::objectInputSchema([
                    'form_code' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 30, 'pattern' => '^[a-z][a-z0-9_]{1,29}$'],
                    'form_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                    'idempotency_key' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 128],
                ], ['form_code', 'form_name', 'idempotency_key']),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'applyFormPlan'],
                'apply_form_plan',
                '原子套用表單草稿',
                '一次驗證並套用完整 Schema v1；新表單會建立為停用草稿，既有草稿需提供 expected_revision。操作具冪等性。',
                $draftWrite,
                self::objectInputSchema([
                    'form_code' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 30],
                    'schema' => ['type' => 'object'],
                    'idempotency_key' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 128],
                    'expected_revision' => ['type' => 'integer', 'minimum' => 1],
                ], ['form_code', 'schema', 'idempotency_key']),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'addComponent'],
                'add_component',
                '加入表單元件',
                '在建立者自己的停用草稿末尾新增欄位、標題、說明、空白格或明細表；欄位可指定 section=item 與 table_key 加入明細表，數字明細欄可設定 options.sum；只接受登錄型別及受控屬性，並附冪等鍵。',
                $draftWrite,
                self::objectInputSchema([
                    'form_code' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 30],
                    'component_type' => ['type' => 'string', 'enum' => ['field', 'heading', 'note', 'spacer', 'table']],
                    'props' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'field_type' => ['type' => 'string', 'enum' => array_keys(config('document_builder.field_types'))],
                            'section' => ['type' => 'string', 'enum' => ['header', 'item']],
                            'table_key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,29}$'],
                            'required' => ['type' => 'boolean'],
                            'width' => ['type' => 'integer', 'enum' => array_map('intval', array_keys(config('document_builder.widths')))],
                            'options' => ['type' => 'object'],
                            'show_in_list' => ['type' => 'boolean'],
                            'text' => ['type' => 'string'],
                            'bg' => ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$'],
                            'border' => ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$'],
                            'title' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'idempotency_key' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 128],
                ], ['form_code', 'component_type', 'props', 'idempotency_key']),
            )
            ->addTool(
                [FormPlatformMcpTools::class, 'setFormEvents'],
                'set_form_events',
                '設定欄位連動事件',
                '替換建立者自己的停用草稿事件清單。只接受 field.changed、equals/not_equals/is_empty 條件與 set_value 固定值動作；請附管理員範圍的冪等鍵。',
                $draftWrite,
                self::eventInputSchema(),
            );

        return $builder->build();
    }

    /** MCP SDK 預設只允許 localhost；正式網址需明確加入允許主機清單。 */
    private function middleware(): array
    {
        $applicationHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $configuredHosts = config('form_platform.mcp.allowed_hosts', []);
        $allowedHosts = array_values(array_unique(array_filter([
            'localhost',
            '127.0.0.1',
            '[::1]',
            is_string($applicationHost) ? $applicationHost : null,
            ...$configuredHosts,
        ])));

        return [new CorsMiddleware, new DnsRebindingProtectionMiddleware($allowedHosts)];
    }

    private static function emptyInputSchema(): array
    {
        return self::objectInputSchema([], []);
    }

    private static function objectInputSchema(array $properties, array $required): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    private static function schemaInputSchema(): array
    {
        return self::objectInputSchema([
            'form_code' => ['type' => 'string', 'minLength' => 1],
            'schema' => ['type' => 'object'],
        ], ['form_code', 'schema']);
    }

    private static function formRequirementsInputSchema(): array
    {
        $requirement = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                'statement' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'source_quote' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                'status' => ['type' => 'string', 'enum' => ['mapped', 'unresolved']],
                'schema_paths' => [
                    'type' => 'array',
                    'uniqueItems' => true,
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                ],
                'question' => ['type' => 'string', 'maxLength' => 1000],
            ],
            'required' => ['id', 'statement', 'source_quote', 'status', 'schema_paths'],
            'additionalProperties' => false,
        ];
        $assumption = [
            'type' => 'object',
            'properties' => [
                'schema_path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'statement' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'impact' => ['type' => 'string', 'enum' => ['low', 'high']],
                'question' => ['type' => 'string', 'maxLength' => 1000],
            ],
            'required' => ['schema_path', 'statement', 'impact'],
            'additionalProperties' => false,
        ];

        return self::objectInputSchema([
            'original_prompt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 10000],
            'requirements' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => $requirement],
            'assumptions' => ['type' => 'array', 'maxItems' => 100, 'items' => $assumption],
            'schema' => ['type' => 'object'],
        ], ['original_prompt', 'requirements', 'assumptions', 'schema']);
    }

    private static function eventInputSchema(): array
    {
        $event = [
            'type' => 'object',
            'properties' => [
                'trigger' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['field.changed']],
                        'field' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,49}$'],
                    ],
                    'required' => ['type', 'field'],
                    'additionalProperties' => false,
                ],
                'condition' => [
                    'type' => 'object',
                    'properties' => [
                        'operator' => ['type' => 'string', 'enum' => ['equals', 'not_equals', 'is_empty']],
                        'value' => [],
                    ],
                    'required' => ['operator'],
                    'additionalProperties' => false,
                ],
                'actions' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 5,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['set_value']],
                            'field' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,49}$'],
                            'value' => [],
                        ],
                        'required' => ['type', 'field', 'value'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['trigger', 'actions'],
            'additionalProperties' => false,
        ];

        return self::objectInputSchema([
            'form_code' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 30],
            'events' => ['type' => 'array', 'maxItems' => 20, 'items' => $event],
            'idempotency_key' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 128],
        ], ['form_code', 'events', 'idempotency_key']);
    }
}
