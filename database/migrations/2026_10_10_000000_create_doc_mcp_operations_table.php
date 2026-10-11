<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_mcp_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_user_id')->comment('執行工具的後台管理員');
            $table->string('operation', 32)->comment('MCP 操作名稱');
            $table->string('idempotency_key', 128)->comment('呼叫端冪等鍵');
            $table->char('request_hash', 64)->comment('正規化請求的 SHA-256');
            $table->unsignedBigInteger('doc_type_id')->nullable()->comment('操作的單據種類');
            $table->json('response_data')->nullable()->comment('冪等重送時回傳的結果');
            $table->timestamps();

            $table->unique(['admin_user_id', 'idempotency_key'], 'doc_mcp_operations_actor_key_unique');
            $table->index(['doc_type_id', 'operation', 'admin_user_id'], 'doc_mcp_operations_draft_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_mcp_operations');
    }
};
