<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use DocumentBuilder\Contracts\Integration;
use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\Integrations\NullIntegration;
use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * 網頁安裝精靈：環境檢查、帳號與版型、路由與整合、寫出設定檔、範本單據。
 * 精靈收集的設定先存在 session，執行安裝時才套用並寫成 config/document_builder.php。
 */
class InstallWizard
{
    /** 精靈可以設定的鍵（其餘設定維持既有設定檔或套件預設） */
    public const KEYS = ['guard', 'user_model', 'users_table', 'user_key_type', 'user_name_column', 'layout', 'route', 'integration'];

    public function __construct(private readonly SetupService $setup, private readonly DocTypeService $types, private readonly DocFieldService $fields) {}

    /** Step 1：環境檢查。level：ok 通過、error 必須修正、warn 可以繼續但有影響 */
    public function checks(): array
    {
        $checks = [];
        $checks[] = $this->check('PHP 版本', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION, '必須是 PHP 8.1 以上');
        foreach (['pdo', 'mbstring', 'json'] as $extension) {
            $checks[] = $this->check("PHP 擴充 {$extension}", extension_loaded($extension), extension_loaded($extension) ? '已載入' : '未載入', "請安裝或啟用 {$extension} 擴充");
        }

        try {
            DB::connection()->getPdo();
            $checks[] = $this->check('資料庫連線', true, DB::connection()->getDriverName().'（'.DB::connection()->getDatabaseName().'）', '');
        } catch (Throwable $e) {
            $checks[] = $this->check('資料庫連線', false, '無法連線', '請確認 .env 的 DB_ 設定：'.$e->getMessage());
        }

        $checks[] = $this->check('storage 目錄可寫入', is_writable(storage_path()), storage_path(), '請讓網頁伺服器可以寫入 storage 目錄');

        $configWritable = is_file(config_path('document_builder.php')) ? is_writable(config_path('document_builder.php')) : is_writable(config_path());
        $checks[] = $this->check('config 目錄可寫入', $configWritable, config_path(), '無法自動寫入設定檔；安裝時會顯示設定內容，請交給開發人員存成 config/document_builder.php', 'warn');
        $checks[] = $this->check('前端資產', true, '由套件直接提供，不需要發布到 public', '');
        if ($this->setup->userColumnMismatch()) {
            $checks[] = $this->check('使用者欄位型別', false, '資料表的使用者欄位和使用者主鍵型別（'.DocumentBuilder::userKeyType().'）不一致',
                '單據建置的資料表是用另一種主鍵型別建立的；還沒有單據資料時，請開發人員移除單據建置的資料表後重新安裝');
        }

        return $checks;
    }

    public function checksPassed(): bool
    {
        return collect($this->checks())->doesntContain('level', 'error');
    }

    /** Step 2：可選的 guard，以及每個 guard 對應的使用者 model 推測值 */
    public function guards(): array
    {
        return collect((array) config('auth.guards', []))
            ->map(function (array $guard, string $name): array {
                $model = (string) config('auth.providers.'.($guard['provider'] ?? '').'.model', '');

                return ['name' => $name, 'model' => $model, 'logged_in' => auth($name)->check()];
            })
            ->filter(fn (array $guard): bool => $guard['model'] !== '' && class_exists($guard['model']))
            ->values()
            ->all();
    }

    /** 某個 model 的資料表、主鍵型別與欄位（給名稱欄位下拉） */
    public function modelInfo(string $model): ?array
    {
        if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return null;
        }
        /** @var Model $instance */
        $instance = new $model;
        $table = $instance->getTable();
        $traits = class_uses_recursive($model);
        $keyType = in_array(HasUlids::class, $traits, true) ? 'ulid'
            : (in_array(HasUuids::class, $traits, true) || $instance->getKeyType() !== 'int' ? 'uuid' : 'int');
        $columns = Schema::hasTable($table)
            ? array_values(array_diff(Schema::getColumnListing($table), $instance->getHidden()))
            : [];

        return [
            'table' => $table,
            'key_type' => $keyType,
            'columns' => $columns,
            'name_column' => collect(['name', 'email'])->first(fn (string $c): bool => in_array($c, $columns, true)) ?? $instance->getKeyName(),
        ];
    }

    /** Step 3：宿主專案裡實作 Integration 的類別（例如接自家後台選單），加上套件內建的 NullIntegration */
    public function integrations(): array
    {
        $found = [NullIntegration::class => '獨立運作：不自動加入宿主選單（建議）'];
        $path = app_path();
        if (is_dir($path)) {
            $namespace = app()->getNamespace();
            foreach (Finder::create()->files()->in($path)->name('*.php')->contains('Contracts\\Integration') as $file) {
                $class = $namespace.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
                if (class_exists($class) && is_subclass_of($class, Integration::class) && ! (new ReflectionClass($class))->isAbstract()) {
                    $found[$class] = class_basename($class).'：專案自己的後台選單與權限整合';
                }
            }
        }

        return $found;
    }

    /** 精靈表單的預設值：既有設定或自動偵測的結果 */
    public function defaults(): array
    {
        $route = (array) config('document_builder.route');

        return [
            'guard' => DocumentBuilder::guard(),
            'user_model' => DocumentBuilder::userModel(),
            'users_table' => DocumentBuilder::usersTable(),
            'user_key_type' => DocumentBuilder::userKeyType(),
            'user_name_column' => DocumentBuilder::userNameColumn(),
            'layout' => (string) config('document_builder.layout'),
            'route' => [
                'prefix' => (string) ($route['prefix'] ?? ''),
                'name' => (string) ($route['name'] ?? ''),
                'middleware' => implode(',', (array) ($route['middleware'] ?? ['web', 'auth'])),
            ],
            'integration' => (string) config('document_builder.integration'),
        ];
    }

    /** Step 2＋3 送出的設定：驗證後回傳整理好的值 */
    public function validate(array $input): array
    {
        $guards = collect($this->guards())->pluck('name')->all();
        $data = Validator::make($input, [
            'guard' => ['required', 'in:'.implode(',', $guards)],
            'user_model' => ['required', 'string'],
            'users_table' => ['required', 'string', 'max:64'],
            'user_key_type' => ['required', 'in:int,uuid,ulid'],
            'user_name_column' => ['required', 'string', 'max:64'],
            'layout' => ['required', 'string', 'max:200'],
            'route.prefix' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_\/-]*$/'],
            'route.name' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_.]*$/'],
            'route.middleware' => ['required', 'string', 'max:200'],
            'integration' => ['required', 'in:'.implode(',', array_keys($this->integrations()))],
        ], [], [
            'guard' => '登入 Guard', 'user_model' => '使用者 Model', 'users_table' => '使用者資料表',
            'user_key_type' => '主鍵型別', 'user_name_column' => '名稱欄位', 'layout' => '頁面版型',
            'route.prefix' => '路由前綴', 'route.name' => '路由名稱前綴', 'route.middleware' => '中介層', 'integration' => '選單整合',
        ])->after(function ($validator) use ($input): void {
            if (! $this->modelInfo((string) ($input['user_model'] ?? ''))) {
                $validator->errors()->add('user_model', '找不到這個使用者 Model');
            }
            $layout = (string) ($input['layout'] ?? '');
            if ($layout !== '' && ! View::exists($layout)) {
                $validator->errors()->add('layout', "找不到版型 {$layout}");
            }
            $guard = (string) ($input['guard'] ?? '');
            if ($guard !== '' && array_key_exists($guard, (array) config('auth.guards')) && ! auth($guard)->check()) {
                $validator->errors()->add('guard', "目前沒有用 {$guard} 登入；請先用這個 guard 的帳號登入，安裝完成後你會成為第一位超級管理員");
            }
        })->validate();

        $data['route']['prefix'] = trim((string) ($data['route']['prefix'] ?? ''), '/');
        $data['route']['name'] = (string) ($data['route']['name'] ?? '');
        $data['route']['middleware'] = array_values(array_filter(array_map('trim', explode(',', $data['route']['middleware']))));

        return collect($data)->only(self::KEYS)->all();
    }

    /** 把精靈的設定套用到這次請求（安裝時 migration 要用新的主鍵型別、認領要用新的 guard） */
    public function apply(array $settings): void
    {
        foreach ($settings as $key => $value) {
            config()->set("document_builder.{$key}", $value);
        }
    }

    /** 安裝完成後，精靈在新路由設定下的網址 */
    public function setupUrl(array $settings): string
    {
        $prefix = trim((string) ($settings['route']['prefix'] ?? ''), '/');

        return url(($prefix !== '' ? $prefix.'/' : '').'document_builder/setup');
    }

    /**
     * 設定檔內容：保留既有設定檔的其他鍵，加上精靈的設定。
     * setup 鍵不寫入（token 留在 .env，由套件預設讀取），其餘和套件預設相同的鍵也不寫，檔案保持精簡。
     */
    public function configContent(array $settings): string
    {
        $path = config_path('document_builder.php');
        $existing = is_file($path) ? (array) require $path : [];
        $defaults = (array) require __DIR__.'/../../config/document_builder.php';
        $merged = array_merge($existing, $settings);
        unset($merged['setup']);
        $merged = array_filter($merged, fn ($value, $key) => array_key_exists($key, $existing) || ($defaults[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);

        return "<?php\n\n// 由單據建置的安裝精靈產生；沒有寫在這裡的設定沿用套件預設（見套件的 config/document_builder.php）\n\nreturn "
            .$this->export($merged).";\n";
    }

    /** 寫入設定檔；寫不進去時回傳 false，畫面改為顯示內容讓開發人員自行存檔 */
    public function writeConfig(array $settings): bool
    {
        $path = config_path('document_builder.php');
        $writable = is_file($path) ? is_writable($path) : is_writable(dirname($path));
        if (! $writable) {
            return false;
        }

        file_put_contents($path, $this->configContent($settings));
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        // 設定有快取時清掉，下一個請求才會讀到新的設定檔
        if (app()->configurationIsCached()) {
            Artisan::call('config:clear');
        }

        return true;
    }

    /** 範本單據：建立「樣品申請單」與幾個常用欄位，讓使用者直接進設計器看效果 */
    public function createSample(): DocType
    {
        $code = 'sample';
        for ($i = 2; DocType::query()->where('code', $code)->exists(); $i++) {
            $code = "sample_{$i}";
        }

        $type = $this->types->create(['code' => $code, 'name' => '樣品申請單']);
        $fields = [
            ['key' => 'sample_name', 'label' => '樣品名稱', 'type' => 'text', 'required' => true, 'show_in_list' => true, 'width' => 6],
            ['key' => 'quantity', 'label' => '數量', 'type' => 'number', 'decimals' => 0, 'required' => true, 'show_in_list' => true, 'width' => 3],
            ['key' => 'need_date', 'label' => '需求日期', 'type' => 'date', 'width' => 3],
            ['key' => 'purpose', 'label' => '用途', 'type' => 'select', 'choices' => "試用\n展示\n檢驗", 'show_in_list' => true, 'width' => 4],
            ['key' => 'need_return', 'label' => '需歸還', 'type' => 'checkbox', 'width' => 4],
            ['key' => 'note', 'label' => '備註', 'type' => 'textarea', 'width' => 12],
            // 其餘型別：金額、單選、複選。主檔下拉需要來源，範本不假設來源存在，因此不放
            ['key' => 'unit_price', 'label' => '單價', 'type' => 'amount', 'decimals' => 2, 'currency' => 'NT$', 'width' => 4],
            ['key' => 'priority', 'label' => '優先度', 'type' => 'radio', 'choices' => "一般\n急件", 'layout' => 'horizontal', 'width' => 4],
            ['key' => 'tags', 'label' => '標籤', 'type' => 'checkgroup', 'choices' => "需回覆\n需寄回\n已確認", 'layout' => 'horizontal', 'width' => 4],
            ['key' => 'destinations', 'label' => '寄送地點', 'type' => 'multiselect', 'choices' => "北部\n中部\n南部\n東部", 'width' => 6],
        ];
        foreach ($fields as $field) {
            $this->fields->create($type, $field + ['section' => DocField::SECTION_HEADER]);
        }

        return $type->fresh();
    }

    private function check(string $label, bool $ok, string $value, string $fix, string $failLevel = 'error'): array
    {
        return ['label' => $label, 'level' => $ok ? 'ok' : $failLevel, 'value' => $value, 'fix' => $ok ? '' : $fix];
    }

    /** 輸出成短陣列語法、縮排四格的 PHP，方便閱讀 */
    private function export(mixed $value, int $depth = 1): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }
        if ($value === []) {
            return '[]';
        }
        $pad = str_repeat('    ', $depth);
        $isList = array_is_list($value);
        $lines = [];
        foreach ($value as $key => $item) {
            $lines[] = $pad.($isList ? '' : var_export($key, true).' => ').$this->export($item, $depth + 1).',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth - 1).']';
    }
}
