<?php

declare(strict_types=1);

namespace DocumentBuilder\Http\Controllers\FormPlatform;

use DocumentBuilder\DocumentBuilder;
use DocumentBuilder\Http\Controllers\Controller;
use DocumentBuilder\Models\DocType;
use DocumentBuilder\Models\DocTypeRevision;
use DocumentBuilder\FormPlatform\FormRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** 查看不可變表單修訂，並提供超級管理員發布與回復入口。 */
class FormRevisionController extends Controller
{
    public function __construct(private readonly FormRevisionService $revisions) {}

    public function index(DocType $type): View
    {
        return view('document-builder::revisions.index', [
            'type' => $type,
            'revisions' => $type->revisions()->with([
                'creator:'.DocumentBuilder::userColumns(), 'publisher:'.DocumentBuilder::userColumns(), 'latestReview.reviewer:'.DocumentBuilder::userColumns(),
            ])->limit(100)->get(),
            'workingRevisionId' => $type->working_revision_id,
            'publishedRevisionId' => $type->published_revision_id,
        ]);
    }

    public function show(DocType $type, DocTypeRevision $revision): View
    {
        abort_unless((int) $revision->doc_type_id === (int) $type->id, 404);
        $revision->load(['latestReview.reviewer:'.DocumentBuilder::userColumns(), 'reviews.reviewer:'.DocumentBuilder::userColumns()]);

        return view('document-builder::revisions.show', [
            'type' => $type,
            'revision' => $revision,
            'isWorkingDraft' => $revision->kind === 'draft'
                && (int) $type->working_revision_id === (int) $revision->id,
        ]);
    }

    public function review(Request $request, DocType $type, DocTypeRevision $revision): JsonResponse|RedirectResponse
    {
        abort_unless((int) $revision->doc_type_id === (int) $type->id, 404);
        $data = $request->validate([
            'decision' => ['required', 'in:approved,changes_requested'],
            'note' => ['required_if:decision,changes_requested', 'nullable', 'string', 'max:2000'],
        ], [], [
            'decision' => '審核結果',
            'note' => '審核意見',
        ]);
        $actor = DocumentBuilder::user();
        abort_unless($actor !== null, 403);
        $review = $this->revisions->review(
            $type,
            $revision,
            $actor,
            $data['decision'],
            $data['note'] ?? null
        );

        $message = $review->decision === 'approved' ? '審核通過；請由另一位超級管理員發布' : '已記錄修改要求';
        if (! $request->expectsJson()) {
            return redirect()->route(document_builder_route_name('document_builder.revisions.show'), [$type, $revision])
                ->with('status', $message);
        }

        return response()->json([
            'message' => $message,
            'review' => [
                'id' => $review->id,
                'decision' => $review->decision,
                'reviewer_id' => $review->reviewer_id,
                'note' => $review->note,
                'reviewed_at' => $review->reviewed_at?->toIso8601String(),
                'validation' => $review->validation_json,
            ],
        ]);
    }

    public function publish(Request $request, DocType $type): JsonResponse
    {
        $data = $request->validate(['summary' => ['nullable', 'string', 'max:255']]);
        $actor = DocumentBuilder::user();
        abort_unless($actor !== null, 403);

        $validation = $this->revisions->validateForPublish($type);
        if (! $validation['valid']) {
            return response()->json(['message' => '表單尚未通過發布驗證', 'validation' => $validation], 422);
        }

        $revision = $this->revisions->publish($type, $actor, $data['summary'] ?? null);

        return response()->json([
            'message' => '已發布表單第 '.$revision->revision.' 版',
            'revision' => $this->revisionData($revision),
            'validation' => $validation,
        ]);
    }

    public function restore(Request $request, DocType $type, DocTypeRevision $revision): JsonResponse|RedirectResponse
    {
        abort_unless((int) $revision->doc_type_id === (int) $type->id, 404);
        $actor = DocumentBuilder::user();
        abort_unless($actor !== null, 403);
        $restored = $this->revisions->restore($type, $revision, $actor);

        $data = [
            'message' => '已建立第 '.$restored->revision.' 版草稿，檢查後需再次發布',
            'revision' => $this->revisionData($restored),
        ];

        if (! $request->expectsJson()) {
            return redirect()->route(document_builder_route_name('document_builder.designer.edit'), $type)->with('status', $data['message']);
        }

        return response()->json($data);
    }

    private function revisionData(DocTypeRevision $revision): array
    {
        return [
            'id' => $revision->id,
            'revision' => $revision->revision,
            'kind' => $revision->kind,
            'schema_version' => $revision->schema_version,
            'schema' => $revision->schema_json,
            'published_by' => $revision->published_by,
            'created_at' => $revision->created_at?->toIso8601String(),
            'published_at' => $revision->published_at?->toIso8601String(),
        ];
    }
}
