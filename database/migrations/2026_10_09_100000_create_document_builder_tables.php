<?php

declare(strict_types=1);

use DocumentBuilder\DocumentBuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 20261009 單據建置 M1：單據種類、欄位定義、單據資料
 * 單據日期等一定會查詢的欄位放實體欄位，自訂欄位放 data JSON。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique()->comment('單據代碼，用在網址，建立後不可改');
            $table->string('name', 50)->comment('單據名稱');
            $table->boolean('status')->default(true)->comment('1 啟用 0 停用');
            $table->unsignedInteger('seq')->default(0)->comment('側選單排序');
            $table->unsignedBigInteger('permission_id')->nullable()->comment('admin_permissions 選單列');
            $table->timestamps();
        });

        Schema::create('doc_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_type_id')->constrained('doc_types')->cascadeOnDelete();
            $table->string('section', 10)->default('header')->comment('header 表頭 / item 表身');
            $table->string('key', 50)->comment('資料鍵，建立後不可改');
            $table->string('label', 50)->comment('欄位名稱');
            $table->string('type', 20)->comment('欄位型別，見 config/document_builder.php');
            $table->boolean('required')->default(false);
            $table->unsignedTinyInteger('width')->default(4)->comment('格線欄寬 3/4/6/12');
            $table->json('options')->nullable()->comment('choices / decimals / source');
            $table->boolean('show_in_list')->default(false);
            $table->unsignedInteger('seq')->default(0);
            $table->timestamp('archived_at')->nullable()->comment('封存時間，封存後不再顯示，舊資料保留');
            $table->timestamps();
            $table->unique(['doc_type_id', 'section', 'key']);
        });

        Schema::create('doc_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_type_id')->constrained('doc_types');
            $table->date('doc_date')->comment('單據日期');
            $table->json('data')->nullable()->comment('表頭自訂欄位');
            // 使用者欄位的型別跟著 user_key_type（int／uuid／ulid）
            DocumentBuilder::userColumn($table, 'created_by')->nullable();
            DocumentBuilder::userColumn($table, 'updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['doc_type_id', 'doc_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_records');
        Schema::dropIfExists('doc_fields');
        Schema::dropIfExists('doc_types');
    }
};
