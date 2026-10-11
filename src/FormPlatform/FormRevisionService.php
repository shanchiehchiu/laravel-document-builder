<?php

declare(strict_types=1);

namespace DocumentBuilder\FormPlatform;

use DocumentBuilder\DocumentBuilder;
use Illuminate\Contracts\Auth\Authenticatable;
use DocumentBuilder\Models\DocField;
use DocumentBuilder\Models\DocRecord;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Models\DocTypeRevision;
use DocumentBuilder\Models\DocTypeRevisionReview;
use DocumentBuilder\Services\DocTypeService;
use DocumentBuilder\Services\DocumentBuilderSchemaAdapter;
use DocumentBuilder\Services\FormLayoutService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/** 以追加式 Schema 快照管理草稿、發布、修訂清單與回復。 */
class FormRevisionService
{
    public function __construct(
        private readonly DocumentBuilderSchemaAdapter $adapter,
        private readonly FormSchemaValidator $schemaValidator,
        private readonly FormPublishValidator $publishValidator,
        private readonly FormLayoutService $layout,
        private readonly DocTypeService $types,
    ) {}

    public function workingSchema(DocType $type): array
    {
        $revision = $type->workingRevision;

        return $revision?->schema_json ?? $this->adapter->export($type);
    }

    public function runtimeSchema(DocType $type): array
    {
        $revision = $type->publishedRevision;

        return $revision?->schema_json ?? $this->adapter->export($type);
    }

    public function schemaForRecord(DocType $type, DocRecord $record): array
    {
        if ($record->form_revision_id === null) {
            return $this->runtimeSchema($type);
        }

        return $record->formRevision?->schema_json ?? $this->runtimeSchema($type);
    }

    public function validateForPublish(DocType $type): array
    {
        return $this->publishValidator->validate($this->workingSchema($type), $type->code);
    }

    /** 儲存一份新的草稿；舊修訂永遠保留原內容。 */
    public function saveDraft(DocType $type, array $schema, int $adminUserId, ?string $summary = null): DocTypeRevision
    {
        $validation = $this->schemaValidator->validate($schema, $type->code);
        if (! $validation['valid']) {
            throw ValidationException::withMessages([
                'schema' => array_column($validation['errors'], 'message'),
            ]);
        }

        return $type->getConnection()->transaction(function () use ($type, $schema, $adminUserId, $summary): DocTypeRevision {
            $lockedType = DocType::query()->lockForUpdate()->findOrFail($type->id);

            return $this->appendRevision($lockedType, $schema, 'draft', $adminUserId, $summary);
        });
    }

    /** 為目前草稿追加審核結果與當時的驗證摘要。 */
    public function review(
        DocType $type,
        DocTypeRevision $draft,
        Authenticatable $reviewer,
        string $decision,
        ?string $note = null,
    ): DocTypeRevisionReview {
        $this->assertSuperAdmin($reviewer);
        if ((int) $draft->doc_type_id !== (int) $type->id || $draft->kind !== 'draft') {
            throw (new ModelNotFoundException)->setModel(DocTypeRevision::class, [$draft->id]);
        }
        if (! in_array($decision, ['approved', 'changes_requested'], true)) {
            throw ValidationException::withMessages(['decision' => '審核結果不正確']);
        }

        return $type->getConnection()->transaction(function () use ($type, $draft, $reviewer, $decision, $note): DocTypeRevisionReview {
            $lockedType = DocType::query()->lockForUpdate()->findOrFail($type->id);
            $lockedDraft = DocTypeRevision::query()->lockForUpdate()->findOrFail($draft->id);
            if ((int) $lockedType->working_revision_id !== (int) $lockedDraft->id) {
                throw ValidationException::withMessages(['revision' => '只能審核目前的工作草稿']);
            }

            $validation = $this->publishValidator->validate($lockedDraft->schema_json, $lockedType->code);
            if (! $validation['valid']) {
                throw ValidationException::withMessages([
                    'schema' => array_column($validation['errors'], 'message'),
                ]);
            }

            return DocTypeRevisionReview::query()->create([
                'doc_type_revision_id' => $lockedDraft->id,
                'reviewer_id' => $reviewer->getKey(),
                'decision' => $decision,
                'note' => $note === null ? null : mb_substr(trim($note), 0, 2000),
                'validation_json' => $validation,
                'reviewed_at' => now(),
            ]);
        });
    }

    /** 審核通過後由不同管理員原子發布，並綁定單據版本。 */
    public function publish(DocType $type, Authenticatable $publisher, ?string $summary = null): DocTypeRevision
    {
        $this->assertSuperAdmin($publisher);

        return $type->getConnection()->transaction(function () use ($type, $publisher, $summary): DocTypeRevision {
            $lockedType = DocType::query()->lockForUpdate()->findOrFail($type->id);
            $draft = $lockedType->workingRevision;
            if ($draft === null || $draft->kind !== 'draft') {
                throw ValidationException::withMessages(['revision' => '目前沒有等待發布的草稿']);
            }
            $latestReview = $draft->latestReview;
            if ($latestReview === null || $latestReview->decision !== 'approved') {
                throw ValidationException::withMessages(['review' => '發布前必須先完成草稿審核']);
            }
            if ((int) $latestReview->reviewer_id === (int) $publisher->getKey()) {
                throw new AuthorizationException('審核者與發布者必須是不同的管理員');
            }

            $schema = $draft->schema_json;
            $validation = $this->publishValidator->validate($schema, $lockedType->code);
            if (! $validation['valid']) {
                throw ValidationException::withMessages([
                    'schema' => array_column($validation['errors'], 'message'),
                ]);
            }

            $payload = $this->adapter->toDesignerPayload($lockedType, $schema);
            $previousPublishedId = $lockedType->published_revision_id;
            $this->layout->save($lockedType, $payload['rows']);
            $this->types->update($lockedType, $payload['settings']);
            $lockedType->forceFill(['event_schema' => $payload['events']])->save();

            $publishedSchema = $this->adapter->export($lockedType->fresh(['fields', 'statuses']));
            $publishedValidation = $this->publishValidator->validate($publishedSchema, $lockedType->code);
            if (! $publishedValidation['valid']) {
                throw ValidationException::withMessages([
                    'schema' => array_column($publishedValidation['errors'], 'message'),
                ]);
            }

            $revision = $this->appendRevision(
                $lockedType,
                $publishedSchema,
                'published',
                (int) $publisher->getKey(),
                $summary,
                $publisher->getKey(),
                now(),
                $lockedType->working_revision_id
            );
            $lockedType->forceFill([
                'working_revision_id' => $revision->id,
                'published_revision_id' => $revision->id,
            ])->save();

            if ($previousPublishedId === null) {
                DocRecord::query()
                    ->where('doc_type_id', $lockedType->id)
                    ->whereNull('form_revision_id')
                    ->update(['form_revision_id' => $revision->id]);
            }

            return $revision->refresh();
        });
    }

    /** 從同一單據的舊修訂複製一份新草稿，不改動目前發布版本。 */
    public function restore(DocType $type, DocTypeRevision $source, Authenticatable $actor): DocTypeRevision
    {
        $this->assertSuperAdmin($actor);
        if ((int) $source->doc_type_id !== (int) $type->id) {
            throw (new ModelNotFoundException)->setModel(DocTypeRevision::class, [$source->id]);
        }

        return $type->getConnection()->transaction(function () use ($type, $source, $actor): DocTypeRevision {
            $lockedType = DocType::query()->lockForUpdate()->findOrFail($type->id);
            $schema = $source->schema_json;
            $validation = $this->schemaValidator->validate($schema, $lockedType->code);
            if (! $validation['valid']) {
                throw ValidationException::withMessages([
                    'schema' => array_column($validation['errors'], 'message'),
                ]);
            }

            if ($lockedType->published_revision_id === null) {
                $payload = $this->adapter->toDesignerPayload($lockedType, $schema);
                $this->layout->save($lockedType, $payload['rows']);
                $this->types->update($lockedType, $payload['settings']);
                $lockedType->forceFill(['event_schema' => $payload['events']])->save();
                $schema = $this->adapter->export($lockedType->fresh(['fields', 'statuses']));
            }

            return $this->appendRevision(
                $lockedType,
                $schema,
                'draft',
                (int) $actor->getKey(),
                '回復修訂 '.$source->revision,
                null,
                null,
                $source->id
            );
        });
    }

    /** 建立使用 Schema 快照的設計器模型，讓草稿不必覆寫已發布的欄位資料。 */
    public function designerProjection(DocType $type, array $schema): DocType
    {
        $currentFields = $type->fields;
        $fields = collect($schema['fields'])->map(function (array $definition) use ($currentFields): DocField {
            $existing = $currentFields->first(fn (DocField $field): bool => $field->key === $definition['key']
                && $field->section === $definition['section']
                && $field->table_key === $definition['table_key']);
            $field = new DocField([
                'section' => $definition['section'],
                'table_key' => $definition['table_key'],
                'key' => $definition['key'],
                'label' => $definition['label'],
                'type' => $definition['type'],
                'required' => $definition['required'],
                'width' => $definition['width'],
                'options' => $definition['options'],
                'show_in_list' => $definition['show_in_list'],
                'seq' => $definition['order'],
                'archived_at' => $definition['archived'] ? now() : null,
            ]);
            $field->setAttribute('id', $existing?->id);

            return $field;
        })->values();

        $rows = [];
        foreach ($schema['layout']['rows'] as $row) {
            $cols = [];
            foreach ($row['cols'] as $col) {
                $component = $col['component'];
                $item = match ($component['type']) {
                    'field' => ['kind' => 'field', 'key' => $component['key']],
                    'table' => [
                        'kind' => 'table', 'key' => $component['key'], 'title' => $component['title'],
                        'fields' => array_column($component['fields'], 'key'),
                    ],
                    default => array_filter([
                        'kind' => $component['type'], 'text' => $component['text'] ?? null,
                        'bg' => $component['bg'] ?? null, 'border' => $component['border'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                };
                $cols[] = ['width' => $col['width'], 'item' => $item];
            }
            $rows[] = ['cols' => $cols];
        }

        $projection = new DocType([
            'code' => $type->code,
            'name' => $schema['form']['name'],
            'status' => $schema['form']['enabled'],
            'seq' => $schema['form']['order'],
            'buttons' => $schema['form']['buttons'],
            'form_layout' => ['rows' => $rows, 'tables' => $schema['layout']['tables']],
        ]);
        $projection->setRelation('fields', $fields);
        $projection->setRelation('statuses', $type->statuses);

        return $projection;
    }

    public function isCurrentRecordRevision(DocType $type, DocRecord $record): bool
    {
        if ($type->published_revision_id === null) {
            return true;
        }

        return (int) $record->form_revision_id === (int) $type->published_revision_id;
    }

    public function publishedRevisionId(DocType $type): ?int
    {
        return $type->published_revision_id === null ? null : (int) $type->published_revision_id;
    }

    private function appendRevision(
        DocType $type,
        array $schema,
        string $kind,
        int $creatorId,
        ?string $summary = null,
        ?int $publisherId = null,
        mixed $publishedAt = null,
        ?int $parentRevisionId = null,
    ): DocTypeRevision {
        $revision = (int) $type->revisions()->max('revision') + 1;
        $sourceRevisionId = $parentRevisionId ?? $type->working_revision_id ?? $type->published_revision_id;
        $record = DocTypeRevision::query()->create([
            'doc_type_id' => $type->id,
            'revision' => $revision,
            'kind' => $kind,
            'schema_version' => (int) $schema['schema_version'],
            'schema_json' => $schema,
            'parent_revision_id' => $sourceRevisionId,
            'change_summary' => $summary === null ? null : mb_substr(trim($summary), 0, 255),
            'created_by' => $creatorId,
            'published_by' => $publisherId,
            'published_at' => $publishedAt,
            'created_at' => now(),
        ]);
        $type->forceFill(['working_revision_id' => $record->id])->save();

        return $record;
    }

    private function assertSuperAdmin(Authenticatable $actor): void
    {
        if (! DocumentBuilder::isSuperAdmin($actor)) {
            throw new AuthorizationException('只有超級管理員可以審核、發布或回復表單修訂');
        }
    }
}
