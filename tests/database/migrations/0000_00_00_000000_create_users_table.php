<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 測試用的使用者資料表：主鍵型別跟著 document_builder.user_key_type，模擬不同宿主專案 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            match (\DocumentBuilder\DocumentBuilder::userKeyType()) {
                'uuid' => $table->uuid('id')->primary(),
                'ulid' => $table->ulid('id')->primary(),
                default => $table->id(),
            };
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_admin')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
