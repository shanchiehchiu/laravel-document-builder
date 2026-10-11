<?php

use DocumentBuilder\DocumentBuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 超級管理員名單：在初始化頁設定，不用改程式。
 * 宿主若自己在程式裡定義 document-builder.super-admin Gate，就以程式為準，這張表不使用。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_admins', function (Blueprint $table): void {
            $table->id();
            DocumentBuilder::userColumn($table, 'user_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_admins');
    }
};
