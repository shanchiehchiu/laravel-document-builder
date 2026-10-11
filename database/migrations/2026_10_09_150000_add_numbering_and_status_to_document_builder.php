<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_types', function (Blueprint $table) {
            $table->string('no_prefix', 20)->default('')->comment('單號前綴');
            $table->string('no_date_format', 10)->default('Ymd')->comment('單號日期格式');
            $table->unsignedTinyInteger('no_seq_digits')->default(3)->comment('流水號補零位數');
        });

        Schema::create('doc_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_type_id')->constrained('doc_types')->cascadeOnDelete();
            $table->string('code', 30)->comment('狀態代碼');
            $table->string('name', 30)->comment('狀態名稱');
            $table->boolean('is_default')->default(false)->comment('建立單據時套用');
            $table->boolean('locks_editing')->default(false)->comment('是否鎖定編輯與刪除');
            $table->string('color', 20)->default('secondary')->comment('Bootstrap badge 顏色');
            $table->unsignedInteger('seq')->default(0)->comment('狀態顯示順序');
            $table->timestamps();
            $table->unique(['doc_type_id', 'code']);
            $table->index(['doc_type_id', 'is_default']);
        });

        Schema::create('doc_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_type_id')->constrained('doc_types')->cascadeOnDelete();
            $table->string('period', 20)->comment('流水號重算週期');
            $table->unsignedBigInteger('last_no')->default(0)->comment('此週期最後使用的流水號');
            $table->timestamps();
            $table->unique(['doc_type_id', 'period']);
        });

        Schema::table('doc_records', function (Blueprint $table) {
            $table->string('no', 60)->nullable()->after('doc_date')->comment('建立後固定不變的單號');
            $table->foreignId('status_id')->nullable()->after('no')->constrained('doc_statuses')->restrictOnDelete();
            $table->unique(['doc_type_id', 'no']);
        });

        $now = now();
        foreach (DB::table('doc_types')->orderBy('id')->pluck('id') as $typeId) {
            $draftId = DB::table('doc_statuses')->insertGetId([
                'doc_type_id' => $typeId,
                'code' => 'draft',
                'name' => '草稿',
                'is_default' => true,
                'locks_editing' => false,
                'color' => 'secondary',
                'seq' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('doc_statuses')->insert([
                [
                    'doc_type_id' => $typeId,
                    'code' => 'confirmed',
                    'name' => '已確認',
                    'is_default' => false,
                    'locks_editing' => true,
                    'color' => 'success',
                    'seq' => 2,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'doc_type_id' => $typeId,
                    'code' => 'void',
                    'name' => '作廢',
                    'is_default' => false,
                    'locks_editing' => true,
                    'color' => 'danger',
                    'seq' => 3,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            DB::table('doc_records')
                ->where('doc_type_id', $typeId)
                ->whereNull('status_id')
                ->update(['status_id' => $draftId]);
        }
    }

    public function down(): void
    {
        Schema::table('doc_records', function (Blueprint $table) {
            $table->dropUnique('doc_records_doc_type_id_no_unique');
            $table->dropForeign('doc_records_status_id_foreign');
            $table->dropColumn(['no', 'status_id']);
        });

        Schema::dropIfExists('doc_sequences');
        Schema::dropIfExists('doc_statuses');

        Schema::table('doc_types', function (Blueprint $table) {
            $table->dropColumn(['no_prefix', 'no_date_format', 'no_seq_digits']);
        });
    }
};
