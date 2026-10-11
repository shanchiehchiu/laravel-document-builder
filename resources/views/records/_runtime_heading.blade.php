<div class="col-md-{{ $col['width'] }} mb-3">
    @if($col['style'])
    <h4 class="fs-5 fw-semibold rounded px-3 py-2 mb-0" style="{{ $col['style'] }}">{{ $col['text'] }}</h4>
    @else
    <h4 class="fs-5 fw-semibold border-bottom pb-2 mb-0">{{ $col['text'] }}</h4>
    @endif
</div>
