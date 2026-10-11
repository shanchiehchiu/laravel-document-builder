<div class="row">
    {{-- 單號一律顯示：新增時還沒有號碼，標示存檔時自動產生，和設計器畫布的固定列一致 --}}
    <div class="col-md-4 mb-4">
        <label class="form-label">單號</label>
        <div class="form-control-plaintext {{ $record ? '' : 'text-muted' }}">{{ $record ? ($record->no ?? '—') : '存檔時自動產生' }}</div>
    </div>
    <div class="col-md-4 mb-4">
        <label class="form-label">單據日期<span class="text-danger">*</span></label>
        <input type="date" name="doc_date" class="form-control" value="{{ $record?->doc_date?->format('Y-m-d') ?? now()->format('Y-m-d') }}">
    </div>
</div>
@include('document-builder::records._runtime_layout')
