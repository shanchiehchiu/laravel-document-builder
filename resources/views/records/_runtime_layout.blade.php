@if(empty($rows))
<div class="text-muted mb-4">這種單據還沒有設定欄位，請到「單據建置 → 單據設定 → 設計表單」新增。</div>
@else
@foreach(\DocumentBuilder\Services\FormLayoutService::sectionBlocks($rows) as $block)
    @if($block['section'] !== null)
        @php($tone = \DocumentBuilder\Services\FormLayoutService::SECTION_TONES[$block['section']['tone'] ?? 'blue'] ?? \DocumentBuilder\Services\FormLayoutService::SECTION_TONES['blue'])
        {{-- 區塊：淺色卡片、左側色條；標題可省略（純卡片做視覺群組） --}}
        <div class="db-form-section mb-4" style="background: {{ $tone['bg'] }}; border: 1px solid {{ $tone['border'] }}; border-left: 4px solid {{ $tone['accent'] }}; border-radius: .5rem; padding: 1rem 1.25rem;">
            @if(($block['section']['text'] ?? '') !== '')
            <div class="fw-semibold mb-3" style="color: {{ $tone['accent'] }};">{{ $block['section']['text'] }}</div>
            @endif
            @include('document-builder::records._runtime_rows', ['rows' => $block['rows']])
        </div>
    @else
        @include('document-builder::records._runtime_rows', ['rows' => $block['rows']])
    @endif
@endforeach
@endif
