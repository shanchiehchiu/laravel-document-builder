<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use DocumentBuilder\Models\DocRecord;
use DocumentBuilder\Models\DocType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 關鍵字搜尋的詞彙索引：把單號與表頭的值依空白與標點切成詞，存進 doc_record_terms。
 * 搜尋是「詞首」比對（詞 LIKE '關鍵字%'），可以走 B-tree 索引；
 * 不比對詞的中間，例如「客戶」搜不到「台中客戶拜訪」，那是連續中文字串的已知限制。
 */
class RecordTermService
{
    private const TABLE = 'doc_record_terms';

    /** 詞的最大長度（字元），與 migration 的 VARCHAR(191) 一致 */
    private const MAX_LENGTH = 191;

    /** 把文字切成詞：依空白與標點、符號切開，去重、去掉空詞與過長的詞 */
    public function tokenize(string $text): array
    {
        return collect(preg_split('/[\s\p{P}\p{S}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->filter(fn (string $term): bool => mb_strlen($term) <= self::MAX_LENGTH)
            ->unique()
            ->values()
            ->all();
    }

    /** 單據的所有詞：單號與表頭的值（主檔取顯示名稱）；不含 JSON 鍵名 */
    public function termsFor(DocRecord $record): array
    {
        $texts = [(string) $record->no];

        foreach ((array) $record->data as $value) {
            if (is_array($value)) {
                $texts[] = (string) ($value['label'] ?? '');
            } elseif (is_scalar($value) && ! is_bool($value)) {
                $texts[] = (string) $value;
            }
        }

        return collect($texts)->flatMap(fn (string $text): array => $this->tokenize($text))->unique()->values()->all();
    }

    /** 重建某張單據的詞（新增或修改後呼叫，與單據寫在同一個交易裡） */
    public function replace(DocRecord $record): void
    {
        DB::table(self::TABLE)->where('doc_record_id', $record->getKey())->delete();

        $rows = array_map(fn (string $term): array => [
            'doc_record_id' => $record->getKey(),
            'doc_type_id' => $record->doc_type_id,
            'term' => $term,
        ], $this->termsFor($record));

        if ($rows !== []) {
            DB::table(self::TABLE)->insert($rows);
        }
    }

    /** 整張單據種類重建（給 migration 回填既有資料） */
    public function rebuildType(DocType $type): void
    {
        $type->records()->withTrashed()->chunkById(500, function ($records): void {
            foreach ($records as $record) {
                $this->replace($record);
            }
        });
    }

    /**
     * 關鍵字的每個詞都必須是某個詞的開頭（AND）。關鍵字切完沒有詞時不加條件。
     */
    public function constrain(Builder $query, DocType $type, string $keyword): void
    {
        foreach ($this->tokenize($keyword) as $term) {
            $prefix = addcslashes($term, '%_\\').'%';
            $query->whereIn('doc_records.id', function ($sub) use ($type, $prefix): void {
                $sub->select('doc_record_id')
                    ->from(self::TABLE)
                    ->where('doc_type_id', $type->getKey())
                    ->whereRaw("term like ? escape '\\'", [$prefix]);
            });
        }
    }
}
