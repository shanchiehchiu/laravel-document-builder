<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use DocumentBuilder\Contracts\SourceDriver;
use DocumentBuilder\Models\DocSource;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * 主檔下拉的資料來源，所有讀取主檔的地方（設計器、填單、驗證、MCP）都經過這裡。
 * 來源有兩處，合併使用：
 * - 設定檔 document_builder.masters：開發者寫在程式裡的來源，畫面上不能改（同代碼時以它為準）
 * - 來源庫 doc_sources：管理員在後台設定的來源，只取啟用的
 * 每個來源由它的驅動（SourceDriver）負責搜尋與依 id 找回資料。
 */
class MasterSourceRegistry
{
    /** @var array<string, array{label: string, driver: string, settings: array, managed: bool}>|null */
    private ?array $definitions = null;

    public function __construct(private readonly Container $container) {}

    /** @return array<string, SourceDriver> 驅動代碼 => 驅動 */
    public function drivers(): array
    {
        return collect((array) config('document_builder.sources.drivers', []))
            ->map(fn (string $class): SourceDriver => $this->container->make($class))
            ->mapWithKeys(fn (SourceDriver $driver): array => [$driver->key() => $driver])
            ->all();
    }

    public function driver(string $key): SourceDriver
    {
        return $this->drivers()[$key] ?? throw new InvalidArgumentException("未定義的來源驅動：{$key}");
    }

    /** @return array<string, array{label: string, driver: string, settings: array, managed: bool}> */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $fromConfig = collect((array) config('document_builder.masters', []))
            ->map(fn (array $def): array => [
                'label' => (string) $def['label'],
                'driver' => (string) ($def['driver'] ?? 'model'),
                'settings' => collect($def)->except(['label', 'driver'])->all(),
                'managed' => false,
            ])
            ->all();

        $fromLibrary = $this->libraryAvailable()
            ? DocSource::query()->where('status', true)->orderBy('id')->get()
                ->reject(fn (DocSource $source): bool => array_key_exists($source->code, $fromConfig))
                ->mapWithKeys(fn (DocSource $source): array => [$source->code => [
                    'label' => $source->label,
                    'driver' => $source->driver,
                    'settings' => (array) $source->settings,
                    'managed' => true,
                ]])
                ->all()
            : [];

        return $this->definitions = $fromConfig + $fromLibrary;
    }

    /** 來源庫有異動時清掉這次請求的快取 */
    public function flush(): void
    {
        $this->definitions = null;
    }

    /** @return array<string, string> 來源代碼 => 顯示名稱 */
    public function options(): array
    {
        return collect($this->definitions())->map(fn (array $def): string => $def['label'])->all();
    }

    public function has(string $source): bool
    {
        return array_key_exists($source, $this->definitions())
            && array_key_exists($this->definitions()[$source]['driver'], $this->drivers());
    }

    /** @return array{label: string, driver: string, settings: array, managed: bool} */
    public function definition(string $source): array
    {
        if (! $this->has($source)) {
            throw new InvalidArgumentException("未定義的主檔來源：{$source}");
        }

        return $this->definitions()[$source];
    }

    /**
     * 下拉搜尋，回傳格式配合填單畫面的 .js-select2[data-url]：[{id, name}]
     *
     * @return array<int, array{id: int|string, name: string}>
     */
    public function search(string $source, ?string $term): array
    {
        $def = $this->definition($source);

        return $this->driver($def['driver'])->search(
            $def['settings'],
            trim((string) $term),
            (int) config('document_builder.master_search_limit', 20)
        );
    }

    /**
     * 依 id 取得 {id, label}；查無資料回傳 null。
     *
     * @return array{id: int|string, label: string}|null
     */
    public function find(string $source, int|string $id): ?array
    {
        $def = $this->definition($source);

        return $this->driver($def['driver'])->find($def['settings'], $id);
    }

    /** 這個來源的 id 型別：integer 或 string */
    public function keyType(string $source): string
    {
        $def = $this->definition($source);

        return $this->driver($def['driver'])->keyType($def['settings']);
    }

    /** 還沒執行來源庫的 migration 時只用設定檔的來源 */
    private function libraryAvailable(): bool
    {
        return Schema::connection((new DocSource)->getConnectionName())->hasTable('doc_sources');
    }
}
