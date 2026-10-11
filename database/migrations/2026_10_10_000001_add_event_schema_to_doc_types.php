<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_types', function (Blueprint $table): void {
            $table->json('event_schema')->nullable()->after('form_layout')->comment('受控欄位事件 DSL');
        });
    }

    public function down(): void
    {
        Schema::table('doc_types', function (Blueprint $table): void {
            $table->dropColumn('event_schema');
        });
    }
};
