<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 列表篩選索引：把「列表顯示」的表頭欄位，從 data JSON 取值成虛擬欄位並建索引。
 * 欄位名稱帶單據種類 id（fk_{類型}_{代碼}），因為 doc_records 由所有單據種類共用，
 * 不同種類的同名欄位不能共用同一個虛擬欄位。
 */
class FilterIndexService
{
    /** 能精確比對、且存放長度可控的型別（數字與多行文字不做，避免小數與長文字的問題） */
    public const TYPES = ['text', 'select', 'master', 'date'];

    private const TABLE = 'doc_records';

    /** 單據種類目前應該有的篩選索引欄位 */
    public static function columnName(DocType $type, string $key): string
    {
        return 'fk_'.$type->getKey().'_'.$key;
    }

    /** 這個欄位是否應該有篩選索引（列表顯示的表頭欄位、未封存、型別允許） */
    public function wants(DocField $field): bool
    {
        return $field->section === DocField::SECTION_HEADER
            && $field->show_in_list
            && $field->archived_at === null
            && in_array($field->type, self::TYPES, true);
    }

    /** 依欄位設定補上缺的索引欄位、移除不再需要的；DDL 不能放在交易裡，由呼叫端在交易外執行 */
    public function sync(DocType $type): void
    {
        $prefix = 'fk_'.$type->getKey().'_';
        $wanted = $type->fields()->get()->filter(fn (DocField $f): bool => $this->wants($f))->keyBy('key');
        $existing = collect($this->columns())->filter(fn (string $c): bool => str_starts_with($c, $prefix));

        foreach ($wanted as $key => $field) {
            $column = $prefix.$key;
            if ($existing->contains($column)) {
                continue;
            }
            $expression = $this->expression($key, $field->type === 'master');
            DB::statement("ALTER TABLE ".self::TABLE." ADD COLUMN {$column} VARCHAR(255) GENERATED ALWAYS AS ({$expression}) VIRTUAL");
            Schema::table(self::TABLE, fn ($table) => $table->index(['doc_type_id', $column, 'doc_date'], 'ix_'.$column));
        }

        foreach ($existing as $column) {
            if ($wanted->has(substr($column, strlen($prefix)))) {
                continue;
            }
            Schema::table(self::TABLE, fn ($table) => $table->dropIndex('ix_'.$column));
            Schema::table(self::TABLE, fn ($table) => $table->dropColumn($column));
        }
    }

    /**
     * 從 JSON 取出文字值的運算式。MySQL 的 json_extract 回傳 JSON 字串要再 json_unquote；
     * SQLite 的 json_extract 直接回傳文字，沒有 json_unquote。
     */
    private function expression(string $key, bool $master): string
    {
        $path = $master ? "\$.\"{$key}\".id" : "\$.\"{$key}\"";
        $extract = "json_extract(data, '{$path}')";

        return DB::getDriverName() === 'sqlite' ? $extract : "json_unquote({$extract})";
    }

    /** 查詢時用：這個欄位有沒有可用的篩選索引欄位 */
    public function hasColumn(DocType $type, string $key): bool
    {
        return in_array(self::columnName($type, $key), $this->columns(), true);
    }

    /**
     * 目前 doc_records 的所有欄位（含虛擬欄位）。SQLite 的 table_info 會略過虛擬欄位，
     * 所以改用 table_xinfo；MySQL／MariaDB 的 getColumnListing 本來就會列出。
     */
    private function columns(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return array_column(DB::select("select name from pragma_table_xinfo('".self::TABLE."')"), 'name');
        }

        return Schema::getColumnListing(self::TABLE);
    }
}
