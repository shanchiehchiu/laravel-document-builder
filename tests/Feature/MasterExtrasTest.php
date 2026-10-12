<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Services\MasterSourceRegistry;
use DocumentBuilder\Tests\Fixtures\Customer;
use DocumentBuilder\Tests\TestCase;

/** 主檔附隨帶入：搜尋結果帶出附隨欄位，填單頁的主檔下拉宣告要寫入的欄位代碼 */
class MasterExtrasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('document_builder.masters', [
            'customer' => [
                'label' => '客戶', 'driver' => 'model', 'model' => Customer::class,
                'code' => 'no', 'text' => 'name', 'extras' => ['kind' => 'kind'],
            ],
        ]);
        app(MasterSourceRegistry::class)->flush();
    }

    public function test_搜尋結果帶出附隨欄位的值(): void
    {
        Customer::query()->create(['no' => 'C001', 'name' => 'ACME', 'kind' => 'vip']);

        $results = app(MasterSourceRegistry::class)->search('customer', 'ACME');

        $this->assertCount(1, $results);
        $this->assertSame('C001 ACME', $results[0]['name'], '標籤為代碼加名稱');
        $this->assertSame(['kind' => 'vip'], $results[0]['extras']);
    }

    public function test_沒有設定附隨欄位的來源_搜尋結果的附隨欄位是空的(): void
    {
        config()->set('document_builder.masters', [
            'plain' => ['label' => '一般', 'driver' => 'model', 'model' => Customer::class, 'code' => 'no', 'text' => 'name'],
        ]);
        app(MasterSourceRegistry::class)->flush();
        Customer::query()->create(['no' => 'C002', 'name' => 'Plain', 'kind' => 'x']);

        $results = app(MasterSourceRegistry::class)->search('plain', '');

        $this->assertArrayNotHasKey('extras', $results[0], '沒有附隨欄位時維持原本的回傳格式');
        $this->assertSame([], app(MasterSourceRegistry::class)->extraKeys('plain'));
    }

    public function test_設計器設定帶出所有來源的附隨欄位代碼_供屬性面板提示(): void
    {
        $admin = $this->makeAdmin();
        $type = $this->makeType('visit', [], '拜訪單');

        $config = $this->actingAs($admin)->get(route('document_builder.designer.edit', $type))->assertOk()->viewData('config');

        $this->assertSame(['kind'], $config['masterExtraKeys']);
    }

    public function test_填單頁的主檔下拉宣告附隨欄位代碼(): void
    {
        $admin = $this->makeAdmin();
        $this->makeType('visit', [
            ['key' => 'customer', 'label' => '客戶', 'type' => 'master', 'source' => 'customer'],
        ], '拜訪單');

        $html = $this->actingAs($admin)->get(route('doc_records.create', 'visit'))->assertOk()->getContent();

        // Blade 會把引號輸出為 &quot;，瀏覽器解析後即為 ["kind"]
        $this->assertStringContainsString('data-extra-keys="[&quot;kind&quot;]"', $html);
    }
}
