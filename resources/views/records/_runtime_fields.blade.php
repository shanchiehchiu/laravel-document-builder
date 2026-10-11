<div class="row">
    @if($record)
    <div class="col-md-4 mb-4">
        <label class="form-label">單號</label>
        <div class="form-control-plaintext">{{ $record->no }}</div>
    </div>
    @endif
    <div class="col-md-4 mb-4">
        <label class="form-label">單據日期<span class="text-danger">*</span></label>
        <input type="date" name="doc_date" class="form-control" value="{{ $record?->doc_date?->format('Y-m-d') ?? now()->format('Y-m-d') }}">
    </div>
</div>
@include('document-builder::records._runtime_layout')
