<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\Models\DocAdmin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * 初始化頁的規則與動作：建立／更新套件的資料表、設定第一位超級管理員、管理超級管理員名單。
 * 全部在網頁上完成，不需要執行任何指令。
 */
class SetupService
{
    private const MIGRATIONS = __DIR__.'/../../database/migrations';

    /** @return array<int, string> 還沒執行的套件 migration（檔名，不含 .php） */
    public function pendingMigrations(): array
    {
        $files = collect(glob(self::MIGRATIONS.'/*.php') ?: [])->map(fn (string $f): string => basename($f, '.php'))->sort()->values();
        // Laravel 10 的設定是字串，Laravel 11 起是陣列（table 鍵）
        $setting = config('database.migrations', 'migrations');
        $table = is_array($setting) ? ($setting['table'] ?? 'migrations') : $setting;
        if (! Schema::hasTable($table)) {
            return $files->all();
        }
        $ran = DB::table($table)->whereIn('migration', $files)->pluck('migration')->all();

        return $files->diff($ran)->values()->all();
    }

    public function installed(): bool
    {
        return $this->pendingMigrations() === [];
    }

    /** 只執行套件自己的 migration，不碰宿主其他還沒跑的 migration */
    public function migrate(): string
    {
        Artisan::call('migrate', ['--path' => realpath(self::MIGRATIONS), '--realpath' => true, '--force' => true]);

        return Artisan::output();
    }

    public function hasAdmins(): bool
    {
        return Schema::hasTable('doc_admins') && DocAdmin::query()->exists();
    }

    /** 設定第一位超級管理員要不要輸入 token：本機開發環境（setup.open_environments）不用，其他環境要 */
    public function requiresToken(): bool
    {
        return ! app()->environment((array) config('document_builder.setup.open_environments', ['local']));
    }

    public function tokenConfigured(): bool
    {
        return (string) config('document_builder.setup.token') !== '';
    }

    /**
     * 還沒有任何超級管理員時，登入者能不能接手初始化（建立資料表、把自己設為第一位超級管理員）。
     * 宿主自己定義了超級管理員 Gate 時一律不行，以程式為準。
     */
    public function canBootstrap(?string $token): bool
    {
        if (DocumentBuilder::hostDefinesSuperAdmin() || $this->hasAdmins()) {
            return false;
        }
        if (! $this->requiresToken()) {
            return true;
        }

        return $this->tokenConfigured() && hash_equals((string) config('document_builder.setup.token'), (string) $token);
    }


    /** 把自己設為第一位超級管理員；資料表還沒建立時一併建立 */
    public function claim(Authenticatable $user, ?string $token): void
    {
        abort_if(DocumentBuilder::hostDefinesSuperAdmin() || $this->hasAdmins(), 403, '已經有超級管理員，請由超級管理員把你加入名單');
        if (! $this->canBootstrap($token)) {
            throw ValidationException::withMessages(['token' => $this->tokenConfigured()
                ? '初始化 token 不正確'
                : '這個環境必須先在 .env 設定 DOCUMENT_BUILDER_SETUP_TOKEN']);
        }
        if (! $this->installed()) {
            $this->migrate();
        }

        DocAdmin::query()->create(['user_id' => $user->getAuthIdentifier()]);
    }

    /** @return \Illuminate\Support\Collection<int, DocAdmin> */
    public function admins()
    {
        return Schema::hasTable('doc_admins') ? DocAdmin::query()->with('user')->orderBy('id')->get() : collect();
    }

    public function addAdmin(int|string $userId): DocAdmin
    {
        $model = DocumentBuilder::userModel();
        if (! $model::query()->whereKey($userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => '找不到這個使用者']);
        }

        return DocAdmin::query()->firstOrCreate(['user_id' => $userId]);
    }

    public function removeAdmin(DocAdmin $admin): void
    {
        if (DocAdmin::query()->count() <= 1) {
            throw ValidationException::withMessages(['admin' => '必須至少保留一位超級管理員']);
        }
        $admin->delete();
    }

    /** 使用者搜尋（加入超級管理員用）：比對名稱欄位與 email */
    public function searchUsers(string $term): array
    {
        $model = DocumentBuilder::userModel();
        $name = DocumentBuilder::userNameColumn();
        $table = DocumentBuilder::usersTable();
        $columns = array_values(array_unique(array_filter([$name, Schema::hasColumn($table, 'email') ? 'email' : null])));

        return $model::query()
            ->when($term !== '', function ($q) use ($columns, $term): void {
                $q->where(function ($w) use ($columns, $term): void {
                    foreach ($columns as $column) {
                        $w->orWhere($column, 'like', "%{$term}%");
                    }
                });
            })
            ->limit(20)
            ->get()
            ->map(fn (Model $user): array => [
                'id' => $user->getKey(),
                'name' => trim(DocumentBuilder::userName($user).(in_array('email', $columns, true) && $name !== 'email' ? '（'.$user->getAttribute('email').'）' : '')),
            ])
            ->all();
    }


    /** 資料表若在使用者主鍵型別設定好之前就建立了，指向使用者的欄位型別會不對 */
    public function userColumnMismatch(): bool
    {
        if (! Schema::hasTable('doc_records')) {
            return false;
        }
        $isInteger = str_contains(strtolower(Schema::getColumnType('doc_records', 'created_by')), 'int');

        return $isInteger !== (DocumentBuilder::userKeyType() === 'int');
    }

}
