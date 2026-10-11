<?php

use DocumentBuilder\DocumentBuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_type_revision_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doc_type_revision_id')->constrained('doc_type_revisions')->cascadeOnDelete();
            DocumentBuilder::userColumn($table, 'reviewer_id')->nullable();
            DocumentBuilder::userForeign($table, 'reviewer_id');
            $table->string('decision', 24);
            $table->text('note')->nullable();
            $table->json('validation_json');
            $table->timestamp('reviewed_at')->useCurrent();
            $table->index(['doc_type_revision_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_type_revision_reviews');
    }
};
