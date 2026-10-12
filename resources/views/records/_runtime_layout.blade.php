@if(empty($rows))
<div class="text-muted mb-4">這種單據還沒有設定欄位，請到「單據建置 → 單據設定 → 設計表單」新增。</div>
@else
<style>.db-section-body label { color: inherit; }</style>
@foreach(\DocumentBuilder\Services\FormLayoutService::sectionBlocks($rows) as $block)
    @if($block['section'] !== null)
        @php($style = \DocumentBuilder\Services\FormLayoutService::sectionStyle($block['section']['bg'] ?? null))
        {{-- 區塊：卡片（沒指定底色時為淡灰底）；標題可省略，純卡片做視覺群組 --}}
        {{-- 標籤繼承卡片的文字顏色，深色底上才看得清楚 --}}
        <div class="db-form-section mb-4" style="background: {{ $style['bg'] }}; border: 1px solid {{ $style['border'] }}; color: {{ $style['title'] }}; border-radius: .5rem; padding: 1rem 1.25rem;">
            @if(($block['section']['text'] ?? '') !== '')
            <div class="fw-semibold mb-3" style="color: {{ $style['title'] }};">{{ $block['section']['text'] }}</div>
            @endif
            <div class="db-section-body">
                @include('document-builder::records._runtime_rows', ['rows' => $block['rows']])
            </div>
        </div>
    @else
        @include('document-builder::records._runtime_rows', ['rows' => $block['rows']])
    @endif
@endforeach
@endif
