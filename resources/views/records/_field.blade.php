@php($labelLeft = $labelLeft ?? false)
<div class="col-md-{{ $width }} mb-4">
    @if($labelLeft)
    {{-- 標籤在左側：標籤欄固定 7 個字寬，同一張單據的欄位對齊；較長的標籤換行 --}}
    <div class="d-flex align-items-start gap-2">
        <label class="form-label mb-0 flex-shrink-0 d-flex align-items-center" style="width: 7em; min-height: 38px;">
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
