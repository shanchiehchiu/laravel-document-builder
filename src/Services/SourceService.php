<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocSource;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 來源庫的新增、修改、刪除。
 * 保護已經被欄位引用的來源：代碼建立後不能改；被引用後不能換驅動、不能換資料模型或單據、不能刪除（可以停用），
 * 避免舊單據存的 id 對到別張表的資料。顯示欄位、條件這類設定仍可調整。
 */
class SourceService
{
    /** 被引用後不能再改的設定鍵（換了就是另一個資料來源） */
    private const LOCKED_SETTINGS = ['model', 'doc_type'];

    public function __construct(private readonly MasterSourceRegistry $registry) {}

    public function create(array $input): DocSource
    {
        $data = $this->validate($input, null);
        $source = DocSource::query()->create($data);
        $this->registry->flush();

        return $source;
    }

    public function update(DocSource $source, array $input): DocSource
    {
        $data = $this->validate(['code' => $source->code] + $input, $source);

        if ($this->usageCount($source->code) > 0) {
            $changed = $data['driver'] !== $source->driver
                || collect(self::LOCKED_SETTINGS)->contains(
                    fn (string $key): bool => ($data['settings'][$key] ?? null) !== ($source->settings[$key] ?? null)
                );
            if ($changed) {
                throw ValidationException::withMessages(['driver' => '已有欄位使用這個來源，不得換驅動或資料來源；可以另外新增一個來源']);
            }
        }

        $source->update(collect($data)->except('code')->all());
        $this->registry->flush();

        return $source;
    }

    public function delete(DocSource $source): void
    {
        if ($this->usageCount($source->code) > 0) {
            throw ValidationException::withMessages(['source' => '已有欄位使用這個來源，不得刪除；不再使用時請改成停用']);
        }

        $source->delete();
        $this->registry->flush();
    }

    /** 引用這個來源的欄位數（含已封存的欄位，因為舊單據還留著它的值） */
    public function usageCount(string $code): int
    {
        return DocField::query()->where('type', 'master')->where('options->source', $code)->count();
    }

    /** 預覽：用還沒存的設定試搜尋，讓管理員確認設定正確 */
    public function preview(array $input, string $term): array
    {
        $driver = $this->registry->driver((string) ($input['driver'] ?? ''));
        $settings = $driver->validateSettings((array) ($input['settings'] ?? []));

        return $driver->search($settings, trim($term), 10);
    }

    private function validate(array $input, ?DocSource $source): array
    {
        $drivers = array_keys($this->registry->drivers());
        $configured = array_keys((array) config('document_builder.masters', []));

        $data = Validator::make($input, [
            'code' => array_filter([
                'required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', Rule::notIn($configured),
                $source ? null : Rule::unique((new DocSource)->getTable(), 'code'),
            ]),
            'label' => ['required', 'string', 'max:50'],
            'driver' => ['required', Rule::in($drivers)],
            'settings' => ['nullable', 'array'],
            'status' => ['nullable', 'boolean'],
        ], [
            'code.regex' => '來源代碼只能用小寫英文、數字及底線，開頭須為英文',
            'code.not_in' => '這個代碼已經用在設定檔的主檔來源',
        ], [
            'code' => '來源代碼', 'label' => '名稱', 'driver' => '來源類型',
        ])->validate();

        return [
            'code' => $data['code'],
            'label' => $data['label'],
            'driver' => $data['driver'],
            'settings' => $this->registry->driver($data['driver'])->validateSettings((array) ($data['settings'] ?? [])),
            'status' => (bool) ($data['status'] ?? true),
        ];
    }
}
