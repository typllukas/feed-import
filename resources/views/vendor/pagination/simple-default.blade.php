@if ($paginator->hasPages())
    <nav class="pagination">
        @if ($paginator->onFirstPage())
            <span class="muted">{!! __('pagination.previous') !!}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">{!! __('pagination.previous') !!}</a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">{!! __('pagination.next') !!}</a>
        @else
            <span class="muted">{!! __('pagination.next') !!}</span>
        @endif
    </nav>
@endif
