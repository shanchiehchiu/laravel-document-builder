@php($labelLeft = $labelLeft ?? false)
<div class="col-md-{{ $width }} mb-4">
    @if($labelLeft)
    {{-- 標籤在左側：標籤佔約 1/3 欄寬，欄位佔其餘，垂直置中 --}}
    <div class="row align-items-center g-2">
        <div class="col-4">
            <label class="form-label mb-0">
                {{ $field->label }}
                @if($field->required && $field->type !== 'checkbox')<span class="text-danger">*</span>@endif
            </label>
        </div>
        <div class="col-8">
            @include('document-builder::records._input', [
                'name' => "data[{$field->key}]",
                'inputId' => "field-{$field->key}",
                'eventKey' => $field->section === 'header' ? $field->key : null,
            ])
        </div>
    </div>
    @else
    <label class="form-label">
        {{ $field->label }}
        @if($field->required && $field->type !== 'checkbox')<span class="text-danger">*</span>@endif
    </label>
    @include('document-builder::records._input', [
        'name' => "data[{$field->key}]",
        'inputId' => "field-{$field->key}",
        'eventKey' => $field->section === 'header' ? $field->key : null,
    ])
    @endif
</div>
