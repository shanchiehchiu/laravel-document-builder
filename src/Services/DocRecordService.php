<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocRecord;
use DocumentBuilder\Models\DocRecordItem;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\FormPlatform\FormRevisionService;
use Illuminate\Support\Collection;

/**
 * 單據存檔：依欄位型別轉換值、主檔存成 {id, label} 快照、保留封存欄位的舊值。
 * 明細列以 _id 對應原本的列：對得上的更新（保留封存欄位舊值），對不上的當新列，沒送回的列刪除。
 */
class DocRecordService
{
    public function __construct(
        private readonly MasterSourceRegistry $masters,
        private readonly DocNumberGenerator $numbers,
        private readonly FormRevisionService $revisions,
        private readonly RecordTermService $terms,
    ) {}

    /** @param  array{doc_date: string, data?: array, items?: array}  $input  已通過 RecordValidator 驗證的資料 */
    public function create(DocType $type, array $input, int|string $userId): DocRecord
    {
        return $type->getConnection()->transaction(function () use ($type, $input, $userId): DocRecord {
            $status = $type->defaultStatus()->firstOrFail();
            $record = $type->records()->create([
                'doc_date' => $input['doc_date'],
                'no' => $this->numbers->generate($type, $input['doc_date']),
                'status_id' => $status->id,
                'form_revision_id' => $this->revisions->publishedRevisionId($type),
                'data' => $this->normalize($type->activeFields(DocField::SECTION_HEADER), $input['data'] ?? [], []),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $this->syncItems($type, $record, $input['items'] ?? []);
            $this->terms->replace($record);

            return $record;
        });
    }

    public function update(DocType $type, DocRecord $record, array $input, int|string $userId): DocRecord
    {
        return $type->getConnection()->transaction(function () use ($type, $record, $input, $userId): DocRecord {
            $record->update([
                'doc_date' => $input['doc_date'],
                'data' => $this->normalize($type->activeFields(DocField::SECTION_HEADER), $input['data'] ?? [], $record->data ?? []),
                'updated_by' => $userId,
            ]);
            $this->syncItems($type, $record, $input['items'] ?? []);
            $this->terms->replace($record);

            return $record;
        });
    }

    /** 只處理版面上的明細表；已從版面移除的明細表，舊明細原樣保留 */
    private function syncItems(DocType $type, DocRecord $record, array $items): void
    {
        foreach ($type->tables() as $tableKey => $title) {
            $fields = $type->tableFields($tableKey);
            $existing = $record->items()->where('table_key', $tableKey)->get()->keyBy('id');
            $kept = [];

            foreach (array_values($items[$tableKey] ?? []) as $index => $row) {
                $item = $existing->get((int) ($row['_id'] ?? 0));
                $data = $this->normalize($fields, $row, $item?->data ?? []);

                if ($item) {
                    $item->update(['seq' => $index + 1, 'data' => $data]);
                    $kept[] = $item->id;
                } else {
                    $record->items()->create(['table_key' => $tableKey, 'seq' => $index + 1, 'data' => $data]);
                }
            }

            $existing->except($kept)->each(fn (DocRecordItem $item) => $item->delete());
        }
    }

    public function delete(DocRecord $record): void
    {
        $record->delete();
    }

    public function changeStatus(DocType $type, DocRecord $record, int $statusId, int|string $userId): DocRecord
    {
        return $type->getConnection()->transaction(function () use ($type, $record, $statusId, $userId): DocRecord {
            $status = $type->statuses()->findOrFail($statusId);
            $record->update(['status_id' => $status->id, 'updated_by' => $userId]);

            return $record;
        });
    }

    /**
     * 同種單據裡的上一筆／下一筆，順序與列表一致：單據日期，同一天再依建立順序（id）
     *
     * @return array{previous: ?DocRecord, next: ?DocRecord}
     */
    public function adjacent(DocRecord $record): array
    {
        $date = $record->doc_date->format('Y-m-d');
        $query = fn () => DocRecord::query()->where('doc_type_id', $record->doc_type_id)->select(['id', 'doc_date']);

        return [
            'previous' => $query()
                ->where(fn ($q) => $q->where('doc_date', '<', $date)
                    ->orWhere(fn ($q) => $q->where('doc_date', $date)->where('id', '<', $record->id)))
                ->orderByDesc('doc_date')->orderByDesc('id')
                ->first(),
            'next' => $query()
                ->where(fn ($q) => $q->where('doc_date', '>', $date)
                    ->orWhere(fn ($q) => $q->where('doc_date', $date)->where('id', '>', $record->id)))
                ->orderBy('doc_date')->orderBy('id')
                ->first(),
        ];
    }

    /** 以舊資料為底只覆寫未封存的欄位，封存欄位的值原樣保留 */
    private function normalize(Collection $fields, array $input, array $existing): array
    {
        $data = $existing;
        foreach ($fields as $field) {
            $data[$field->key] = $this->cast($field, $input[$field->key] ?? null, $existing[$field->key] ?? null);
        }

        return $data;
    }

    private function cast(DocField $field, mixed $value, mixed $old): mixed
    {
        if ($field->type === 'checkbox') {
            return (bool) $value;
        }
        // 複選：一律存成純字串陣列；瀏覽器沒勾任何一項時不會送出該欄位，所以 null 也轉成 []
        if ($field->type === 'checkgroup') {
            return array_values(array_filter(array_map('strval', (array) $value), fn (string $choice): bool => $choice !== ''));
        }
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field->type) {
            'number', 'amount' => round((float) $value, $field->decimals()),
            // 仍是原本那筆主檔時沿用存檔當時的名稱，主檔之後改名、刪除都不影響舊單據
            'master' => is_array($old) && (string) ($old['id'] ?? '') === (string) $value
                ? $old
                : $this->masters->find((string) $field->masterSource(), $value),
            default => (string) $value,
        };
    }
}
