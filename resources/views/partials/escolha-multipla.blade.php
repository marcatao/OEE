{{-- Escolha de vários com busca (public/js/componentes.js). Parâmetros:
     nome (ex.: "mantenedores[]"), titulo, itens (lista de ['valor', 'nome', 'detalhe' (opcional), 'marcado' (bool),
     'fixo' (bool: sempre marcado), 'usuario' (opcional, para a foto)]), vazio (texto do resumo sem nada marcado),
     busca (placeholder), semItens (texto quando a lista está vazia) e aberto (bool). --}}
<details class="escolha" data-escolha @if (! empty($aberto)) open @endif>
  <summary>
    <span class="escolha-titulo">{{ $titulo }}</span>
    <span class="escolha-resumo suave" data-escolha-resumo data-vazio="{{ $vazio ?? 'Nenhum' }}">{{ $vazio ?? 'Nenhum' }}</span>
  </summary>
  <div class="escolha-painel">
    @if (count($itens) > 0)
      <input type="search" class="escolha-busca" data-escolha-busca placeholder="{{ $busca ?? 'Buscar por nome…' }}" aria-label="Buscar">
    @endif
    <div class="escolha-lista">
      @forelse ($itens as $item)
        <label class="escolha-item" data-escolha-item data-nome="{{ $item['nome'] }}">
          <input type="checkbox" name="{{ $nome }}" value="{{ $item['valor'] }}" @checked(! empty($item['marcado']) || ! empty($item['fixo']))
            @if (! empty($item['fixo'])) onclick="return false" @endif>
          @if (! empty($item['usuario']))@include('partials.avatar', ['usuario' => $item['usuario']])@endif
          <span class="escolha-texto"><strong>{{ $item['nome'] }}</strong>@if (! empty($item['fixo'])) <small class="suave">(você — fica atribuído)</small>@endif
            @if (! empty($item['detalhe']))<small class="suave">{{ $item['detalhe'] }}</small>@endif</span>
        </label>
      @empty
        <p class="suave">{{ $semItens ?? 'Nada para escolher.' }}</p>
      @endforelse
      <p class="suave" data-escolha-vazio hidden>Nada encontrado.</p>
    </div>
  </div>
</details>
