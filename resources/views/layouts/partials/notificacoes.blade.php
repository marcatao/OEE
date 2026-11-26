{{-- Sino de notificações do topo (pedido do usuário em 26/11/2026): substitui as faixas de aviso no topo das páginas
     (OS para avaliar, termos para assinar) e mostra as SS para atender. Lista inicial do servidor; o navegador atualiza
     a cada minuto pela rota `notificacoes` e mostra pop-up no Windows com o navegador minimizado
     (public/js/componentes.js, [data-notificacoes]; App\Services\Notificacoes\NotificacoesUsuario). --}}
@php($notificacoes = rescue(fn () => app(\App\Services\Notificacoes\NotificacoesUsuario::class)->para(auth()->user()), [], false))
<div class="notificacoes" data-notificacoes data-url="{{ route('notificacoes') }}" data-icone="{{ asset('img/alumbra-icone.svg') }}">
  <button type="button" class="topo-icone notificacoes-botao" data-notificacoes-botao aria-expanded="false" aria-controls="notificacoes-painel"
          title="Notificações" aria-label="Notificações{{ $notificacoes ? ' ('.count($notificacoes).' novas)' : '' }}">
    @include('layouts.partials.icone', ['nome' => 'sino'])
    <span class="notificacoes-contador" data-notificacoes-contador @if (! $notificacoes) hidden @endif>{{ count($notificacoes) > 99 ? '99+' : count($notificacoes) }}</span>
  </button>
  <div class="notificacoes-painel" id="notificacoes-painel" data-notificacoes-painel hidden role="dialog" aria-label="Notificações">
    <div class="notificacoes-cabecalho">
      <strong>Notificações</strong>
      <button type="button" class="botao secundario pequeno" data-notificacoes-ativar hidden>Avisar no Windows</button>
    </div>
    <p class="notificacoes-dica suave" data-notificacoes-dica hidden></p>
    <ul class="notificacoes-lista" data-notificacoes-lista>
      @forelse ($notificacoes as $n)
        <li class="{{ $n['urgente'] ? 'urgente' : '' }}"><a href="{{ $n['url'] }}"><strong>{{ $n['titulo'] }}</strong><span>{{ $n['texto'] }}</span></a></li>
      @empty
        <li class="notificacoes-vazia suave">Nenhuma notificação.</li>
      @endforelse
    </ul>
    <script type="application/json" data-notificacoes-dados>@json($notificacoes)</script>
  </div>
</div>
