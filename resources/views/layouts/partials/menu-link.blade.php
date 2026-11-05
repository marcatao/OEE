{{-- Link do menu lateral. Parâmetros: rota, rotulo, icone e ativo (padrão de nome de rota para destacar). --}}
<a href="{{ route($rota) }}" title="{{ $rotulo }}" @class(['ativo' => request()->routeIs($ativo ?? $rota)]) @if (request()->routeIs($ativo ?? $rota)) aria-current="page" @endif>
  @include('layouts.partials.icone', ['nome' => $icone])<span class="menu-rotulo">{{ $rotulo }}</span>
</a>
