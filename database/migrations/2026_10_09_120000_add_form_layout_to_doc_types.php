<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_types', function (Blueprint $table) {
            $table->json('form_layout')->nullable()->after('seq')->comment('表單設計器版面：列 → 欄 → 元件（欄位只存 key 參照）');
        });
    }

    public function down(): void
    {
        Schema::table('doc_types', function (Blueprint $table) {
            $table->dropColumn('form_layout');
        });
    }
};
