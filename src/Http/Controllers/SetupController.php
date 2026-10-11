<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Controllers;

use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\Models\DocAdmin;
use DocumentBuilder\Services\InstallWizard;
use DocumentBuilder\Services\SetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 網頁安裝精靈：環境檢查 → 帳號與版型 → 路由與整合 → 執行安裝 → 完成。不需要執行任何指令。
 * 安裝前：本機開發環境直接可用，其他環境要輸入 .env 的 DOCUMENT_BUILDER_SETUP_TOKEN。
 * 安裝後：只有超級管理員能再進來（調整設定、套用更新後的資料表、管理超級管理員名單）。
 */
class SetupController extends Controller
{
    private const SESSION = 'document_builder.install';

    public function __construct(private readonly SetupService $setup, private readonly InstallWizard $wizard) {}

    public function show(Request $request)
    {
        // 安裝後只有超級管理員能進來；一般使用者看到 404，套件更新後有資料表要套用時則告訴他找誰
        if ($this->locked() && ! DocumentBuilder::isSuperAdmin()) {
            abort_if($this->setup->installed(), 404);

            return view('document-builder::setup-pending');
        }

        // 表單預設值：session 裡精靈填過的值優先；中介層在 session 存成陣列，表單上顯示成逗號分隔
        $values = array_replace_recursive($this->wizard->defaults(), (array) $request->session()->get(self::SESSION.'.settings', []));
        $values['route']['middleware'] = implode(',', (array) $values['route']['middleware']);

        return view('document-builder::setup', [
            'checks' => $this->wizard->checks(),
            'guards' => $this->wizard->guards(),
            'integrations' => $this->wizard->integrations(),
            'values' => $values,
            'modelInfo' => $this->wizard->modelInfo(DocumentBuilder::userModel()),
            'installed' => $this->setup->installed() && $this->locked(),
            'pending' => $this->setup->pendingMigrations(),
            'locked' => $this->locked(),
            'hostDefines' => DocumentBuilder::hostDefinesSuperAdmin(),
            'isSuperAdmin' => DocumentBuilder::isSuperAdmin(),
            'needsToken' => ! $this->locked() && $this->setup->requiresToken() && ! $this->tokenAccepted($request),
            'tokenConfigured' => $this->setup->tokenConfigured(),
            'admins' => $this->setup->admins(),
            'userId' => DocumentBuilder::user()?->getAuthIdentifier(),
            'appUrl' => url('/'),
        ]);
    }

    /** Step 1：環境檢查；正式環境同時驗證初始化 token */
    public function check(Request $request): JsonResponse
    {
        if (! $this->locked() && $this->setup->requiresToken() && ! $this->tokenAccepted($request)) {
            $token = (string) $request->input('token');
            if (! $this->setup->canBootstrap($token)) {
                throw ValidationException::withMessages(['token' => $this->setup->tokenConfigured()
                    ? '初始化 token 不正確'
                    : '這個環境必須先在 .env 設定 DOCUMENT_BUILDER_SETUP_TOKEN']);
            }
            $request->session()->put(self::SESSION.'.token', $token);
        }
        $this->ensureAllowed($request);

        return response()->json(['checks' => $this->wizard->checks(), 'passed' => $this->wizard->checksPassed()]);
    }

    /** Step 2：選了使用者 Model 後帶出資料表、主鍵型別與欄位 */
    public function model(Request $request): JsonResponse
    {
        $this->ensureAllowed($request);
        $info = $this->wizard->modelInfo((string) $request->input('model'));
        abort_unless($info !== null, 404);

        return response()->json($info);
    }

    /** Step 2＋3 送出：驗證後暫存在 session，回傳設定檔預覽 */
    public function settings(Request $request): JsonResponse
    {
        $this->ensureAllowed($request);
        $settings = $this->wizard->validate($request->all());
        $request->session()->put(self::SESSION.'.settings', $settings);

        return response()->json([
            'config' => $this->wizard->configContent($settings),
            'url' => $this->wizard->setupUrl($settings),
        ]);
    }

    /** Step 4：依序執行 migrate（建立資料表）→ admin（設定第一位超級管理員）→ config（寫入設定檔） */
    public function run(Request $request, string $task): JsonResponse
    {
        $this->ensureAllowed($request);
        $settings = (array) $request->session()->get(self::SESSION.'.settings', []);
        // 套件更新後只需要建立新的資料表時，沿用目前的設定，不必重填前面的步驟
        abort_if($settings === [] && $task !== 'migrate', 422, '請先完成前面的步驟');
        $this->wizard->apply($settings);

        return match ($task) {
            'migrate' => response()->json(['ok' => true, 'log' => trim($this->setup->migrate()) ?: '資料表都已是最新']),
            'admin' => $this->runAdmin($request),
            'config' => $this->runConfig($settings),
            default => abort(404),
        };
    }

    /** Step 5：建立範本單據（樣品申請單），回傳設計器網址 */
    public function sample(): JsonResponse
    {
        abort_unless(DocumentBuilder::isSuperAdmin(), 403);
        $type = $this->wizard->createSample();

        return response()->json([
            'message' => "已建立範本「{$type->name}」",
            'url' => route(document_builder_route_name('document_builder.designer.edit'), $type),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin();

        return response()->json($this->setup->searchUsers(trim((string) $request->input('search'))));
    }

    public function addAdmin(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin();
        $this->setup->addAdmin($request->validate(['user_id' => ['required']])['user_id']);

        return response()->json(['message' => '已加入超級管理員']);
    }

    public function removeAdmin(DocAdmin $admin): JsonResponse
    {
        $this->ensureSuperAdmin();
        $this->setup->removeAdmin($admin);

        return response()->json(['message' => '已移除']);
    }

    private function runAdmin(Request $request): JsonResponse
    {
        if (DocumentBuilder::hostDefinesSuperAdmin()) {
            return response()->json(['ok' => true, 'log' => '超級管理員由專案的程式定義（document-builder.super-admin Gate），跳過']);
        }
        if (DocumentBuilder::isSuperAdmin()) {
            return response()->json(['ok' => true, 'log' => '你已經是超級管理員']);
        }
        $this->setup->claim(DocumentBuilder::user(), $request->session()->get(self::SESSION.'.token'));

        return response()->json(['ok' => true, 'log' => '已把你設為第一位超級管理員']);
    }

    private function runConfig(array $settings): JsonResponse
    {
        if ($this->wizard->writeConfig($settings)) {
            return response()->json(['ok' => true, 'log' => '已寫入 '.config_path('document_builder.php'), 'url' => $this->wizard->setupUrl($settings)]);
        }

        // 寫不進去：資料表與管理員都已完成，設定檔請開發人員存檔
        return response()->json([
            'ok' => false,
            'log' => 'config 目錄無法寫入，請把下面的內容存成 config/document_builder.php：',
            'config' => $this->wizard->configContent($settings),
            'url' => $this->wizard->setupUrl($settings),
        ]);
    }

    /** 已經有超級管理員（或由程式定義）就算完成安裝，精靈不再開放給一般人 */
    private function locked(): bool
    {
        return DocumentBuilder::hostDefinesSuperAdmin() || $this->setup->hasAdmins();
    }

    private function tokenAccepted(Request $request): bool
    {
        return $this->setup->canBootstrap((string) $request->session()->get(self::SESSION.'.token'));
    }


    /** 能不能執行精靈的動作：安裝後限超級管理員；安裝前要符合本機環境或 token */
    private function ensureAllowed(Request $request): void
    {
        $allowed = $this->locked() ? DocumentBuilder::isSuperAdmin() : $this->tokenAccepted($request);
        abort_unless($allowed, 403);
    }

    /** 名單只在套件自己管理超級管理員時能改；宿主用程式定義時以程式為準 */
    private function ensureSuperAdmin(): void
    {
        abort_if(DocumentBuilder::hostDefinesSuperAdmin(), 404);
        abort_unless(DocumentBuilder::isSuperAdmin(), 403);
    }
}
