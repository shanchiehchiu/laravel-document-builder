<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use DocumentBuilder\Services\MasterSourceRegistry;

/** 將主檔來源（設定檔與來源庫）轉成不含資料表、Model 或驅動設定的公開資源契約。 */
class ResourceRegistry
{
    public function __construct(private readonly MasterSourceRegistry $sources) {}

    /** 列出 AI 可識別的資源代碼與唯讀能力。 */
    public function listing(): array
    {
        return collect($this->sources->options())
            ->map(fn (string $label, string $code): array => $this->publicSchema($code, $label))
            ->all();
    }

    /** 取得單一資源契約；未知代碼不得回退成任意資料來源（definition 會丟 InvalidArgumentException）。 */
    public function get(string $code): array
    {
        return $this->publicSchema($code, $this->sources->definition($code)['label']);
    }

    public function has(string $code): bool
    {
        return $this->sources->has($code);
    }

    /** @return array<string, string> 資源代碼 => 顯示名稱 */
    public function options(): array
    {
        return $this->sources->options();
    }

    private function publicSchema(string $code, string $label): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'version' => 1,
            'fields' => [
                'id' => ['label' => '資料識別碼', 'type' => $this->sources->keyType($code)],
                'label' => ['label' => '顯示名稱', 'type' => 'string'],
            ],
            'search' => [
                'fields' => ['code', 'name'],
                'max_results' => (int) config('document_builder.master_search_limit'),
            ],
            'operations' => ['search'],
        ];
    }
}
