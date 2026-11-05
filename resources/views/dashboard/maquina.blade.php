@extends('layouts.portal')

@section('titulo', $maquina->nome)

@use('App\Support\Formato')

@section('conteudo')
@php($oee = $ind->oee())
<section class="cartao">
  <p class="trilha">Produção › <a href="{{ route('dashboard.maquinas', ['periodo' => $periodo->chave]) }}">Dashboard por máquina</a></p>
  <div class="titulo-com-acao">
    <div>
      <h1>{{ $maquina->nome }}</h1>
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
  @if ($ind->performanceAcimaDoIdeal())
    <p class="aviso alerta">A produção passou do ciclo ideal cadastrado (performance acima de 100%). Revise o ciclo ideal da máquina.</p>
  @endif
</section>

@if (! $ind->temDados())
  <section class="cartao vazio">
    @include('layouts.partials.icone', ['nome' => 'dashboard'])
    <p>Sem apontamentos de produção desta máquina em <strong>{{ mb_strtolower($periodo->rotulo()) }}</strong>.</p>
    <p><small>Os apontamentos chegam pelos dispositivos IoT ligados à máquina.</small></p>
  </section>
@else
  <div class="grade duas">
    <section class="cartao {{ Formato::nivel($oee) }}">
      <h3>OEE — {{ $periodo->rotulo() }}</h3>
      <div class="painel-oee">
        <div class="medidor" style="--pct: {{ Formato::pct100($oee) }}"><div><strong>{{ Formato::pct($oee) }}</strong><span>OEE</span></div></div>
        <div class="barras">
          @foreach (['Disponibilidade' => $ind->disponibilidade(), 'Performance' => $ind->performance(), 'Qualidade' => $ind->qualidade()] as $rotulo => $valor)
            <div class="{{ Formato::nivel($valor) }}">
              <div class="barra-rotulo"><span>{{ $rotulo }}</span><strong>{{ Formato::pct($valor) }}</strong></div>
              <div class="barra"><i style="width: {{ Formato::pct100($valor) }}%"></i></div>
            </div>
          @endforeach
        </div>
      </div>
    </section>

    <section class="cartao">
      <h3>Tempos e produção</h3>
      <div class="numeros">
        <div><span>Tempo planejado</span><strong>{{ Formato::minutos($ind->tempoPlanejadoMin()) }}</strong></div>
        <div><span>Tempo operando</span><strong>{{ Formato::minutos($ind->tempoOperandoMin()) }}</strong></div>
        <div><span>Paradas não planejadas</span><strong>{{ Formato::minutos($ind->paradasNaoPlanejadasMin) }}</strong></div>
        <div><span>Paradas planejadas</span><strong>{{ Formato::minutos($ind->paradasPlanejadasMin) }}</strong></div>
        <div><span>Total produzido</span><strong>{{ Formato::inteiro($ind->totalProduzido) }}</strong></div>
        <div><span>Peças boas</span><strong>{{ Formato::inteiro($ind->pecasBoas) }}</strong></div>
        <div><span>Refugo</span><strong>{{ Formato::inteiro($ind->refugo()) }}</strong></div>
        <div><span>Apontamentos</span><strong>{{ Formato::inteiro($ind->apontamentos) }}</strong></div>
      </div>
    </section>
  </div>

  <div class="grade duas" style="margin-top:1.25rem">
    <section class="cartao">
      <h3>Paradas por motivo</h3>
      @php($maior = max(array_column($ind->pareto, 'minutos') ?: [0]))
      @forelse ($ind->pareto as $linha)
        <div style="margin-top:.75rem">
          <div class="barra-rotulo"><span>{{ $linha['motivo'] }} @if ($linha['planejada'])<small class="suave">(planejada)</small>@endif</span>
            <strong>{{ Formato::minutos($linha['minutos']) }} · {{ $linha['ocorrencias'] }}×</strong></div>
          <div class="barra {{ $linha['planejada'] ? 'nivel-sem' : 'nivel-ruim' }}"><i style="width: {{ $maior > 0 ? round($linha['minutos'] / $maior * 100, 1) : 0 }}%"></i></div>
        </div>
      @empty
        <p class="suave">Nenhuma parada no período.</p>
      @endforelse
    </section>

    <section class="cartao">
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
  </div>

  <section class="cartao" style="margin-top:1.25rem">
    <h3>Últimos apontamentos</h3>
    <div class="rolagem">
    <table class="tabela">
      <thead><tr><th>Início</th><th>Fim</th><th class="num">Ciclo ideal (s)</th><th class="num">Produzido</th><th class="num">Boas</th><th class="num">Refugo</th></tr></thead>
      <tbody>
      @foreach ($apontamentos as $a)
        <tr>
          <td>@dataHora($a->inicio)</td><td>@dataHora($a->fim)</td>
          <td class="num">{{ number_format($a->ciclo_ideal_s, 1, ',', '.') }}</td>
          <td class="num">{{ Formato::inteiro($a->total_produzido) }}</td>
          <td class="num">{{ Formato::inteiro($a->pecas_boas) }}</td>
          <td class="num">{{ Formato::inteiro($a->total_produzido - $a->pecas_boas) }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  </section>
@endif
@endsection
