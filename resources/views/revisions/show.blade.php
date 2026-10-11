@extends(config('document_builder.layout'))

@section('content')
<div class="block block-rounded">
    <div class="block-header block-header-default">
        <h3 class="block-title">{{ $revision->schema_json['form']['name'] ?? $type->name }}－第 {{ $revision->revision }} 版 Schema</h3>
        <a class="btn btn-secondary" href="{{ route(document_builder_route_name('document_builder.revisions.index'), $type) }}">返回版本紀錄</a>
    </div>
    <div class="block-content block-content-full">
        <dl class="row">
            <dt class="col-sm-2">版本類型</dt>
            <dd class="col-sm-10">{{ $revision->kind === 'published' ? '發布快照' : '草稿快照' }}</dd>
            <dt class="col-sm-2">變更摘要</dt>
            <dd class="col-sm-10">{{ $revision->change_summary ?: '—' }}</dd>
            <dt class="col-sm-2">建立時間</dt>
            <dd class="col-sm-10">{{ $revision->created_at?->format('Y-m-d H:i:s') }}</dd>
            @if($revision->published_at)
            <dt class="col-sm-2">發布時間</dt>
            <dd class="col-sm-10">{{ $revision->published_at->format('Y-m-d H:i:s') }}</dd>
            @endif
        </dl>
        @if($isWorkingDraft && \DocumentBuilder\DocumentBuilder::isSuperAdmin())
        <div class="alert alert-info">
            草稿須先通過審核，再由另一位超級管理員發布。審核者不能發布自己審核的版本。
        </div>
        <div class="row g-3 mb-4">
            <div class="col-lg-6">
                <form method="post" action="{{ route(document_builder_route_name('document_builder.revisions.review'), [$type, $revision]) }}" class="border rounded p-3 h-100">
                    @csrf
                    <input type="hidden" name="decision" value="approved">
                    <label class="form-label" for="review-note-approved">審核備註（選填）</label>
                    <textarea class="form-control mb-3" id="review-note-approved" name="note" rows="3"></textarea>
                    <button type="submit" class="btn btn-success">審核通過</button>
                </form>
            </div>
            <div class="col-lg-6">
                <form method="post" action="{{ route(document_builder_route_name('document_builder.revisions.review'), [$type, $revision]) }}" class="border rounded p-3 h-100">
                    @csrf
                    <input type="hidden" name="decision" value="changes_requested">
                    <label class="form-label" for="review-note-changes">修改意見（必填）</label>
                    <textarea class="form-control mb-3" id="review-note-changes" name="note" rows="3" required></textarea>
                    <button type="submit" class="btn btn-warning">要求修改</button>
                </form>
            </div>
        </div>
        @endif
        @if($revision->reviews->isNotEmpty())
        <h4 class="fs-6">審核紀錄</h4>
        <div class="list-group mb-4">
            @foreach($revision->reviews as $review)
            <div class="list-group-item">
                <div class="d-flex justify-content-between gap-3">
                    <strong>{{ $review->decision === 'approved' ? '審核通過' : '要求修改' }}</strong>
                    <span class="text-muted">{{ $review->reviewer?->name ?: '已刪除的管理員' }}／{{ $review->reviewed_at?->format('Y-m-d H:i:s') }}</span>
                </div>
                @if($review->note)<p class="mb-1 mt-2">{{ $review->note }}</p>@endif
                @if(!empty($review->validation_json['warnings']))
                <ul class="small text-warning-emphasis mb-0">
                    @foreach($review->validation_json['warnings'] as $warning)
                    <li>{{ $warning['path'] }}：{{ $warning['message'] }}</li>
                    @endforeach
                </ul>
                @endif
            </div>
            @endforeach
        </div>
        @endif
        @if(\DocumentBuilder\DocumentBuilder::isSuperAdmin())
        <form method="post" action="{{ route(document_builder_route_name('document_builder.revisions.restore'), [$type, $revision]) }}" class="mb-3">
            @csrf
            <button type="submit" class="btn btn-outline-warning">複製成新草稿</button>
            <span class="text-muted ms-2">不會改變目前發布版本；回復後須重新審核，並由不同管理員發布。</span>
        </form>
        @endif
        <h4 class="fs-6">不可變 Schema 快照</h4>
        <pre class="bg-light p-3 rounded"><code>{{ json_encode($revision->schema_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
    </div>
</div>
@endsection
