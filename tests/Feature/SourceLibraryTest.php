<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\Models\DocRecord;
use DocumentBuilder\Models\DocSource;
use DocumentBuilder\Services\MasterSourceRegistry;
use DocumentBuilder\Tests\Fixtures\Customer;
use DocumentBuilder\Tests\Fixtures\Supplier;
use DocumentBuilder\Tests\Fixtures\User;
use DocumentBuilder\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

/** 來源庫：管理員在畫面上新增主檔來源，不用改宿主程式 */
class SourceLibraryTest extends TestCase
{
    private Model $admin;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // 「資料模型」可選的 model；實際專案預設自動找 app/Models
        $app['config']->set('document_builder.sources.models', [Customer::class, Supplier::class]);
    }

    public function test_存檔回傳來源摘要_設計器彈窗靠它就地更新主檔下拉(): void
    {
        $created = $this->storeSource([
            'code' => 'customer', 'label' => '客戶', 'driver' => 'model',
            'settings' => ['model' => Customer::class, 'code' => 'no', 'text' => 'name'],
        ])->assertOk()->assertJsonPath('source', ['code' => 'customer', 'label' => '客戶', 'status' => true]);

        $source = DocSource::query()->findOrFail($created->json('id'));

        $this->actingAs($this->admin)->putJson(route('document_builder.sources.update', $source), [
            'code' => 'customer', 'label' => '客戶（新）', 'driver' => 'model', 'status' => 0,
            'settings' => ['model' => Customer::class, 'code' => 'no', 'text' => 'name'],
        ])->assertOk()->assertJsonPath('source', ['code' => 'customer', 'label' => '客戶（新）', 'status' => false]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin();
    }

    private function storeSource(array $input)
    {
        return $this->actingAs($this->admin)->postJson(route('document_builder.sources.store'), $input + ['status' => 1]);
    }

    private function search(string $source, string $term = ''): array
    {
        return $this->actingAs($this->admin)
            ->getJson(route('document_builder.masters', $source).'?search='.urlencode($term))
            ->assertOk()->json();
    }

    public function test_資料模型來源_條件決定能選的資料_存檔記下名稱(): void
    {
        $alice = Customer::query()->create(['no' => 'C001', 'name' => '艾莉絲貿易']);
        Customer::query()->create(['no' => 'C002', 'name' => '停用客戶', 'active' => false]);
        $vendor = Customer::query()->create(['no' => 'V001', 'name' => '零件行', 'kind' => 'vendor']);

        $this->storeSource([
            'code' => 'customer', 'label' => '客戶', 'driver' => 'model',
            'settings' => ['model' => Customer::class, 'code' => 'no', 'text' => 'name', 'where' => ['kind' => 'customer'], 'active' => ['active' => 1]],
        ])->assertOk();

        $this->assertSame([['id' => $alice->id, 'name' => 'C001 艾莉絲貿易']], $this->search('customer'));

        $this->makeType('visit', [['key' => 'customer', 'label' => '客戶', 'type' => 'master', 'source' => 'customer', 'required' => true]], '拜訪單');
        $url = route('doc_records.store', 'visit');

        // 身分條件也套用在存檔：客戶欄位不能存廠商
        $this->actingAs($this->admin)->postJson($url, ['doc_date' => '2026-10-12', 'data' => ['customer' => $vendor->id]])
            ->assertStatus(422)->assertJsonValidationErrors('data.customer');

        $id = $this->actingAs($this->admin)->postJson($url, ['doc_date' => '2026-10-12', 'data' => ['customer' => $alice->id]])
            ->assertOk()->json('id');
        $this->assertSame(['id' => $alice->id, 'label' => 'C001 艾莉絲貿易'], DocRecord::query()->find($id)->value('customer'));
    }

    public function test_不能選隱藏欄位或沒開放的資料模型(): void
    {
        $this->storeSource([
            'code' => 'customer', 'label' => '客戶', 'driver' => 'model',
            'settings' => ['model' => Customer::class, 'code' => 'no', 'text' => 'secret'],
        ])->assertStatus(422)->assertJsonValidationErrors('settings.text');

        $this->storeSource([
            'code' => 'users', 'label' => '使用者', 'driver' => 'model',
            'settings' => ['model' => User::class, 'code' => 'email', 'text' => 'name'],
        ])->assertStatus(422)->assertJsonValidationErrors('settings.model');

        $columns = $this->actingAs($this->admin)->getJson(route('document_builder.sources.columns', ['model' => Customer::class]))
            ->assertOk()->json('columns');
        $this->assertContains('no', $columns);
        $this->assertNotContains('secret', $columns);
    }

    public function test_單據來源_用單據工廠自己建立的單據當主檔(): void
    {
        $this->makeType('project', [['key' => 'title', 'label' => '專案名稱', 'type' => 'text', 'required' => true]], '專案單');
        $projectId = $this->actingAs($this->admin)->postJson(route('doc_records.store', 'project'), [
            'doc_date' => '2026-10-01', 'data' => ['title' => '新竹廠擴建'],
        ])->assertOk()->json('id');
        $project = DocRecord::query()->findOrFail($projectId);

        $this->storeSource([
            'code' => 'project', 'label' => '專案', 'driver' => 'document',
            'settings' => ['doc_type' => 'project', 'text_field' => 'title'],
        ])->assertOk();

        $expected = [['id' => $project->id, 'name' => $project->no.' 新竹廠擴建']];
        $this->assertSame($expected, $this->search('project', '擴建'));
        $this->assertSame($expected, $this->search('project', $project->no));

        $this->makeType('leave', [['key' => 'project', 'label' => '專案', 'type' => 'master', 'source' => 'project']]);
        $id = $this->actingAs($this->admin)->postJson(route('doc_records.store', 'leave'), [
            'doc_date' => '2026-10-12', 'data' => ['project' => $project->id],
        ])->assertOk()->json('id');
        $this->assertSame($project->no.' 新竹廠擴建', DocRecord::query()->find($id)->value('project')['label']);
    }

    public function test_uuid_主鍵的資料模型也能當來源(): void
    {
        $supplier = Supplier::query()->create(['name' => '大同五金']);
        $this->storeSource([
            'code' => 'supplier', 'label' => '供應商', 'driver' => 'model',
            'settings' => ['model' => Supplier::class, 'code' => 'name', 'text' => 'name'],
        ])->assertOk();

        $this->assertSame([['id' => $supplier->id, 'name' => '大同五金']], $this->search('supplier'));

        $this->makeType('purchase', [['key' => 'supplier', 'label' => '供應商', 'type' => 'master', 'source' => 'supplier']], '採購單');
        $id = $this->actingAs($this->admin)->postJson(route('doc_records.store', 'purchase'), [
            'doc_date' => '2026-10-12', 'data' => ['supplier' => $supplier->id],
        ])->assertOk()->json('id');
        $this->assertSame(['id' => $supplier->id, 'label' => '大同五金'], DocRecord::query()->find($id)->value('supplier'));
    }

    public function test_被欄位使用的來源不能換資料來源或刪除_停用後舊值仍保留(): void
    {
        $alice = Customer::query()->create(['no' => 'C001', 'name' => '艾莉絲貿易']);
        $this->storeSource([
            'code' => 'customer', 'label' => '客戶', 'driver' => 'model',
            'settings' => ['model' => Customer::class, 'code' => 'no', 'text' => 'name'],
        ])->assertOk();
        $source = DocSource::query()->where('code', 'customer')->firstOrFail();
        $type = $this->makeType('visit', [['key' => 'customer', 'label' => '客戶', 'type' => 'master', 'source' => 'customer']], '拜訪單');
        $recordId = $this->actingAs($this->admin)->postJson(route('doc_records.store', 'visit'), [
            'doc_date' => '2026-10-12', 'data' => ['customer' => $alice->id],
        ])->assertOk()->json('id');

        $update = fn (array $input) => $this->actingAs($this->admin)->putJson(route('document_builder.sources.update', $source), $input);
        $settings = ['model' => Customer::class, 'code' => 'no', 'text' => 'name'];

        $update(['label' => '客戶', 'driver' => 'model', 'settings' => ['model' => Supplier::class, 'code' => 'name', 'text' => 'name']])
            ->assertStatus(422)->assertJsonValidationErrors('driver');
        $this->actingAs($this->admin)->deleteJson(route('document_builder.sources.destroy', $source))
            ->assertStatus(422)->assertJsonValidationErrors('source');

        // 可以改名、改顯示欄位、停用；代碼不會被改掉
        $update(['label' => '往來客戶', 'code' => 'hacked', 'driver' => 'model', 'settings' => ['code' => 'name'] + $settings, 'status' => 0])->assertOk();
        $source->refresh();
        $this->assertSame(['customer', '往來客戶', false], [$source->code, $source->label, $source->status]);

        // 停用後下拉選不到，但舊單據沿用原本的值仍可存檔
        $this->actingAs($this->admin)->getJson(route('document_builder.masters', 'customer'))->assertNotFound();
        $this->actingAs($this->admin)->putJson(route('doc_records.update', ['visit', $recordId]), [
            'doc_date' => '2026-10-13', 'data' => ['customer' => $alice->id],
        ])->assertOk();
        $this->assertSame('C001 艾莉絲貿易', DocRecord::query()->find($recordId)->value('customer')['label']);
    }

    public function test_設定檔的來源與來源庫合併_代碼不能重複(): void
    {
        config()->set('document_builder.masters', [
            'customer' => ['label' => '客戶', 'model' => Customer::class, 'code' => 'no', 'text' => 'name', 'where' => [], 'active' => []],
        ]);

        $this->storeSource([
            'code' => 'customer', 'label' => '另一個客戶', 'driver' => 'model',
            'settings' => ['model' => Customer::class, 'code' => 'no', 'text' => 'name'],
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->storeSource([
            'code' => 'supplier', 'label' => '供應商', 'driver' => 'model',
            'settings' => ['model' => Supplier::class, 'code' => 'name', 'text' => 'name'],
        ])->assertOk();

        $this->assertSame(['customer' => '客戶', 'supplier' => '供應商'], app(MasterSourceRegistry::class)->options());
        $this->actingAs($this->admin)->get(route('document_builder.sources.index'))
            ->assertOk()->assertSee('程式設定，不得在這裡修改')->assertSee('供應商');
    }

    public function test_一般使用者不能管理來源庫(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->get(route('document_builder.sources.index'))->assertForbidden();
        $this->actingAs($user)->postJson(route('document_builder.sources.store'), [])->assertForbidden();
    }

    public function test_來源庫畫面可以開啟(): void
    {
        $this->actingAs($this->admin)->get(route('document_builder.sources.create'))
            ->assertOk()->assertSee('資料模型')->assertSee('Customer');
    }

    public function test_列表可依主檔欄位篩選_比對主檔的_id(): void
    {
        $alice = Customer::query()->create(['no' => 'C001', 'name' => '艾莉絲貿易']);
        $bob = Customer::query()->create(['no' => 'C002', 'name' => '鮑伯工作室']);
        $this->storeSource([
            'code' => 'customer', 'label' => '客戶', 'driver' => 'model',
            'settings' => ['model' => Customer::class, 'code' => 'no', 'text' => 'name'],
        ])->assertOk();
        $this->makeType('visit', [['key' => 'customer', 'label' => '客戶', 'type' => 'master', 'source' => 'customer', 'show_in_list' => true]], '拜訪單');

        foreach ([$alice, $bob] as $customer) {
            $this->actingAs($this->admin)->postJson(route('doc_records.store', 'visit'), [
                'doc_date' => '2026-10-12', 'data' => ['customer' => $customer->id],
            ])->assertOk();
        }

        $url = route('doc_records.index', 'visit').'?draw=1&start=0&length=10&'.http_build_query(['field' => ['customer' => $alice->id]]);
        $this->actingAs($this->admin)->getJson($url, ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJsonPath('recordsFiltered', 1)->assertJsonPath('data.0.f_customer', 'C001 艾莉絲貿易');

        // 主檔的 id 必須是整數，不是整數的值直接忽略
        $this->actingAs($this->admin)->getJson(route('doc_records.index', 'visit').'?draw=1&start=0&length=10&field[customer]=abc', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJsonPath('recordsFiltered', 2);
    }
}
