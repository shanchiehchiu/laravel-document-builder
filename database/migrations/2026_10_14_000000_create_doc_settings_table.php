<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 套件的設定（外觀等）：超級管理員在外觀設定頁調整，存在資料庫，不用改程式或設定檔 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_settings');
    }
};
