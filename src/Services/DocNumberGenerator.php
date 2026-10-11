<?php

declare(strict_types=1);

namespace DocumentBuilder\Services;

use DocumentBuilder\Models\DocType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class DocNumberGenerator
{
    /**
     * 呼叫端須在建立單據的交易內使用；初始化週期列後鎖定並遞增流水號。
     */
    public function generate(DocType $type, string $docDate): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $docDate);
        if ($date === false) {
            throw new InvalidArgumentException('單據日期格式必須為 Y-m-d');
        }

        $period = $this->periodFor($date, $type->no_date_format);
        $now = now();
        $type->getConnection()->table('doc_sequences')->insertOrIgnore([
            'doc_type_id' => $type->id,
            'period' => $period,
            'last_no' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sequence = $type->sequences()
            ->where('period', $period)
            ->lockForUpdate()
            ->firstOrFail();
        $next = $sequence->last_no + 1;
        $sequence->update(['last_no' => $next]);

        return $type->no_prefix
            .$date->format($type->no_date_format)
            .str_pad((string) $next, $type->no_seq_digits, '0', STR_PAD_LEFT);
    }

    /**
     * 依單據日期與顯示日期格式計算本次流水號所屬的週期鍵。
     *
     * Ymd 每日歸零時單號能辨認完整日期，但每天都會切換序號列。
     * Ym 每月歸零時較適合月結單據，序號比每日累積得快。
     * Y 每年歸零時週期最少、管理簡單，但年內單號累積較多。
     */
    public function periodFor(CarbonImmutable $docDate, string $noDateFormat): string
    {
        return match ($noDateFormat) {
            'Ymd' => $docDate->format('Ymd'),
            'Ym' => $docDate->format('Ym'),
            'Y' => $docDate->format('Y'),
            default => throw new InvalidArgumentException('不支援的單號日期格式'),
        };
    }
}
