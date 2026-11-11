{{-- Título de seção do menu lateral, que abre e fecha a lista de links da seção. Parâmetro: titulo. --}}
<button type="button" class="menu-secao" aria-expanded="true" title="Mostrar ou esconder {{ $titulo }}">
  <span class="menu-secao-texto">{{ $titulo }}</span>@include('layouts.partials.icone', ['nome' => 'seta', 'classe' => 'menu-secao-seta'])
</button>
