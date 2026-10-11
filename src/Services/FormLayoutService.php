<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 表單設計器的版面：列 → 欄 → 一個元件（欄位／區段標題／說明文字／空白格／明細表）。
 *
 * doc_types.form_layout 只存結構與欄位 key 參照，欄位定義仍在 doc_fields；
 * 設計器存檔只收結構，不收 HTML，填單畫面由後端依版面渲染。
 *
 * 明細表獨佔一列，存成 {kind: table, key, title, fields: [欄位 key]}，表內欄位是 section=item 的欄位。
 * form_layout.tables 記錄所有建過的明細表 {key: 名稱}（含已從版面移除的），讓移除的明細表能整張還原。
 */
class FormLayoutService
{
    public const KINDS = ['field', 'heading', 'note', 'spacer', 'table'];

    private const GRID = 12;

    private const COLOR = '/^#[0-9a-fA-F]{6}$/';

    private const DARK_TEXT = '#1f2937';

    private const TABLE_KEY = '/^[a-z][a-z0-9_]{0,29}$/';

    public function __construct(private readonly DocFieldService $fields) {}

    /**
     * 解析成可渲染的列：每格為 ['width' => int, 'kind' => string, 'field' => DocField] 或 [..., 'text' => string]；
     * 明細表為 ['width' => 12, 'kind' => 'table', 'key' => string, 'title' => string, 'fields' => Collection<DocField>]。
     * 沒有版面時依 seq 與欄寬自動排列；版面沒參照到的未封存欄位補在最後；參照不到的 key 略過。
     */
    public function rows(DocType $type): array
    {
        $fields = $type->activeFields(DocField::SECTION_HEADER)->keyBy('key');
        $rows = [];

        foreach ($type->form_layout['rows'] ?? [] as $row) {
            $cols = [];
            foreach ($row['cols'] ?? [] as $col) {
                $item = $col['item'] ?? ['kind' => 'spacer'];
                $width = (int) $col['width'];

                if ($item['kind'] === 'table') {
                    $cols[] = $this->tableCol($type, $item);

                    continue;
                }

                if ($item['kind'] === 'field') {
                    $field = $fields->pull($item['key'] ?? '');
                    if ($field) {
                        $cols[] = ['width' => $width, 'kind' => 'field', 'field' => $field];
                    }

                    continue;
                }

                $cols[] = ['width' => $width, 'kind' => $item['kind'], 'text' => (string) ($item['text'] ?? '')]
                    + ($item['kind'] === 'heading' ? [
                        'bg' => $item['bg'] ?? null,
                        'border' => $item['border'] ?? null,
                        'style' => self::headingStyle($item['bg'] ?? null, $item['border'] ?? null),
                    ] : []);
            }

            if ($cols !== []) {
                $rows[] = $cols;
            }
        }

        return array_merge($rows, $this->pack($fields->values()));
    }

    /** 設計器畫布的 GrapesJS 元件定義（不經 HTML，前端依屬性產生預覽）；明細表直接放在畫布最外層，不包在列裡 */
    public function canvas(DocType $type): array
    {
        return array_map(fn (array $cols): array => ($cols[0]['kind'] ?? null) === 'table'
            ? $this->tableComponent($cols[0]['key'], $cols[0]['title'], $cols[0]['fields'])
            : [
                'type' => 'db-row',
                'components' => array_map(fn (array $col): array => isset($col['field'])
                    ? $this->fieldCell($col['field'], $col['width'])
                    : ['type' => 'db-cell', 'kind' => $col['kind'], 'width' => $col['width'], 'text' => $col['text']]
                        + ($col['kind'] === 'heading' ? ['bg' => (string) $col['bg'], 'border' => (string) $col['border']] : []),
                    $cols),
            ], $this->rows($type));
    }

    /**
     * 已封存的欄位，設計器放在「已封存」分類，拖回畫布即取消封存。
     * 表身欄位只列出明細表還在版面上的（只能拖回原本的明細表）；明細表整張移除的見 archivedTables()。
     */
    public function archivedCells(DocType $type): array
    {
        $tables = $type->tables();

        return $type->fields
            ->whereNotNull('archived_at')
            ->filter(fn (DocField $field): bool => $field->section === DocField::SECTION_HEADER
                || array_key_exists((string) $field->table_key, $tables))
            ->map(fn (DocField $field): array => $this->fieldCell($field, $field->width)
                + ($field->section === DocField::SECTION_ITEM ? ['tableTitle' => $tables[$field->table_key]] : []))
            ->values()
            ->all();
    }

    /** 從版面移除的明細表（含當時表內的欄位），拖回畫布即整張還原 */
    public function archivedTables(DocType $type): array
    {
        $onLayout = $type->tables();

        return collect($type->form_layout['tables'] ?? [])
            ->reject(fn (string $title, string $key): bool => array_key_exists($key, $onLayout))
            ->map(fn (string $title, string $key): array => $this->tableComponent($key, $title,
                $type->fields->where('section', DocField::SECTION_ITEM)->where('table_key', $key)->values()))
            ->values()
            ->all();
    }

    /**
     * 儲存設計器送來的版面。欄位逐一新增或更新，版面上沒有的表頭欄位封存、有的取消封存，
     * 並依版面由上而下、由左而右重算 seq，欄寬寫回 doc_fields.width（列表欄位順序跟著版面走）。
     */
    public function save(DocType $type, array $rows): void
    {
        $rows = $this->validateStructure($rows);

        $type->getConnection()->transaction(function () use ($type, $rows): void {
            $layout = [];
            $placed = [];
            $tables = $type->form_layout['tables'] ?? [];
            $usedTables = [];

            foreach ($rows as $index => $row) {
                $cols = [];
                foreach ($row['cols'] as $col) {
                    $width = (int) $col['width'];
                    $item = $col['item'];

                    if ($item['kind'] === 'table') {
                        if (count($row['cols']) > 1) {
                            throw ValidationException::withMessages(['rows' => '第 '.($index + 1).' 列：明細表必須獨佔一列']);
                        }

                        $key = $this->tableKey($tables, $item['key'] ?? null);
                        if (isset($usedTables[$key])) {
                            throw ValidationException::withMessages(['rows' => "明細表「{$item['title']}」在版面上重複出現"]);
                        }
                        $usedTables[$key] = true;
                        $title = trim((string) $item['title']);
                        $tables[$key] = $title;

                        $keys = [];
                        foreach ($item['fields'] ?? [] as $fieldItem) {
                            $field = $this->saveField($type, (array) $fieldItem + ['width' => 12], $index + 1, $key, $title);
                            if (isset($placed[$field->id])) {
                                throw ValidationException::withMessages(['rows' => "欄位「{$field->label}」在版面上重複出現"]);
                            }
                            $placed[$field->id] = true;
                            $field->update(['seq' => count($placed), 'archived_at' => null]);
                            $keys[] = $field->key;
                        }

                        $cols[] = ['width' => 12, 'item' => ['kind' => 'table', 'key' => $key, 'title' => $title, 'fields' => $keys]];

                        continue;
                    }

                    if ($item['kind'] === 'field') {
                        $field = $this->saveField($type, $item + ['width' => $width], $index + 1);
                        if (isset($placed[$field->id])) {
                            throw ValidationException::withMessages(['rows' => "欄位「{$field->label}」在版面上重複出現"]);
                        }
                        $placed[$field->id] = true;
                        $field->update(['seq' => count($placed), 'archived_at' => null]);
                        $item = ['kind' => 'field', 'key' => $field->key];
                    } else {
                        $item = array_filter([
                            'kind' => $item['kind'],
                            'text' => $item['text'] ?? null,
                            // 顏色只有區段標題用得到；存小寫，空值不存
                            'bg' => $item['kind'] === 'heading' && ! empty($item['bg']) ? strtolower($item['bg']) : null,
                            'border' => $item['kind'] === 'heading' && ! empty($item['border']) ? strtolower($item['border']) : null,
                        ], fn ($v): bool => $v !== null);
                    }

                    $cols[] = ['width' => $width, 'item' => $item];
                }
                $layout[] = ['cols' => $cols];
            }

            // 版面上沒有的欄位（含整張移除的明細表裡的欄位）一律封存，舊資料保留
            $type->fields()
                ->whereNull('archived_at')
                ->whereNotIn('id', array_keys($placed))
                ->update(['archived_at' => now()]);

            $type->update(['form_layout' => ['rows' => $layout, 'tables' => $tables]]);
        });
    }

    /** 只檢查結構；回傳原始資料，因為 validate() 的回傳值會剝掉沒寫規則的巢狀鍵（欄位定義留給 saveField 逐欄驗證） */
    private function validateStructure(array $rows): array
    {
        $kinds = implode(',', self::KINDS);

        Validator::make(['rows' => $rows], [
            'rows' => ['present', 'array', 'max:100'],
            'rows.*.cols' => ['required', 'array', 'min:1', 'max:'.self::GRID],
            'rows.*.cols.*.width' => ['required', Rule::in(array_keys(config('document_builder.widths')))],
            'rows.*.cols.*.item' => ['required', 'array'],
            'rows.*.cols.*.item.kind' => ['required', Rule::in(self::KINDS)],
            'rows.*.cols.*.item.text' => ['nullable', 'string', 'required_if:rows.*.cols.*.item.kind,heading,note', 'max:500'],
            // 顏色會輸出到 style 屬性，只接受 #RRGGBB，擋掉 CSS 注入
            'rows.*.cols.*.item.bg' => ['nullable', 'regex:'.self::COLOR],
            'rows.*.cols.*.item.border' => ['nullable', 'regex:'.self::COLOR],
            'rows.*.cols.*.item.title' => ['nullable', 'string', 'required_if:rows.*.cols.*.item.kind,table', 'max:50'],
            'rows.*.cols.*.item.key' => ['nullable', 'string'],
            'rows.*.cols.*.item.fields' => ['nullable', 'array', 'max:30'],
        ], [
            'rows.*.cols.*.item.title.required_if' => '明細表名稱不能空白',
            'rows.*.cols.*.item.title.max' => '明細表名稱最多 50 字',
            'rows.*.cols.*.item.fields.max' => '一個明細表最多 30 個欄位',
            'rows.*.cols.*.item.bg.regex' => '區段標題背景顏色必須是 #RRGGBB 色碼',
            'rows.*.cols.*.item.border.regex' => '區段標題外框顏色必須是 #RRGGBB 色碼',
            'rows.*.cols.*.width.in' => '欄寬只能是 '.implode('、', array_keys(config('document_builder.widths'))),
            'rows.*.cols.*.item.kind.in' => "不支援的元件類型（只能是 {$kinds}）",
            'rows.*.cols.*.item.text.required_if' => '區段標題與說明文字的文字不能空白',
            'rows.*.cols.*.item.text.max' => '區段標題與說明文字最多 500 字',
            'rows.*.cols.required' => '每一列至少要有一格',
        ])->validate();

        return array_values($rows);
    }

    /**
     * 新增或更新一個欄位；$tableKey 有值時是明細表裡的表身欄位。
     * 錯誤訊息改成「第 N 列「欄位名稱」：…」讓設計器使用者找得到是哪一格。
     */
    private function saveField(DocType $type, array $item, int $rowNo, ?string $tableKey = null, ?string $tableTitle = null): DocField
    {
        $section = $tableKey === null ? DocField::SECTION_HEADER : DocField::SECTION_ITEM;

        try {
            $data = Validator::make($item, DocFieldService::rules() + ['id' => ['nullable', 'integer']],
                DocFieldService::MESSAGES, DocFieldService::ATTRIBUTES)->validate();

            if (empty($data['id'])) {
                return $this->fields->create($type, $data + ['section' => $section, 'table_key' => $tableKey]);
            }

            $field = $type->fields()->whereKey($data['id'])->first()
                ?? throw ValidationException::withMessages(['id' => '欄位不存在或不屬於這種單據']);

            // 換區塊或換明細表，舊單據存的值就對不上了
            if ($field->section !== $section) {
                throw ValidationException::withMessages(['id' => $section === DocField::SECTION_ITEM
                    ? '表頭欄位不能拖進明細表，請在明細表裡新增欄位'
                    : '明細表的欄位不能拖到表頭，請在表頭新增欄位']);
            }
            if ($section === DocField::SECTION_ITEM && $field->table_key !== $tableKey) {
                throw ValidationException::withMessages(['id' => '欄位不能換到別的明細表']);
            }

            return $this->fields->update($field, $data);
        } catch (ValidationException $e) {
            $label = trim((string) ($item['label'] ?? '')) ?: '未命名欄位';
            $where = $tableTitle === null ? "第 {$rowNo} 列「{$label}」" : "第 {$rowNo} 列明細表「{$tableTitle}」的「{$label}」";

            throw ValidationException::withMessages([
                'rows' => collect($e->errors())->flatten()->map(fn (string $m): string => "{$where}：{$m}")->all(),
            ]);
        }
    }

    /** 沿用既有明細表的 key；新明細表（沒有 key）編成 table_N */
    private function tableKey(array $tables, ?string $key): string
    {
        if ($key === null || $key === '') {
            $n = count($tables) + 1;
            while (array_key_exists("table_{$n}", $tables)) {
                $n++;
            }

            return "table_{$n}";
        }

        if (! preg_match(self::TABLE_KEY, $key) || ! array_key_exists($key, $tables)) {
            throw ValidationException::withMessages(['rows' => "明細表 {$key} 不存在"]);
        }

        return $key;
    }

    /** 版面上的明細表：依版面的欄位順序取出未封存欄位，版面沒參照到的補在最後 */
    private function tableCol(DocType $type, array $item): array
    {
        $fields = $type->tableFields((string) $item['key'])->keyBy('key');
        $ordered = collect($item['fields'] ?? [])
            ->map(fn (string $key): ?DocField => $fields->pull($key))
            ->filter()
            ->concat($fields->values())
            ->values();

        return ['width' => 12, 'kind' => 'table', 'key' => $item['key'], 'title' => (string) ($item['title'] ?? ''), 'fields' => $ordered];
    }

    private function tableComponent(string $key, string $title, Collection $fields): array
    {
        return [
            'type' => 'db-table',
            'tableKey' => $key,
            'title' => $title,
            'components' => $fields->map(fn (DocField $field): array => $this->fieldCell($field, 12))->values()->all(),
        ];
    }

    /**
     * 區段標題的 inline style；沒設定顏色回傳 null（沿用預設的底線樣式）。
     * 文字顏色不讓使用者選，依背景自動挑白字或深色字中對比較高的一個，避免淺底白字看不見。
     */
    public static function headingStyle(?string $bg, ?string $border): ?string
    {
        if (! $bg && ! $border) {
            return null;
        }
        if (! $bg) {
            return "border: 1px solid {$border}";
        }

        $text = self::contrast($bg, '#ffffff') >= self::contrast($bg, self::DARK_TEXT) ? '#ffffff' : self::DARK_TEXT;

        return "background-color: {$bg}; color: {$text}; border: 1px solid ".($border ?: $bg);
    }

    /** WCAG 對比值 */
    private static function contrast(string $a, string $b): float
    {
        [$la, $lb] = [self::luminance($a), self::luminance($b)];

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** WCAG 相對亮度 */
    private static function luminance(string $hex): float
    {
        $channel = function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = array_map(fn (string $h): float => $channel((int) hexdec($h)), str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function fieldCell(DocField $field, int $width): array
    {
        return [
            'type' => 'db-cell',
            'kind' => 'field',
            'width' => $width,
            'fieldId' => $field->id,
            'key' => $field->key,
            'label' => $field->label,
            'ftype' => $field->type,
            'required' => $field->required,
            'showInList' => $field->show_in_list,
            'choices' => implode("\n", $field->choices()),
            'decimals' => $field->decimals(),
            'source' => (string) $field->masterSource(),
            'tableKey' => (string) $field->table_key,
            'sum' => $field->sums(),
        ];
    }

    /** 沒有版面的欄位依欄寬累加，超過 12 欄就換列 */
    private function pack(Collection $fields): array
    {
        $rows = [];
        $current = [];
        $used = 0;

        foreach ($fields as $field) {
            $width = min(self::GRID, max(1, $field->width));
            if ($used + $width > self::GRID && $current !== []) {
                $rows[] = $current;
                $current = [];
                $used = 0;
            }
            $current[] = ['width' => $width, 'kind' => 'field', 'field' => $field];
            $used += $width;
        }

        if ($current !== []) {
            $rows[] = $current;
        }

        return $rows;
    }
}
