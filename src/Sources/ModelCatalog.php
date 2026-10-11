<?php

declare(strict_types=1);

namespace DocumentBuilder\Sources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * 來源庫裡「資料模型」可以選哪些 model、哪些欄位。
 * 預設自動找出宿主 app/Models 底下的 Eloquent model，不用改宿主程式；
 * 欄位排除 model 的 $hidden（例如 password）與設定裡的敏感欄位，管理員碰不到這些欄位。
 */
class ModelCatalog
{
    /** @return array<class-string<Model>, string> model 類別 => 顯示名稱（類別短名） */
    public function models(): array
    {
        $configured = config('document_builder.sources.models');
        $classes = is_array($configured) ? $configured : $this->discover();

        return collect($classes)
            ->filter(fn (string $class): bool => $this->isModel($class))
            ->mapWithKeys(fn (string $class): array => [$class => class_basename($class)])
            ->sort()
            ->all();
    }

    public function allows(string $class): bool
    {
        return array_key_exists($class, $this->models());
    }

    /** @return array<int, string> 這個 model 可以用的欄位（排除隱藏與敏感欄位） */
    public function columns(string $class): array
    {
        if (! $this->allows($class)) {
            return [];
        }

        /** @var Model $model */
        $model = new $class;
        $hidden = array_merge($model->getHidden(), (array) config('document_builder.sources.hidden_columns', []));

        return collect(Schema::connection($model->getConnectionName())->getColumnListing($model->getTable()))
            ->reject(fn (string $column): bool => in_array($column, $hidden, true))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function discover(): array
    {
        $path = app_path('Models');
        if (! is_dir($path)) {
            return [];
        }

        $namespace = app()->getNamespace().'Models\\';
        $classes = [];
        foreach (Finder::create()->files()->in($path)->name('*.php') as $file) {
            $relative = Str::beforeLast($file->getRelativePathname(), '.php');
            $classes[] = $namespace.str_replace('/', '\\', $relative);
        }

        return $classes;
    }

    private function isModel(string $class): bool
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return false;
        }

        return ! (new ReflectionClass($class))->isAbstract();
    }
}
