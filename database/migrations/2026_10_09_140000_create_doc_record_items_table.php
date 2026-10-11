<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 20261009 單據建置 M2：明細表（表身）
 * 一張單據可以有多個明細表，明細表本身記在 doc_types.form_layout；
 * 表身欄位以 table_key 標明屬於哪個明細表，每一列明細的自訂欄位放 data JSON。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_fields', function (Blueprint $table) {
            $table->string('table_key', 30)->nullable()->after('section')->comment('表身欄位所屬的明細表（form_layout 的 table key）');
        });

        Schema::create('doc_record_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_record_id')->constrained('doc_records')->cascadeOnDelete();
            $table->string('table_key', 30)->comment('所屬明細表');
            $table->unsignedInteger('seq')->default(0)->comment('列序');
            $table->json('data')->nullable()->comment('表身自訂欄位');
            $table->timestamps();
            $table->index(['doc_record_id', 'table_key', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_record_items');

        Schema::table('doc_fields', function (Blueprint $table) {
            $table->dropColumn('table_key');
        });
    }
};
