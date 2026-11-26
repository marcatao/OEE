@extends('layouts.portal')

@section('titulo', 'Painel de logins')

@php
  $maxDia = max(1, collect($porDia)->max(fn ($d) => $d['entradas'] + $d['recusas']));
  $maxHora = max(1, max($porHora));
  $passoRotulo = (int) ceil(count($porDia) / 10);
  $situacoes = ['nunca' => ['Nunca entrou', 'erro'], 'sem_uso' => ['Sem uso', 'alerta'], 'hoje' => ['Usou hoje', 'ok'], 'em_uso' => ['Em uso', 'suave']];
@endphp

@section('conteudo')
<section class="cartao">
  <p class="trilha">Segurança</p>
  <h1>Painel de logins</h1>
  <p>Entradas no portal, recusas, usuários ativos e o último uso de cada usuário (login, página aberta ou aplicativo).
    Dias e horas no horário local.</p>

  <form method="get" class="filtros">
    <label>Período
      <select name="dias">
        @foreach ($periodos as $p)<option value="{{ $p }}" @selected($dias === $p)>Últimos {{ $p }} dias</option>@endforeach
      </select>
    </label>
    <label>Sem uso há mais de (dias) <input type="number" name="sem_uso" min="1" max="3650" value="{{ $semUsoDias }}"></label>
    @if ($mostrar)<input type="hidden" name="mostrar" value="{{ $mostrar }}">@endif
    <button class="botao" type="submit">Atualizar</button>
  </form>

  <div class="kpis">
    <div class="kpi"><div class="kpi-icone">@include('layouts.partials.icone', ['nome' => 'usuarios'])</div>
      <div><span>Usuários ativos hoje</span><strong>{{ $ativosHoje }}</strong><small>portal ou aplicativo</small></div></div>
    <div class="kpi"><div class="kpi-icone">@include('layouts.partials.icone', ['nome' => 'logins'])</div>
      <div><span>Entradas hoje</span><strong>{{ $entradasHoje }}</strong><small>{{ $entradasPeriodo }} em {{ $dias }} dias</small></div></div>
    <div class="kpi"><div @class(['kpi-icone', 'alerta' => $recusasPeriodo > 0])>@include('layouts.partials.icone', ['nome' => 'alerta'])</div>
      <div><span>Recusas em {{ $dias }} dias</span><strong>{{ $recusasPeriodo }}</strong></div></div>
    <a class="kpi" href="{{ route('cadastros.painel-acessos', ['dias' => $dias, 'sem_uso' => $semUsoDias, 'mostrar' => 'nunca']) }}#usuarios">
      <div @class(['kpi-icone', 'alerta' => $nuncaEntraram > 0])>@include('layouts.partials.icone', ['nome' => 'perfil'])</div>
      <div><span>Ativos que nunca entraram</span><strong>{{ $nuncaEntraram }}</strong></div></a>
    <a class="kpi" href="{{ route('cadastros.painel-acessos', ['dias' => $dias, 'sem_uso' => $semUsoDias, 'mostrar' => 'sem_uso']) }}#usuarios">
      <div @class(['kpi-icone', 'alerta' => $semUso > 0])>@include('layouts.partials.icone', ['nome' => 'relogio'])</div>
      <div><span>Sem uso há {{ $semUsoDias }}+ dias</span><strong>{{ $semUso }}</strong><small>candidatos a bloqueio</small></div></a>
  </div>
</section>

<div class="grade duas">
  <section class="cartao">
    <h2>Entradas por dia</h2>
    <div class="grafico-colunas" role="img" aria-label="Entradas e recusas por dia">
      @foreach ($porDia as $d)
        <div title="{{ $d['dia']->format('d/m') }}: {{ $d['entradas'] }} entrada(s), {{ $d['usuarios'] }} usuário(s), {{ $d['recusas'] }} recusa(s)">
          @if ($d['recusas'])<i class="recusa" style="height: {{ round($d['recusas'] / $maxDia * 100, 1) }}%"></i>@endif
          @if ($d['entradas'])<i style="height: {{ round($d['entradas'] / $maxDia * 100, 1) }}%"></i>@endif
        </div>
      @endforeach
    </div>
    <div class="grafico-eixo">
      @foreach ($porDia as $i => $d)<span>{{ $i % $passoRotulo === 0 ? $d['dia']->format('d/m') : '' }}</span>@endforeach
    </div>
    <div class="grafico-legenda"><span><i></i>Entradas</span><span><i class="recusa"></i>Recusas</span></div>
  </section>

  <section class="cartao">
    <h2>Entradas por hora do dia <small class="suave">({{ $dias }} dias)</small></h2>
    <div class="grafico-colunas" role="img" aria-label="Entradas por hora do dia">
      @foreach ($porHora as $h => $n)
        <div title="{{ sprintf('%02d', $h) }}h: {{ $n }} entrada(s)">@if ($n)<i style="height: {{ round($n / $maxHora * 100, 1) }}%"></i>@endif</div>
      @endforeach
    </div>
    <div class="grafico-eixo">
      @foreach ($porHora as $h => $n)<span>{{ $h % 3 === 0 ? sprintf('%02d', $h) : '' }}</span>@endforeach
    </div>
  </section>

  <section class="cartao">
    <h2>Recusas por motivo</h2>
    @if ($recusasPorMotivo->isEmpty())
      <p class="suave">Nenhuma recusa no período.</p>
    @else
    <table class="tabela">
      <thead><tr><th>Motivo</th><th class="num">Tentativas</th></tr></thead>
      <tbody>
      @foreach ($recusasPorMotivo as $motivo => $n)
        <tr><td class="quebra">
          @if (auth()->user()->pode('seguranca_historico'))
            <a href="{{ route('cadastros.acessos', ['resultado' => 'recusado', 'motivo' => $motivo === 'sem motivo informado' ? '-' : $motivo, 'de' => $inicio->copy()->setTimezone(config('oee.fuso_exibicao'))->format('Y-m-d')]) }}">{{ $motivo }}</a>
          @else {{ $motivo }} @endif
        </td><td class="num">{{ $n }}</td></tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </section>

  <section class="cartao">
    <h2>Páginas mais abertas</h2>
    @if ($paginas->isEmpty())
      <p class="suave">Nenhuma página registrada no período.</p>
    @else
    <div class="rolagem">
    <table class="tabela">
      <thead><tr><th>Página</th><th class="num">Vezes</th><th class="num">Usuários</th></tr></thead>
      <tbody>
      @foreach ($paginas as $p)
        <tr><td class="quebra">{{ $rotulosPaginas[$p->rota] ?? ($p->rota ?? '-') }}</td><td class="num">{{ (int) $p->total }}</td><td class="num">{{ (int) $p->usuarios }}</td></tr>
      @endforeach
      </tbody>
    </table>
    </div>
    @endif
  </section>
</div>

<section class="cartao" id="usuarios">
  <h2>Último uso de cada usuário ativo</h2>
  <p class="suave">Mais tempo sem uso primeiro. Para bloquear, abra o cadastro e marque <strong>Inativo</strong>.</p>
  <div class="filtros">
    @foreach ([null => 'Todos ('.$usuarios->count().')', 'nunca' => 'Nunca entraram ('.$nuncaEntraram.')', 'sem_uso' => "Sem uso há {$semUsoDias}+ dias ({$semUso})", 'hoje' => 'Usaram hoje'] as $valor => $rotulo)
      <a @class(['botao pequeno', 'secundario' => $mostrar !== ($valor ?: null)]) href="{{ route('cadastros.painel-acessos', array_filter(['dias' => $dias, 'sem_uso' => $semUsoDias, 'mostrar' => $valor ?: null])) }}#usuarios">{{ $rotulo }}</a>
    @endforeach
  </div>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Usuário</th><th>Situação</th><th>Último login</th><th>Última página</th><th>Último uso do app</th></tr></thead>
    <tbody>
    @forelse ($lista as $l)
      <tr>
        <td>@include('partials.usuario-seguranca', ['usuario' => $l['usuario']]) <small class="suave">{{ $l['usuario']->email }}</small></td>
        <td><span class="etiqueta {{ $situacoes[$l['situacao']][1] }}">{{ $situacoes[$l['situacao']][0] }}@if ($l['situacao'] === 'sem_uso') há {{ $l['dias_sem_uso'] }} dias @endif</span></td>
        <td>@dataHora($l['ultimo_login'])</td>
        <td>@dataHora($l['ultima_pagina'])</td>
        <td>@dataHora($l['ultimo_app'])</td>
      </tr>
    @empty
      <tr><td colspan="5">Nenhum usuário nesta lista.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>
@endsection
