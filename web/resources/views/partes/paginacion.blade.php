@if ($paginator->hasPages())
<div class="fila">
    @if ($paginator->onFirstPage())<span class="btn sec" style="opacity:.5">Anterior</span>@else<a class="btn sec" href="{{ $paginator->previousPageUrl() }}">Anterior</a>@endif
    <span class="suave">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
    @if ($paginator->hasMorePages())<a class="btn sec" href="{{ $paginator->nextPageUrl() }}">Siguiente</a>@else<span class="btn sec" style="opacity:.5">Siguiente</span>@endif
</div>
@endif
