<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use InvalidArgumentException;

/** 將既有主檔白名單轉成不含資料表或 Model 資訊的公開資源契約。 */
class ResourceRegistry
{
    /** 列出 AI 可識別的資源代碼與唯讀能力。 */
    public function listing(): array
    {
        return collect(config('document_builder.masters'))
            ->map(fn (array $definition, string $code): array => $this->publicSchema($code, $definition))
            ->all();
    }

    /** 取得單一資源契約；未知代碼不得回退成任意資料來源。 */
    public function get(string $code): array
    {
        $definition = $this->masterDefinition($code);

        return $this->publicSchema($code, $definition);
    }

    public function has(string $code): bool
    {
        return array_key_exists($code, config('document_builder.masters'));
    }

    /** @return array<string, string> 資源代碼 => 顯示名稱 */
    public function options(): array
    {
        return collect(config('document_builder.masters'))
            ->map(fn (array $definition): string => $definition['label'])
            ->all();
    }

    /** 供後端主檔查詢器使用的內部設定，不得放入 MCP 回應。 */
    public function masterDefinition(string $code): array
    {
        if (! $this->has($code)) {
            throw new InvalidArgumentException("未定義的主檔來源：{$code}");
        }

        return config('document_builder.masters')[$code];
    }

    private function publicSchema(string $code, array $definition): array
    {
        return [
            'code' => $code,
            'label' => $definition['label'],
            'version' => 1,
            'fields' => [
                'id' => ['label' => '資料識別碼', 'type' => 'integer'],
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
