<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_types', function (Blueprint $table): void {
            $table->string('label_layout', 10)->default('top')->after('no_seq_digits')->comment('表頭欄位標籤位置：top 上方、left 左側');
        });
    }

    public function down(): void
    {
        Schema::table('doc_types', function (Blueprint $table): void {
            $table->dropColumn('label_layout');
        });
    }
};
