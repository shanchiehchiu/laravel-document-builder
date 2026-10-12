@php($labelLeft = $labelLeft ?? false)
<div class="row">
    {{-- 單號一律顯示：新增時還沒有號碼，標示存檔時自動產生，和設計器畫布的固定列一致 --}}
    <div class="col-md-4 mb-4">
        @if($labelLeft)
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 flex-shrink-0" style="width: 7em;">單號</label>
            <div class="flex-grow-1" style="min-width: 0;"><div class="form-control-plaintext {{ $record ? '' : 'text-muted' }}">{{ $record ? ($record->no ?? '—') : '存檔時自動產生' }}</div></div>
        </div>
        @else
        <label class="form-label">單號</label>
        <div class="form-control-plaintext {{ $record ? '' : 'text-muted' }}">{{ $record ? ($record->no ?? '—') : '存檔時自動產生' }}</div>
        @endif
    </div>
    <div class="col-md-4 mb-4">
        @if($labelLeft)
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 flex-shrink-0" style="width: 7em;">單據日期<span class="text-danger">*</span></label>
            <div class="flex-grow-1" style="min-width: 0;"><input type="date" name="doc_date" class="form-control" value="{{ $record?->doc_date?->format('Y-m-d') ?? now()->format('Y-m-d') }}"></div>
        </div>
        @else
        <label class="form-label">單據日期<span class="text-danger">*</span></label>
        <input type="date" name="doc_date" class="form-control" value="{{ $record?->doc_date?->format('Y-m-d') ?? now()->format('Y-m-d') }}">
        @endif
    </div>
</div>
@include('document-builder::records._runtime_layout')
