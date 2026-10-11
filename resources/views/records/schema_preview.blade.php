<section class="doc-schema-preview" aria-label="表單預覽">
    <h3 class="fs-5 mb-3">{{ $formName }}</h3>
    <form data-form-events="{{ json_encode($events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}">
        @include('document-builder::records._runtime_fields')
    </form>
</section>
<script src="{{ \DocumentBuilder\Support\Assets::url('form-events.js') }}"></script>
