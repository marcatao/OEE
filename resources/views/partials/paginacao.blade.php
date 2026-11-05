@if ($paginator->hasPages())
  <nav class="paginacao" aria-label="Paginação">
    @if ($paginator->onFirstPage())<span>‹ Anterior</span>@else<a href="{{ $paginator->previousPageUrl() }}">‹ Anterior</a>@endif
    <span>Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
    @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}">Próxima ›</a>@else<span>Próxima ›</span>@endif
  </nav>
@endif
