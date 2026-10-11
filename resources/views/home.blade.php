@extends(config('document_builder.layout'))

@section('content')
<div class="block block-rounded">
    <div class="block-header block-header-default">
        <h3 class="block-title">{{ __('document-builder::ui.my_documents') }}</h3>
        @if($canDesign)
            <a href="{{ route(document_builder_route_name('document_builder.types.create')) }}" class="btn btn-sm btn-primary">{{ __('document-builder::ui.new_type') }}</a>
        @endif
    </div>
    <div class="block-content block-content-full">
        @if($types->isEmpty())
            <p class="text-muted mb-0">
                {{ __('document-builder::ui.no_types') }}
                @if($canDesign)
                    <a href="{{ route(document_builder_route_name('document_builder.types.create')) }}">{{ __('document-builder::ui.new_type') }}</a>
                @endif
            </p>
        @else
            <div class="row g-3">
                @foreach($types as $type)
                    <div class="col-sm-6 col-lg-4 col-xl-3">
                        <a class="db-type-card" href="{{ route(document_builder_route_name('doc_records.index'), $type->code) }}">
                            <div class="db-type-name">{{ $type->name }}</div>
                            <div class="db-type-meta">{{ __('document-builder::ui.record_count', ['count' => $type->records_count]) }}</div>
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
