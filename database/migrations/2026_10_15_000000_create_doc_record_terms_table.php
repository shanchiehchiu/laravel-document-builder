<?php

use DocumentBuilder\Models\DocType;
use DocumentBuilder\Services\RecordTermService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_record_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_record_id')->constrained('doc_records')->cascadeOnDelete();
            $table->foreignId('doc_type_id')->constrained('doc_types');
            // 詞首搜尋走這個索引：單據種類 + 詞的範圍
            $table->string('term', 191);
            $table->index(['doc_type_id', 'term', 'doc_record_id'], 'doc_record_terms_search_index');
        });

        // 回填既有單據的詞
        $service = app(RecordTermService::class);
        DocType::query()->each(fn (DocType $type) => $service->rebuildType($type));
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_record_terms');
    }
};
