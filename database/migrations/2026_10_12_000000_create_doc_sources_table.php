<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 來源庫：主檔下拉的資料來源，由管理員在後台設定（設定檔 masters 裡的來源仍然有效，兩邊合併）。
 * driver 決定資料從哪裡來（model：宿主的資料表；document：單據工廠自己建立的單據），
 * settings 是該驅動需要的設定。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique()->comment('來源代碼，欄位以它引用，建立後不能改');
            $table->string('label', 50);
            $table->string('driver', 20);
            $table->json('settings');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_sources');
    }
};
