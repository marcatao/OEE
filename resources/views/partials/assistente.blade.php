{{-- "Pergunte para Alumbra": assistente de IA (Claude) da página inicial. Só responde sobre o portal, com os dados que o
     usuário pode ver. Conversa, tabelas (com CSV) e gráficos: public/js/assistente.js. --}}
@php
  $sugestoes = array_values(array_filter([
    $u->pode('dashboard_maquina') ? 'Qual foi o OEE de cada máquina ontem? Mostre num gráfico.' : null,
    ($u->pode('timeline') || $u->pode('apontamentos')) ? 'Quais máquinas estão paradas agora e desde quando?' : null,
    $u->pode('apontamentos') ? 'Monte um relatório das paradas desta semana por máquina e legenda.' : null,
    $u->pode('ordens_producao') ? 'Quais OPs abertas estão com o custo acima da meta?' : null,
    ($u->pode('os_painel') || $u->pode('os_aprovar') || $u->pode('os_solicitar')) ? 'Quantas OS estão abertas por máquina?' : null,
    'Como o OEE é calculado no portal?',
  ]));
@endphp
<section class="cartao assistente" id="assistente" data-assistente
  data-url-conversa="{{ route('assistente.conversa') }}"
  data-url-perguntar="{{ route('assistente.perguntar') }}"
  data-url-etapa="{{ route('assistente.etapa', ['pergunta' => '__ID__']) }}"
  data-csrf="{{ csrf_token() }}">
  <div class="assistente-topo">
    <div class="assistente-marca">
      <img src="{{ asset('img/alumbra-icone.svg') }}" alt="">
      <div>
        <h3>Pergunte para Alumbra</h3>
        <small class="suave">Assistente de IA do {{ config('oee.nome') }}: responde sobre o portal com os dados que você pode ver.</small>
      </div>
    </div>
    <button type="button" class="botao secundario pequeno" data-assistente-nova hidden>@include('layouts.partials.icone', ['nome' => 'mais']) Nova conversa</button>
  </div>

  <div class="assistente-conversa" data-assistente-conversa aria-live="polite">
    <div class="assistente-vazio" data-assistente-vazio>
      <p class="suave">Peça um número, um relatório ou um gráfico. Experimente:</p>
      <div class="assistente-sugestoes">
        @foreach ($sugestoes as $s)
          <button type="button" class="assistente-sugestao" data-assistente-sugestao>{{ $s }}</button>
        @endforeach
      </div>
    </div>
  </div>

  <form class="assistente-form" data-assistente-form>
    <label class="sr-only" for="assistente-pergunta">Sua pergunta</label>
    <textarea id="assistente-pergunta" name="pergunta" rows="1" maxlength="2000" placeholder="Pergunte sobre máquinas, OEE, OPs, custos, apontamentos ou ordens de serviço…" required></textarea>
    <button type="submit" class="botao" data-assistente-enviar>Perguntar</button>
  </form>
  <p class="assistente-rodape suave"><small>As respostas são geradas por IA (Claude) a partir dos dados do portal. Confira os números importantes nas telas. <span data-assistente-restantes></span></small></p>
</section>
<script src="{{ asset('js/assistente.js') }}?v={{ @filemtime(public_path('js/assistente.js')) }}" defer></script>
