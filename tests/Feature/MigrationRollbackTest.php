<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/** 套件的 migration 要能完整回滾（含 SQLite，測試環境就是 SQLite） */
class MigrationRollbackTest extends TestCase
{
    public function test_套件資料表可以全部回滾再重建(): void
    {
        $path = realpath(__DIR__.'/../../database/migrations');
        $this->assertTrue(Schema::hasTable('doc_type_revisions'));

        $this->assertSame(0, Artisan::call('migrate:reset', ['--path' => $path, '--realpath' => true, '--force' => true]), Artisan::output());
        foreach (['doc_types', 'doc_records', 'doc_type_revisions', 'doc_sources', 'doc_admins'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} 應該已移除");
        }

        $this->assertSame(0, Artisan::call('migrate', ['--path' => $path, '--realpath' => true, '--force' => true]), Artisan::output());
        $this->assertTrue(Schema::hasTable('doc_type_revisions'));
    }
}
