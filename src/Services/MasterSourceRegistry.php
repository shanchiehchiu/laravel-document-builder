<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use DocumentBuilder\FormPlatform\ResourceRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 主檔下拉的資料來源，只允許 config/document_builder.php 白名單內的來源
 */
class MasterSourceRegistry
{
    public function __construct(private readonly ResourceRegistry $resources) {}

    /** @return array<string, string> 來源代碼 => 顯示名稱 */
    public function options(): array
    {
        return $this->resources->options();
    }

    public function has(string $source): bool
    {
        return $this->resources->has($source);
    }

    /**
     * 下拉搜尋，回傳格式配合全域 .js-select2[data-url]：[{id, name}]
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function search(string $source, ?string $term): array
    {
        $def = $this->definition($source);
        $term = trim((string) $term);

        return $this->baseQuery($def)
            ->when($def['active'] !== [], fn (Builder $q) => $q->where($def['active']))
            ->when($term !== '', function (Builder $q) use ($def, $term): void {
                $q->where(fn (Builder $w) => $w
                    ->where($def['code'], 'like', "%{$term}%")
                    ->orWhere($def['text'], 'like', "%{$term}%"));
            })
            ->orderBy($def['code'])
            ->limit((int) config('document_builder.master_search_limit'))
            ->get(['id', $def['code'], $def['text']])
            ->map(fn (Model $m): array => ['id' => (int) $m->getKey(), 'name' => $this->labelOf($def, $m)])
            ->all();
    }

    /**
     * 依 id 取得 {id, label}；查無資料回傳 null。
     * 不套用 active 條件：舊單據引用到已停用的資料仍可存檔。
     *
     * @return array{id: int, label: string}|null
     */
    public function find(string $source, int $id): ?array
    {
        $def = $this->definition($source);
        $model = $this->baseQuery($def)->whereKey($id)->first(['id', $def['code'], $def['text']]);

        return $model ? ['id' => (int) $model->getKey(), 'label' => $this->labelOf($def, $model)] : null;
    }

    private function definition(string $source): array
    {
        return $this->resources->masterDefinition($source);
    }

    /** 套用身分條件（where）的查詢 */
    private function baseQuery(array $def): Builder
    {
        return $def['model']::query()
            ->when($def['where'] !== [], fn (Builder $q) => $q->where($def['where']));
    }

    private function labelOf(array $def, Model $model): string
    {
        return trim($model->{$def['code']}.' '.$model->{$def['text']});
    }
}
