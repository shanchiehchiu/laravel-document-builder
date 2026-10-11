<div class="col-md-{{ $width }} mb-4">
    <label class="form-label">
        {{ $field->label }}
        @if($field->required && $field->type !== 'checkbox')<span class="text-danger">*</span>@endif
    </label>
    @include('document-builder::records._input', [
        'name' => "data[{$field->key}]",
        'inputId' => "field-{$field->key}",
        'eventKey' => $field->section === 'header' ? $field->key : null,
    ])
</div>
