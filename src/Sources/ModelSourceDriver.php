<?php

declare(strict_types=1);

namespace DocumentBuilder\Sources;

use DocumentBuilder\Contracts\SourceDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * 資料模型：宿主專案的 Eloquent model（客戶、產品、員工……）。
 * settings：model（類別）、code（代碼欄）、text（名稱欄）、
 * where（身分條件，搜尋與存檔驗證都套用，例如客戶欄位不能存廠商）、
 * active（可選條件，只在搜尋時套用，停用的資料不出現在下拉，但舊單據引用到仍可存檔）。
 */
class ModelSourceDriver implements SourceDriver
{
    public function __construct(private readonly ModelCatalog $catalog) {}

    public function key(): string
    {
        return 'model';
    }

    public function label(): string
    {
        return '資料模型';
    }

    public function search(array $settings, string $term, int $limit): array
    {
        $model = $this->newModel($settings);
        $code = (string) $settings['code'];
        $text = (string) $settings['text'];

        return $this->baseQuery($settings)
            ->when(($settings['active'] ?? []) !== [], fn (Builder $q) => $q->where($settings['active']))
            ->when($term !== '', function (Builder $q) use ($code, $text, $term): void {
                $q->where(fn (Builder $w) => $w->where($code, 'like', "%{$term}%")->orWhere($text, 'like', "%{$term}%"));
            })
            ->orderBy($code)
            ->limit($limit)
            ->get(array_values(array_unique([$model->getKeyName(), $code, $text, ...array_values($this->extraColumns($settings))])))
            ->map(fn (Model $m): array => [
                'id' => $m->getKey(),
                'name' => $this->labelOf($settings, $m),
            ] + $this->extrasPayload($settings, $m))
            ->all();
    }

    /**
     * 附隨欄位：settings 的 extras 是「欄位代碼 => 模型欄位」，選取主檔時會把值帶進同一列中代碼相同的欄位。
     *
     * @return array<string, string>
     */
    private function extraColumns(array $settings): array
    {
        return (array) ($settings['extras'] ?? []);
    }

    /** 沒有附隨欄位的來源不帶 extras 鍵，維持原本的回傳格式 */
    private function extrasPayload(array $settings, Model $m): array
    {
        $extras = collect($this->extraColumns($settings))
            ->map(fn (string $column): mixed => $m->getAttribute($column))
            ->all();

        return $extras === [] ? [] : ['extras' => $extras];
    }

    public function find(array $settings, int|string $id): ?array
    {
        $model = $this->newModel($settings);
        $found = $this->baseQuery($settings)
            ->whereKey($id)
            ->first(array_values(array_unique([$model->getKeyName(), $settings['code'], $settings['text']])));

        return $found ? ['id' => $found->getKey(), 'label' => $this->labelOf($settings, $found)] : null;
    }

    public function keyType(array $settings): string
    {
        return $this->newModel($settings)->getKeyType() === 'int' ? 'integer' : 'string';
    }

    public function validateSettings(array $settings): array
    {
        $model = (string) ($settings['model'] ?? '');
        if (! $this->catalog->allows($model)) {
            throw ValidationException::withMessages(['settings.model' => '請選擇可用的資料模型']);
        }

        $columns = $this->catalog->columns($model);
        $errors = [];
        foreach (['code' => '代碼欄位', 'text' => '名稱欄位'] as $key => $label) {
            if (! in_array($settings[$key] ?? null, $columns, true)) {
                $errors["settings.{$key}"] = "請選擇{$label}";
            }
        }

        $conditions = [];
        foreach (['where', 'active'] as $group) {
            $conditions[$group] = [];
            foreach ((array) ($settings[$group] ?? []) as $column => $value) {
                if (! in_array($column, $columns, true) || ! is_scalar($value)) {
                    $errors["settings.{$group}"] = '條件只能選這個資料模型的欄位，值只能是文字或數字';

                    continue;
                }
                $conditions[$group][$column] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['model' => $model, 'code' => $settings['code'], 'text' => $settings['text']] + $conditions;
    }

    public function describe(array $settings): string
    {
        $summary = class_basename((string) ($settings['model'] ?? '')).'：'.($settings['code'] ?? '').'／'.($settings['text'] ?? '');
        $conditions = collect(array_merge($settings['where'] ?? [], $settings['active'] ?? []))
            ->map(fn ($value, $column): string => "{$column}={$value}")
            ->implode('，');

        return $conditions === '' ? $summary : "{$summary}（{$conditions}）";
    }

    private function newModel(array $settings): Model
    {
        $class = (string) $settings['model'];

        return new $class;
    }

    /** 套用身分條件（where）的查詢 */
    private function baseQuery(array $settings): Builder
    {
        return $this->newModel($settings)->newQuery()
            ->when(($settings['where'] ?? []) !== [], fn (Builder $q) => $q->where($settings['where']));
    }

    /** 顯示為「代碼 名稱」；代碼與名稱是同一欄時只顯示一次 */
    private function labelOf(array $settings, Model $model): string
    {
        $code = (string) $model->getAttribute($settings['code']);
        $text = (string) $model->getAttribute($settings['text']);

        return $settings['code'] === $settings['text'] ? $text : trim("{$code} {$text}");
    }
}
