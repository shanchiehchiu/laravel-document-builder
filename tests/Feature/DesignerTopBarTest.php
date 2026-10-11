<?php

declare(strict_types=1);

namespace DocumentBuilder\Tests\Feature;

use DocumentBuilder\FormPlatform\FormRevisionService;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Services\DocumentBuilderSchemaAdapter;
use DocumentBuilder\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

/** 設計器頂部列：版本標籤、單據切換，以及「發布版本」能不能按與原因 */
class DesignerTopBarTest extends TestCase
{
    public function test_從沒存過的單據_沒有可發布的草稿(): void
    {
        $admin = $this->makeAdmin();
        $type = $this->makeType('leave', [['key' => 'reason', 'label' => '事由', 'type' => 'text']]);
        $this->makeType('trip', [], '出差單');

        $config = $this->designerConfig($admin, $type);

        $this->assertSame('尚未儲存', $config['revisionInfo']['label']);
        $this->assertFalse($config['revisionInfo']['canPublish']);
        $this->assertSame('目前沒有等待發布的草稿', $config['revisionInfo']['publishReason']);
        $this->assertSame(['請假單', '出差單'], array_column($config['switcher'], 'name'));
    }

    public function test_草稿還沒審核_不能發布(): void
    {
        $admin = $this->makeAdmin();
        $type = $this->makeType('leave', [['key' => 'reason', 'label' => '事由', 'type' => 'text']]);
        $this->saveDraft($type, $admin);

        $info = $this->designerConfig($admin, $type)['revisionInfo'];

        $this->assertSame('草稿 v1', $info['label']);
        $this->assertFalse($info['canPublish']);
        $this->assertStringContainsString('還沒審核', $info['publishReason']);
    }

    public function test_審核者不能自己發布_換另一位管理員才可以(): void
    {
        $reviewer = $this->makeAdmin();
        $publisher = $this->makeAdmin();
        $type = $this->makeType('leave', [['key' => 'reason', 'label' => '事由', 'type' => 'text']]);
        $draft = $this->saveDraft($type, $reviewer);
        app(FormRevisionService::class)->review($type, $draft, $reviewer, 'approved');

        $self = $this->designerConfig($reviewer, $type)['revisionInfo'];
        $this->assertFalse($self['canPublish']);
        $this->assertStringContainsString('另一位超級管理員', $self['publishReason']);

        $other = $this->designerConfig($publisher, $type)['revisionInfo'];
        $this->assertTrue($other['canPublish']);
        $this->assertNull($other['publishReason']);
    }

    private function saveDraft(DocType $type, Model $user)
    {
        $schema = app(DocumentBuilderSchemaAdapter::class)->export($type->fresh(['fields', 'statuses']));

        return app(FormRevisionService::class)->saveDraft($type, $schema, $user->getKey(), '測試草稿');
    }

    private function designerConfig(Model $user, DocType $type): array
    {
        $response = $this->actingAs($user)->get(route('document_builder.designer.edit', $type))->assertOk();

        return $response->viewData('config');
    }
}
