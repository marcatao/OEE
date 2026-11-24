@extends('layouts.portal')

@section('titulo', $maquina->nome)

@use('App\Support\Formato')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção › <a href="{{ route('dashboard.maquinas', ['periodo' => $periodo->chave]) }}">Dashboard por máquina</a></p>
  <div class="titulo-com-acao">
    <div>
      <h1>{{ $maquina->nome }}
        @if (auth()->user()->ehAdmin())
          <a class="dm-engrenagem" href="{{ route('admin.maquinas.edit', $maquina) }}" title="Editar o cadastro da máquina" aria-label="Editar o cadastro da máquina">@include('layouts.partials.icone', ['nome' => 'engrenagem'])</a>
        @endif
      </h1>
      <p class="suave" style="margin:0">{{ $maquina->codigo }}@if ($maquina->localizacao()) · {{ $maquina->localizacao() }}@endif
        @if ($maquina->fabricante || $maquina->modelo) · {{ trim($maquina->fabricante.' '.$maquina->modelo) }}@endif</p>
    </div>
    <div class="filtros" style="margin:0">
      <form method="get" action="" onsubmit="return false" class="filtros" style="margin:0">
        <label>Máquina
          <select onchange="location.href=this.value">
            @foreach ($maquinas as $m)
              <option value="{{ route('dashboard.maquina', ['maquina' => $m, 'periodo' => $periodo->chave]) }}" @selected($m->id === $maquina->id)>{{ $m->nome }}</option>
            @endforeach
          </select>
        </label>
      </form>
      @include('dashboard._periodo', ['acao' => route('dashboard.maquina', $maquina)])
    </div>
  </div>

  @if ($paradaAberta)
    <p class="aviso alerta" role="status">@include('layouts.partials.icone', ['nome' => 'alerta'])
      <strong>Máquina parada</strong> desde @dataHora($paradaAberta->inicio) — {{ $paradaAberta->motivo?->descricao ?? 'sem justificativa' }}.</p>
  @endif
</section>

{{-- Panorama pelos apontamentos reais (Timeline): agora, OP, molde, tempos e as legendas do período --}}
@php($dadosMaq = $panorama['maquinas'][$maquina->id])
<div class="pm-detalhe">
  @include('dashboard._cartao-maquina', ['sit' => $panorama['situacoes'][$maquina->id] ?? [], 'dados' => $dadosMaq, 'ind' => null, 'link' => null])
  <section class="cartao">
    <h3>Tempo por legenda — {{ $periodo->rotulo() }}</h3>
    @php($maiorLeg = max(array_column($panorama['geral']['totais'], 'segundos') ?: [0]))
    @forelse ($panorama['geral']['totais'] as $t)
      <div style="margin-top:.7rem">
        <div class="barra-rotulo"><span><i class="tl-cor" style="background: {{ $t['legenda']->cor }}"></i> {{ $t['legenda']->codigo }} · {{ $t['legenda']->descricao }}
          <small class="suave">({{ \App\Models\Legenda::TIPOS[$t['legenda']->tipo] ?? $t['legenda']->tipo }})</small></span>
          <strong>{{ Formato::minutos($t['segundos'] / 60) }} · {{ Formato::pct($t['pct']) }}</strong></div>
        <div class="barra"><i style="width: {{ $maiorLeg > 0 ? round($t['segundos'] / $maiorLeg * 100, 1) : 0 }}%; background: {{ $t['legenda']->cor }}"></i></div>
      </div>
    @empty
      <p class="suave">Nenhum apontamento real desta máquina em {{ mb_strtolower($periodo->rotulo()) }}.</p>
    @endforelse
  </section>
</div>

{{-- Timeline da máquina no período do filtro (todas as legendas, como a tela Timeline) --}}
@php($fusoTl = config('oee.fuso_exibicao'))
@php($podeApontarTl = auth()->user()->pode('apontamentos'))
<section class="cartao" style="margin-top:1.25rem">
  <h3 style="margin-top:0">Timeline — {{ $periodo->rotulo() }}</h3>
  <div class="tl-grade dm-timeline">
    <div class="tl-faixa">
      @forelse ($timelineMaquina['blocos'] as $b)
        @php($legenda = $b['legenda'])
        @php($dica = $legenda->codigo.' · '.$legenda->descricao.' · '
            .\Carbon\Carbon::createFromTimestamp($b['inicio'], $fusoTl)->format('d/m H:i').'–'
            .($b['aberto'] && $b['fim'] >= now()->getTimestamp() - 60 ? 'agora' : \Carbon\Carbon::createFromTimestamp($b['fim'], $fusoTl)->format('H:i'))
            .' ('.Formato::minutos(($b['fim'] - $b['inicio']) / 60).')'
            .($b['apontamento']->ordemProducao ? ' · OP '.$b['apontamento']->ordemProducao->numero : ''))
        @php($estilo = 'left: '.$b['esquerda'].'%; width: '.$b['largura'].'%; background-color: '.$legenda->cor)
        @if ($podeApontarTl)
          <a class="tl-bloco {{ $legenda->classeTexto() }} {{ $b['aberto'] ? 'aberto' : '' }}" style="{{ $estilo }}" title="{{ $dica }}"
             href="{{ route('apontamentos.edit', ['apontamento' => $b['apontamento']]) }}"><span><b>{{ $b['apontamento']->rotuloBarra() }}</b><em class="tl-desc"> · {{ $legenda->descricao }}</em></span></a>
        @else
          <div class="tl-bloco {{ $legenda->classeTexto() }} {{ $b['aberto'] ? 'aberto' : '' }}" style="{{ $estilo }}" title="{{ $dica }}"><span><b>{{ $b['apontamento']->rotuloBarra() }}</b><em class="tl-desc"> · {{ $legenda->descricao }}</em></span></div>
        @endif
      @empty
        <span class="dm-timeline-vazia suave">Nenhum apontamento real em {{ mb_strtolower($periodo->rotulo()) }}.</span>
      @endforelse
      @if ($timelineMaquina['agora'] !== null)<i class="tl-agora" style="left: {{ $timelineMaquina['agora'] }}%" title="Agora"></i>@endif
    </div>
    <div class="tl-eixo">
      @foreach ($timelineMaquina['marcas'] as $marca)
        <span @class(['tl-marca', 'virada' => $marca['virada']]) style="left: {{ $marca['esquerda'] }}%">{{ $marca['rotulo'] }}</span>
      @endforeach
    </div>
  </div>
</section>

{{-- Produção por hora desta máquina (o mesmo cartão de Dashboards › Produção por hora) --}}
@if ($producaoHora)
  <section style="margin-top:1.25rem">
    <h3 style="margin:0 0 .5rem">Produção por hora — {{ $diaProducao->isSameDay(now(config('oee.fuso_exibicao'))) ? 'hoje' : $diaProducao->format('d/m/Y') }}</h3>
    @include('dashboard.producao-hora._maquina', ['d' => $producaoHora])
  </section>
@endif

<section class="cartao" style="margin-top:1.25rem">
  <h3>Apontamentos reais — {{ $periodo->rotulo() }}</h3>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Dia</th><th>Legenda</th><th>Início</th><th>Fim</th><th class="num">Duração</th><th>OP</th><th>Referência</th>
      @if (auth()->user()->pode('apontamentos'))<th></th>@endif</tr></thead>
    <tbody>
    @forelse ($apontamentosReais as $a)
      @php($ini = $a->inicio->copy()->setTimezone(config('oee.fuso_exibicao')))
      <tr>
        <td>{{ $ini->format('d/m/Y') }}</td>
        <td>@if ($a->legenda)<span class="tl-chip"><i class="tl-cor" style="background: {{ $a->legenda->cor }}"></i>{{ $a->legenda->codigo }}</span> <small>{{ $a->legenda->descricao }}</small>@endif</td>
        <td>{{ $ini->format('H:i:s') }}</td>
        <td>@if ($a->fim){{ $a->fim->copy()->setTimezone(config('oee.fuso_exibicao'))->format('H:i:s') }}@else<span class="etiqueta situacao-online">em aberto</span>@endif</td>
        <td class="num">{{ Formato::minutos($a->duracaoMin()) }}</td>
        <td>@if ($a->ordemProducao)<code>@include('partials.link-op', ['op' => $a->ordemProducao])</code>@elseif ($a->legenda?->exige_op)<small class="texto-alerta">sem OP</small>@else - @endif</td>
        <td>{{ $a->referencia ?: '-' }}</td>
        @if (auth()->user()->pode('apontamentos'))<td><a href="{{ route('apontamentos.edit', $a) }}">Editar</a></td>@endif
      </tr>
    @empty
      <tr><td colspan="8" class="suave">Nenhum apontamento real nesse período.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>

{{-- OEE (IoT) da máquina no período: D × P × Q pelos apontamentos reais, o IoT e as perdas apontadas (PanoramaMaquinas).
     Substitui o "OEE completo" antigo, que lia as tabelas de peças do sistema anterior (vazias). --}}
@php($oeeIot = $dadosMaq['oeeIot'] ?? null)
@php($semMeta = ($dadosMaq['pecasNaMeta'] ?? null) === null)
<div class="grade duas" style="margin-top:1.25rem">
  <section class="cartao {{ Formato::nivel($oeeIot) }}">
    <h3>OEE (IoT) — {{ $periodo->rotulo() }}</h3>
    <div class="painel-oee">
      <div class="medidor" style="--pct: {{ Formato::pct100($oeeIot) }}"><div><strong>{{ Formato::pct($oeeIot) }}</strong><span>OEE</span></div></div>
      <div class="barras">
        @foreach (['Disponibilidade' => $dadosMaq['disponibilidade'], 'Performance' => $dadosMaq['performance'] ?? null, 'Qualidade' => $dadosMaq['qualidade'] ?? null] as $rotulo => $valor)
          <div class="{{ Formato::nivel($valor === null ? null : min($valor, 1)) }}">
            <div class="barra-rotulo"><span>{{ $rotulo }}</span><strong>{{ Formato::pct($valor) }}</strong></div>
            <div class="barra"><i style="width: {{ Formato::pct100($valor) }}%"></i></div>
          </div>
        @endforeach
      </div>
    </div>
    @if ($oeeIot === null)
      <p class="suave" style="margin-bottom:0">
        @if ($dadosMaq['apontado'] <= 0)
          Sem apontamentos reais desta máquina em {{ mb_strtolower($periodo->rotulo()) }}.
        @elseif ($semMeta)
          Falta a <strong>meta</strong> para a performance: as OPs produzidas no período não têm item MO na estrutura (Produção › Mão de obra / estrutura no Protheus).
        @endif
      </p>
    @endif
  </section>

  <section class="cartao">
    <h3>Tempos e produção</h3>
    <div class="numeros">
      <div><span>Tempo produzindo</span><strong>{{ Formato::minutos(($dadosMaq['segundos']['produzindo'] ?? 0) / 60) }}</strong></div>
      <div title="Só paradas não planejadas"><span>Paradas não planejadas</span><strong>{{ Formato::minutos(($dadosMaq['segundos']['parada'] ?? 0) / 60) }}</strong></div>
      <div><span>Paradas planejadas</span><strong>{{ Formato::minutos(($dadosMaq['segundos']['planejada'] ?? 0) / 60) }}</strong></div>
      <div><span>Fora de turno</span><strong>{{ Formato::minutos(($dadosMaq['segundos']['desligada'] ?? 0) / 60) }}</strong></div>
      <div title="Peças contadas pelo IoT (inclui as perdidas)"><span>Peças (IoT)</span><strong>{{ Formato::inteiro($dadosMaq['pecasTodas'] ?? 0) }}</strong></div>
      <div title="Meta da OP × tempo produzindo (só OPs com meta)"><span>Peças na meta</span><strong>{{ ($dadosMaq['pecasNaMeta'] ?? null) === null ? '—' : Formato::inteiro($dadosMaq['pecasNaMeta']) }}</strong></div>
      <div title="Perdas apontadas (aba Apontamento de perdas da OP / app) desta máquina no período"><span>Perdas</span><strong>{{ Formato::quantidade($dadosMaq['perdas'] ?? 0) }}</strong></div>
      <div><span>Peças boas</span><strong>{{ Formato::inteiro(max(0, ($dadosMaq['pecasTodas'] ?? 0) - ($dadosMaq['perdas'] ?? 0))) }}</strong></div>
    </div>
  </section>
</div>

<section class="cartao" style="margin-top:1.25rem">
  <h3>Dispositivos IoT</h3>
  @forelse ($maquina->dispositivos as $d)
    <p style="display:flex;justify-content:space-between;gap:1rem;margin:.6rem 0">
      <span>{{ $d->nome }} <small class="suave">{{ $d->rotuloProtocolo() }}</small></span>
      <span class="etiqueta situacao-{{ $d->situacao() }}"><i class="ponto"></i>{{ $d->rotuloSituacao() }}</span>
    </p>
  @empty
    <p class="suave">Nenhum dispositivo ligado a esta máquina.</p>
  @endforelse
</section>
@endsection
