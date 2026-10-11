@extends(config('document_builder.layout'))

@section('content')
<div class="block block-rounded">
    <div class="block-header block-header-default">
        <h3 class="block-title">{{ __('document-builder::ui.create') }}</h3>
    </div>
    <div class="block-content block-content-full">
        <form id="form-create" action="{{ route(document_builder_route_name('document_builder.types.store')) }}" method="post">
            @csrf
            <div class="row">
                <div class="col-md-4 mb-4">
                    <label class="form-label">單據名稱<span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" maxlength="50" placeholder="例如：維修單">
                </div>
                <div class="col-md-4 mb-4">
                    <label class="form-label">單據代碼<span class="text-danger">*</span></label>
                    <input type="text" name="code" class="form-control" maxlength="30" placeholder="例如：repair">
                    <div class="form-text">用在網址，只可以用小寫英文、數字、底線，建立後不得修改</div>
                </div>
                <div class="col-md-4 mb-4">
                    <label class="form-label">選單排序</label>
                    <input type="number" name="seq" class="form-control" min="0" value="0">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">{{ __('document-builder::ui.save') }}</button>
            <a href="{{ route(document_builder_route_name('document_builder.types.index')) }}" class="btn btn-secondary">{{ __('document-builder::ui.back_list') }}</a>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    var $form = $('#form-create');
    // 建立後直接進設計器拉欄位
    var editUrl = '{{ route(document_builder_route_name('document_builder.designer.edit'), '___ID___') }}';
    $form.ajaxForm({
        beforeSubmit: function () { $form.find('button[type=submit]').attr('disabled', true); },
        success: function (data) {
            Swal.fire({ text: data.message, icon: 'success' }).then(function () {
                location.href = editUrl.replace('___ID___', data.id);
            });
        },
        complete: function () { $form.find('button[type=submit]').attr('disabled', false); }
    });
});
</script>
@endpush
