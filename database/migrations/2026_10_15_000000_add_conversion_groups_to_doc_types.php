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
            $table->json('conversion_groups')->nullable()->after('event_schema')->comment('換算組（單價 ↔ 折數）宣告');
        });
    }

    public function down(): void
    {
        Schema::table('doc_types', function (Blueprint $table): void {
            $table->dropColumn('conversion_groups');
        });
    }
};
