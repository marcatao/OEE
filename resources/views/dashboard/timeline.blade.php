@extends('layouts.portal')

@section('titulo', 'Timeline')

@use('App\Support\Formato')

@section('conteudo')
@php
  $u = auth()->user();
  $podeApontar = $u->pode('apontamentos');
  $fuso = config('oee.fuso_exibicao');
  $parametros = fn (array $extra = []) => array_filter(['data' => $data->format('Y-m-d'), 'turno' => $turno?->id] + $extra, fn ($v) => $v !== null);
@endphp
<section class="cartao">
  <p class="trilha">Dashboards</p>
  <div class="tl-topo">
    <div>
      <h1>Timeline</h1>
      <p class="suave" style="margin:0">{{ $inicio->format('d/m/Y H:i') }} até {{ $fim->format('d/m/Y H:i') }}{{ $turno ? ' · '.$turno->nome : '' }}</p>
    </div>
    <div class="tl-navegacao">
      <a class="topo-icone" href="{{ route('timeline', $parametros(['data' => $data->subDay()->format('Y-m-d')])) }}" title="Dia anterior" aria-label="Dia anterior">@include('layouts.partials.icone', ['nome' => 'anterior'])</a>
      <form method="get" action="{{ route('timeline') }}">
        @if ($turno)<input type="hidden" name="turno" value="{{ $turno->id }}">@endif
        <input type="date" name="data" value="{{ $data->format('Y-m-d') }}" onchange="this.form.submit()" aria-label="Data">
      </form>
      <a class="topo-icone" href="{{ route('timeline', $parametros(['data' => $data->addDay()->format('Y-m-d')])) }}" title="Próximo dia" aria-label="Próximo dia">@include('layouts.partials.icone', ['nome' => 'seta'])</a>
      @unless ($data->equalTo($hoje))
        <a class="botao secundario pequeno" href="{{ route('timeline', array_filter(['turno' => $turno?->id])) }}">Hoje</a>
      @endunless
      @if ($turnos->isNotEmpty())
        <nav class="tl-turnos" aria-label="Turno">
          @foreach ($turnos as $t)
            <a href="{{ route('timeline', ['data' => $data->format('Y-m-d'), 'turno' => $t->id]) }}" @class(['ativo' => $turno?->id === $t->id]) title="{{ $t->nome }}">{{ $loop->iteration }}</a>
          @endforeach
          <a href="{{ route('timeline', ['data' => $data->format('Y-m-d')]) }}" @class(['ativo' => ! $turno]) title="Dia todo">Dia</a>
        </nav>
      @endif
      @if ($podeApontar)
        <a class="botao" href="{{ route('apontamentos.create', ['voltar' => 'timeline']) }}">@include('layouts.partials.icone', ['nome' => 'mais']) Novo apontamento</a>
      @endif
    </div>
  </div>

  <div class="tl-resumo">
    <span class="{{ Formato::nivel($disponibilidade) }}">Disponibilidade: <strong class="texto-nivel">{{ Formato::pct($disponibilidade) }}</strong></span>
    @forelse ($totais as $t)
      <span class="tl-chip" title="{{ $t['legenda']->descricao }}">
        <i class="tl-cor" style="background: {{ $t['legenda']->cor }}"></i>{{ $t['legenda']->codigo }}
        <strong>{{ Formato::pct($t['pct']) }}</strong><small class="suave">{{ Formato::minutos($t['segundos'] / 60) }}</small>
      </span>
    @empty
      <span class="suave">Sem apontamentos neste período.</span>
    @endforelse
  </div>

  @if ($maquinas->isEmpty())
    <div class="vazio">
      @include('layouts.partials.icone', ['nome' => 'maquina'])
      <p>Nenhuma máquina ativa. Cadastre as máquinas em Administração › Máquinas.</p>
    </div>
  @else
    <div class="tl-grade">
      <div class="tl-tabela">
        @foreach ($maquinas as $maquina)
          <div class="tl-linha">
            @if ($u->pode('dashboard_maquina'))
              <a class="tl-maquina" href="{{ route('dashboard.maquina', $maquina) }}" title="{{ $maquina->nome }}">{{ $maquina->nome }}<small>{{ $maquina->codigo }}</small></a>
            @else
              <span class="tl-maquina" title="{{ $maquina->nome }}">{{ $maquina->nome }}<small>{{ $maquina->codigo }}</small></span>
            @endif
            <div class="tl-faixa">
              @foreach ($linhas[$maquina->id] ?? [] as $b)
                @php
                  $legenda = $b['legenda'];
                  $dica = $legenda->codigo.' · '.$legenda->descricao.' · '
                    .\Carbon\Carbon::createFromTimestamp($b['inicio'], $fuso)->format('H:i').'–'
                    .($b['aberto'] && $b['fim'] >= now()->getTimestamp() - 60 ? 'agora' : \Carbon\Carbon::createFromTimestamp($b['fim'], $fuso)->format('H:i'))
                    .' ('.Formato::minutos(($b['fim'] - $b['inicio']) / 60).')'
                    .($b['apontamento']->referencia ? ' · '.$b['apontamento']->referencia : '');
                  $estilo = 'left: '.$b['esquerda'].'%; width: '.$b['largura'].'%; background-color: '.$legenda->cor;
                @endphp
                @if ($podeApontar)
                  <a class="tl-bloco {{ $legenda->classeTexto() }} {{ $b['aberto'] ? 'aberto' : '' }}" style="{{ $estilo }}" title="{{ $dica }}"
                     href="{{ route('apontamentos.edit', ['apontamento' => $b['apontamento'], 'voltar' => 'timeline']) }}"><span>{{ $b['apontamento']->referencia ?: $legenda->codigo }}</span></a>
                @else
                  <div class="tl-bloco {{ $legenda->classeTexto() }} {{ $b['aberto'] ? 'aberto' : '' }}" style="{{ $estilo }}" title="{{ $dica }}"><span>{{ $b['apontamento']->referencia ?: $legenda->codigo }}</span></div>
                @endif
              @endforeach
              @if ($agora !== null)<i class="tl-agora" style="left: {{ $agora }}%" title="Agora"></i>@endif
            </div>
          </div>
        @endforeach
        <div class="tl-linha">
          <span></span>
          <div class="tl-eixo">
            @foreach ($marcas as $marca)
              <span @class(['tl-marca', 'virada' => $marca['virada']]) style="left: {{ $marca['esquerda'] }}%">{{ $marca['rotulo'] }}</span>
            @endforeach
          </div>
        </div>
      </div>
    </div>
  @endif

  @if ($legendas->isNotEmpty())
    <div class="tl-resumo" style="background:none">
      <span class="suave">Legendas:</span>
      @foreach ($legendas as $l)
        <span class="tl-chip"><i class="tl-cor" style="background: {{ $l->cor }}"></i>{{ $l->codigo }} <small class="suave">{{ $l->descricao }}</small></span>
      @endforeach
    </div>
  @endif
</section>
@endsection
