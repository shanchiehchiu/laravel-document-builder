@extends(config('document_builder.layout'))

@section('content')
<div class="block block-rounded">
    <div class="block-header block-header-default">
        <h3 class="block-title">{{ $type->name }}－版本紀錄</h3>
        <a class="btn btn-secondary" href="{{ route(document_builder_route_name('document_builder.designer.edit'), $type) }}">返回設計器</a>
    </div>
    <div class="block-content block-content-full">
        @if($revisions->isEmpty())
        <p class="text-muted mb-0">尚無修訂紀錄；第一次明確儲存設計器後會建立草稿版本。</p>
        @else
        <div class="table-responsive">
            <table class="table table-bordered table-vcenter">
                <thead>
                    <tr>
                        <th>版本</th>
                        <th>類型</th>
                        <th>摘要</th>
                        <th>建立者</th>
                        <th>建立時間</th>
                        <th>審核狀態</th>
                        <th>發布者／時間</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($revisions as $revision)
                    <tr>
                        <td>第 {{ $revision->revision }} 版</td>
                        <td>
                            @if($revision->id === $publishedRevisionId)<span class="badge bg-success">目前發布</span>
                            @elseif($revision->id === $workingRevisionId)<span class="badge bg-warning text-dark">目前草稿</span>
                            @else<span class="badge bg-secondary">{{ $revision->kind === 'published' ? '已發布快照' : '歷史草稿' }}</span>
                            @endif
                        </td>
                        <td>{{ $revision->change_summary ?: '—' }}</td>
                        <td>{{ \DocumentBuilder\DocumentBuilder::userName($revision->creator) ?? '已刪除的使用者' }}</td>
                        <td class="text-nowrap">{{ $revision->created_at?->format('Y-m-d H:i') }}</td>
                        <td>
                            @if($revision->latestReview?->decision === 'approved')
                            <span class="badge bg-success">審核通過</span>
                            @elseif($revision->latestReview?->decision === 'changes_requested')
                            <span class="badge bg-warning text-dark">要求修改</span>
                            @else
                            <span class="badge bg-secondary">待審核</span>
                            @endif
                            @if($revision->latestReview)
                            <div class="small text-muted">{{ \DocumentBuilder\DocumentBuilder::userName($revision->latestReview->reviewer) ?? '已刪除的使用者' }}／{{ $revision->latestReview->reviewed_at?->format('Y-m-d H:i') }}</div>
                            @endif
                        </td>
                        <td>{{ \DocumentBuilder\DocumentBuilder::userName($revision->publisher) }}{{ $revision->published_at ? '／'.$revision->published_at->format('Y-m-d H:i') : '' }}</td>
                        <td><a class="btn btn-sm btn-outline-primary" href="{{ route(document_builder_route_name('document_builder.revisions.show'), [$type, $revision]) }}">查看 Schema</a></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection
