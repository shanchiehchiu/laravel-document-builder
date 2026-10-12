@php($labelLeft = $labelLeft ?? false)
<div class="col-md-{{ $width }} mb-4">
    @if($labelLeft)
    {{-- 標籤在左側：標籤寬度依文字長度，欄位接在後面，垂直置中 --}}
    <div class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 text-nowrap flex-shrink-0">
            {{ $field->label }}
            @if($field->required && $field->type !== 'checkbox')<span class="text-danger">*</span>@endif
        </label>
        <div class="flex-grow-1" style="min-width: 0;">
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
