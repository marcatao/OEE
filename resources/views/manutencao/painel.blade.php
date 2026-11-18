@extends('layouts.portal')

@section('titulo', 'Painel de ordens de serviço')

@use('App\Models\Manutencao\OrdemServico')
@use('App\Services\Manutencao\HorasManutencao')
@use('App\Http\Controllers\Manutencao\OrdemServicoController')

@section('conteudo')
@php($nomeArea = config('oee.unidades')[$unidade] ?? $unidade)
@php($idade = fn ($data) => $data ? OrdemServicoController::duracao($data, now()) : '—')
<section class="cartao">
  <p class="trilha">Ordens de serviço</p>
  <div class="titulo-com-acao">
    <h1>Painel — {{ $nomeArea }}</h1>
    @if (count($unidades) > 1)
      <div class="painel-areas">
        <nav class="painel-abas" aria-label="Área">
          @foreach ($unidades as $un)
            <a href="{{ route('os.painel', ['unidade' => $un]) }}" @class(['ativo' => $un === $unidade])>{{ config('oee.unidades')[$un] ?? $un }}@if ($un === $padraoGravado) <small>(padrão)</small>@endif</a>
          @endforeach
        </nav>
        @if ($unidade !== $padraoGravado)
          <form method="post" action="{{ route('os.painel.padrao') }}">@csrf<input type="hidden" name="unidade" value="{{ $unidade }}">
            <button class="botao pequeno secundario" type="submit">Usar {{ $nomeArea }} como área padrão</button></form>
        @endif
      </div>
    @endif
  </div>
  <p class="suave">Situação agora das solicitações e ordens de serviço de {{ $nomeArea }} que você acompanha. Números dos últimos {{ \App\Services\Manutencao\PainelManutencao::DIAS }} dias onde indicado.</p>

  <div class="kpis painel-kpis">
    <a class="kpi" href="{{ route('os.solicitacoes.index', ['status' => 'pendente']) }}">
      <span class="kpi-icone {{ $pendentes->isNotEmpty() ? 'alerta' : '' }}">@include('layouts.partials.icone', ['nome' => 'solicitacao'])</span>
      <div><span>Aguardando aprovação</span><strong>{{ $pendentes->count() }}</strong><small>{{ $pendentes->isNotEmpty() ? 'mais antiga: '.$idade($pendentes->first()->criado_em) : 'nenhuma SS na fila' }}</small></div></a>
    <a class="kpi" href="{{ route('os.ordens.index', ['status' => 'em_aberto']) }}">
      <span class="kpi-icone">@include('layouts.partials.icone', ['nome' => 'ferramenta'])</span>
      <div><span>OS abertas</span><strong>{{ $abertas->count() }}</strong><small>{{ $porStatus['em_execucao'] }} em execução · {{ $porStatus['aguardando_material'] }} aguard. material</small></div></a>
    <div class="kpi">
      <span class="kpi-icone {{ $maquinasParadas ? 'alerta' : '' }}">@include('layouts.partials.icone', ['nome' => 'maquina'])</span>
      <div><span>Máquinas paradas</span><strong>{{ $maquinasParadas }}</strong><small>{{ $urgentes }} OS urgentes abertas</small></div></div>
    <div class="kpi">
      <span class="kpi-icone {{ $semResposta->isNotEmpty() ? 'alerta' : '' }}">@include('layouts.partials.icone', ['nome' => 'solicitacao'])</span>
      <div><span>Mensagens sem resposta</span><strong>{{ $semResposta->count() }}</strong><small>esperando a manutenção</small></div></div>
    <a class="kpi" href="{{ route('os.ordens.index', ['status' => 'concluida']) }}">
      <span class="kpi-icone">@include('layouts.partials.icone', ['nome' => 'check'])</span>
      <div><span>Aguardando avaliação</span><strong>{{ $aguardandoAvaliacao->count() }}</strong><small>concluídas</small></div></a>
    <div class="kpi">
      <span class="kpi-icone {{ $semMantenedor->isNotEmpty() || $preventivasAtrasadas->isNotEmpty() ? 'alerta' : '' }}">@include('layouts.partials.icone', ['nome' => 'alerta'])</span>
      <div><span>Sem mantenedor</span><strong>{{ $semMantenedor->count() }}</strong><small>{{ $preventivasAtrasadas->count() }} preventivas atrasadas</small></div></div>
  </div>

  <div class="painel-30">
    <div><span>OS encerradas ({{ \App\Services\Manutencao\PainelManutencao::DIAS }} dias)</span><strong>{{ $encerradas30 }}</strong></div>
    <div><span>Tempo médio de atendimento</span><strong>{{ $tempoMedioMin !== null ? OrdemServicoController::duracao(now()->subMinutes((int) $tempoMedioMin), now()) : '—' }}</strong><small>da emissão da OS à conclusão</small></div>
    <div><span>Horas apontadas no mês</span><strong>{{ HorasManutencao::hhmm($minutosMes) }}</strong></div>
    <div><span>Avaliações ({{ \App\Services\Manutencao\PainelManutencao::DIAS }} dias)</span>
      <strong class="painel-avaliacoes">
        @foreach (OrdemServico::AVALIACOES as $k => $r)<span class="avaliacao-{{ $k }}">{{ $r }} {{ $avaliacoes[$k] }}</span>@endforeach
      </strong></div>
  </div>
</section>

<div class="grade duas painel-grade">
  <section class="cartao">
    <h2>OS abertas por mantenedor</h2>
    @php($maior = max(1, $porMantenedor->max('total') ?? 1))
    <div class="rolagem">
    <table class="tabela painel-mantenedores">
      <thead><tr><th>Mantenedor</th><th>OS abertas</th><th class="num">Urg.</th><th class="num">Horas/mês</th></tr></thead>
      <tbody>
      @forelse ($porMantenedor as $l)
        <tr>
          <td><span class="os-pessoa">@if ($l['mantenedor']->usuario)@include('partials.avatar', ['usuario' => $l['mantenedor']->usuario])@endif <span><a href="{{ route('os.ordens.index', ['responsavel' => $l['mantenedor']->id]) }}">{{ $l['mantenedor']->nomeExibicao() }}</a>
            @if ($l['rodando'])<a class="painel-apontando" href="{{ route('os.ordens.show', $l['rodando']->ordem_servico_id) }}">● apontando agora na {{ $l['rodando']->ordemServico?->numeroFormatado() }}</a>@endif</span></span></td>
          <td>
            <div class="painel-barra" title="{{ $l['porStatus']['aberta'] }} abertas · {{ $l['porStatus']['em_execucao'] }} em execução · {{ $l['porStatus']['aguardando_material'] }} aguardando material">
              @foreach (OrdemServico::STATUS_ABERTOS as $st)
                @if ($l['porStatus'][$st])<span class="seg-{{ $st }}" style="width: {{ $l['porStatus'][$st] / $maior * 100 }}%"></span>@endif
              @endforeach
              <strong>{{ $l['total'] }}</strong>
            </div>
          </td>
          <td class="num {{ $l['urgentes'] ? 'prioridade-urgente' : 'suave' }}">{{ $l['urgentes'] }}</td>
          <td class="num">{{ HorasManutencao::hhmm($l['minutosMes']) }}</td>
        </tr>
      @empty
        <tr><td colspan="4" class="suave">Nenhum mantenedor cadastrado nesta área.</td></tr>
      @endforelse
      </tbody>
    </table>
    </div>
    <p class="painel-legenda suave"><span class="seg-aberta"></span> aberta <span class="seg-em_execucao"></span> em execução <span class="seg-aguardando_material"></span> aguardando material</p>

    <h2>OS abertas por área executante</h2>
    @php($maiorArea = max(1, $porArea->max() ?? 1))
    @forelse ($porArea as $area => $qtd)
      <div class="painel-linha-area"><span>{{ $area }}</span><div class="painel-barra"><span class="seg-em_execucao" style="width: {{ $qtd / $maiorArea * 100 }}%"></span><strong>{{ $qtd }}</strong></div></div>
    @empty
      <p class="suave">Nenhuma OS aberta.</p>
    @endforelse
  </section>

  <section class="cartao">
    <h2>Aguardando aprovação <small class="suave">({{ $pendentes->count() }})</small></h2>
    <ul class="painel-lista">
      @forelse ($pendentes->take(8) as $ss)
        <li><a href="{{ route('os.solicitacoes.show', $ss) }}"><strong>{{ $ss->numeroFormatado() }}</strong> {{ \Illuminate\Support\Str::limit($ss->titulo ?: $ss->descricao, 60) }}</a>
          <small class="suave">{{ $ss->solicitante?->nome }} · {{ $ss->areaExecutante?->nome }} · há {{ $idade($ss->criado_em) }}</small>
          @if ($ss->prioridade === 'urgente' || $ss->maquina_parada)<span class="etiqueta os-rejeitada">{{ $ss->maquina_parada ? 'máquina parada' : 'urgente' }}</span>@endif
          @if ($ss->risco_integridade)<span class="etiqueta os-risco">risco</span>@endif</li>
      @empty
        <li class="suave">Nenhuma solicitação esperando aprovação.</li>
      @endforelse
    </ul>

    <h2>Mensagens sem resposta <small class="suave">({{ $semResposta->count() }})</small></h2>
    <ul class="painel-lista">
      @forelse ($semResposta->take(8) as $item)
        <li><a href="{{ route('os.ordens.show', $item['os']) }}#interacoes"><strong>{{ $item['os']->numeroFormatado() }}</strong> {{ \Illuminate\Support\Str::limit($item['os']->titulo ?: $item['os']->descricao, 50) }}</a>
          <small class="suave">{{ $item['msg']->usuario?->nome }}: “{{ \Illuminate\Support\Str::limit($item['msg']->texto ?: 'enviou anexo', 70) }}” · há {{ $idade($item['msg']->criado_em) }}</small></li>
      @empty
        <li class="suave">Nenhuma mensagem esperando resposta.</li>
      @endforelse
    </ul>

    <h2>Urgentes e máquinas paradas <small class="suave">({{ $urgentesLista->count() }})</small></h2>
    <ul class="painel-lista">
      @forelse ($urgentesLista->take(8) as $o)
        <li><a href="{{ route('os.ordens.show', $o) }}"><strong>{{ $o->numeroFormatado() }}</strong> {{ \Illuminate\Support\Str::limit($o->titulo ?: $o->descricao, 50) }}</a>
          <small class="suave">@if ($o->maquina){{ $o->maquina->codigo }} {{ $o->maquina->nome }} · @endif{{ $o->rotuloStatus() }} · aberta há {{ $idade($o->criado_em) }}</small></li>
      @empty
        <li class="suave">Nenhuma OS urgente ou com máquina parada.</li>
      @endforelse
    </ul>

    @if ($semMantenedor->isNotEmpty() || $preventivasAtrasadas->isNotEmpty())
      <h2>Precisam de atenção</h2>
      <ul class="painel-lista">
        @foreach ($semMantenedor->take(5) as $o)
          <li><a href="{{ route('os.ordens.show', $o) }}"><strong>{{ $o->numeroFormatado() }}</strong> {{ \Illuminate\Support\Str::limit($o->titulo ?: $o->descricao, 50) }}</a> <small class="suave">sem mantenedor · há {{ $idade($o->criado_em) }}</small></li>
        @endforeach
        @foreach ($preventivasAtrasadas->take(5) as $o)
          <li><a href="{{ route('os.ordens.show', $o) }}"><strong>{{ $o->numeroFormatado() }}</strong> {{ \Illuminate\Support\Str::limit($o->titulo ?: $o->descricao, 50) }}</a> <small class="suave">preventiva prevista para {{ $o->prevista_para->format('d/m/Y') }}</small></li>
        @endforeach
      </ul>
    @endif
  </section>
</div>
@endsection
