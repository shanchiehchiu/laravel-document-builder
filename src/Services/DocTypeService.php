<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use Closure;
use DocumentBuilder\Contracts\Integration;
use DocumentBuilder\Models\DocType;
use Illuminate\Validation\ValidationException;

/**
 * 單據種類的建立、修改、刪除；透過宿主整合同步選單，並在交易結束後清除選單快取
 */
class DocTypeService
{
    public function __construct(private readonly Integration $integration) {}

    public function create(array $data): DocType
    {
        $type = $this->transaction(function () use ($data): DocType {
            $type = DocType::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'status' => (bool) ($data['status'] ?? true),
                'seq' => (int) ($data['seq'] ?? 0),
            ]);
            $this->syncMenu($type);
            $this->createDefaultStatuses($type);

            return $type;
        });
        $this->integration->flushMenuCache();

        return $type;
    }

    /** 代碼用在網址與選單 link，建立後不可改，這裡只更新名稱、狀態、排序 */
    public function update(DocType $type, array $data): DocType
    {
        $this->transaction(function () use ($type, $data): void {
            $type->update([
                'name' => $data['name'],
                'status' => (bool) ($data['status'] ?? $type->status),
                'seq' => (int) ($data['seq'] ?? $type->seq),
                'buttons' => isset($data['buttons']) ? $this->buttons($data['buttons']) : $type->buttons,
                'no_prefix' => array_key_exists('no_prefix', $data) ? (string) $data['no_prefix'] : $type->no_prefix,
                'no_date_format' => (string) ($data['no_date_format'] ?? $type->no_date_format),
                'no_seq_digits' => (int) ($data['no_seq_digits'] ?? $type->no_seq_digits),
            ]);
            $this->syncMenu($type);
        });
        $this->integration->flushMenuCache();

        return $type;
    }

    /** 已有單據（含已刪除）時不可刪除，只能停用 */
    public function delete(DocType $type): void
    {
        if ($type->records()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['type' => '這種單據已經有資料，不能刪除，請改為停用']);
        }

        $this->transaction(function () use ($type): void {
            $this->integration->removeMenu($type);
            $type->delete(); // 欄位由外鍵 cascade 刪除
        });
        $this->integration->flushMenuCache();
    }

    /** 補齊所有按鈕代碼並轉成布林，存下來的設定一律是完整的 */
    private function buttons(array $input): array
    {
        $buttons = [];
        foreach (array_keys(DocType::BUTTONS) as $key) {
            $buttons[$key] = (bool) ($input[$key] ?? true);
        }

        return $buttons;
    }

    private function createDefaultStatuses(DocType $type): void
    {
        foreach ($this->defaultStatuses() as $status) {
            $type->statuses()->create($status);
        }
    }

    /** 提供新單據固定使用的預設狀態契約。 */
    public function defaultStatuses(): array
    {
        return [
            ['code' => 'draft', 'name' => '草稿', 'is_default' => true, 'locks_editing' => false, 'color' => 'secondary', 'seq' => 1],
            ['code' => 'confirmed', 'name' => '已確認', 'is_default' => false, 'locks_editing' => true, 'color' => 'success', 'seq' => 2],
            ['code' => 'void', 'name' => '作廢', 'is_default' => false, 'locks_editing' => true, 'color' => 'danger', 'seq' => 3],
        ];
    }

    /** 宿主整合回傳的選單項目 id 記在單據種類上，之後更新與刪除都靠它找到同一列 */
    private function syncMenu(DocType $type): void
    {
        $id = $this->integration->syncMenu($type);

        if ($id !== null && $type->permission_id !== $id) {
            $type->forceFill(['permission_id' => $id])->save();
        }
    }

    private function transaction(Closure $callback): mixed
    {
        return DocType::query()->getConnection()->transaction($callback);
    }
}
