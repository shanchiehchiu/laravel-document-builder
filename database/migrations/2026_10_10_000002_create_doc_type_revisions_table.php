<?php

use DocumentBuilder\DocumentBuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_type_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doc_type_id')->constrained('doc_types')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('kind', 12);
            $table->unsignedSmallInteger('schema_version');
            $table->json('schema_json');
            $table->foreignId('parent_revision_id')->nullable()->constrained('doc_type_revisions')->nullOnDelete();
            $table->string('change_summary', 255)->nullable();
            DocumentBuilder::userColumn($table, 'created_by')->nullable();
            DocumentBuilder::userColumn($table, 'published_by')->nullable();
            DocumentBuilder::userForeign($table, 'created_by');
            DocumentBuilder::userForeign($table, 'published_by');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['doc_type_id', 'revision']);
            $table->index(['doc_type_id', 'kind', 'revision']);
        });

        Schema::table('doc_types', function (Blueprint $table): void {
            $table->unsignedBigInteger('working_revision_id')->nullable()->index();
            $table->unsignedBigInteger('published_revision_id')->nullable()->index();
        });

        Schema::table('doc_records', function (Blueprint $table): void {
            $table->foreignId('form_revision_id')->nullable()->constrained('doc_type_revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('doc_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('form_revision_id');
        });

        Schema::table('doc_types', function (Blueprint $table): void {
            $table->dropIndex(['working_revision_id']);
            $table->dropIndex(['published_revision_id']);
            $table->dropColumn(['working_revision_id', 'published_revision_id']);
        });

        Schema::dropIfExists('doc_type_revisions');
    }
};
