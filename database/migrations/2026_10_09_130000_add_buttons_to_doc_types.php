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
            $table->json('buttons')->nullable()->after('form_layout')->comment('基礎按鈕開關 {create, edit, delete, navigation: bool}；未設定的視為開啟');
        });
    }

    public function down(): void
    {
        Schema::table('doc_types', function (Blueprint $table) {
            $table->dropColumn('buttons');
        });
    }
};
