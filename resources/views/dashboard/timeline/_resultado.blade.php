{{-- Área de resultado da Timeline. Trocada sozinha (sem recarregar a página) quando um filtro muda. --}}
@use('App\Support\Formato')
@php($u = auth()->user())
@php($podeApontar = $u->pode('apontamentos'))
@php($fuso = config('oee.fuso_exibicao'))
<div class="tl-resultado-conteudo" data-ao-vivo="{{ $aoVivo ? 1 : 0 }}">
  <p class="tl-status suave">
    {{ $inicio->format('d/m/Y H:i') }} até {{ $filtro->ate->format('d/m/Y H:i') }}
    · {{ $maquinas->count() }} {{ $maquinas->count() == 1 ? 'máquina' : 'máquinas' }}
    · @if ($aoVivo)<span class="etiqueta situacao-online">Ao vivo</span>@endif
    atualizado às {{ $atualizadoEm->format('H:i:s') }}
  </p>
  @if ($filtro->aviso)<p class="aviso alerta">{{ $filtro->aviso }}</p>@endif

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
      <p>Nenhuma máquina ativa com estes filtros.</p>
    </div>
  @else
    <div class="tl-grade">
      <div class="tl-tabela">
        @foreach ($maquinas as $maquina)
          @php($sit = $situacoes[$maquina->id] ?? null)
          <div class="tl-linha">
            <div class="tl-maquina-info" title="{{ $maquina->nome }} · {{ $maquina->localizacao() }}">
              @if ($u->pode('dashboard_maquina'))
                <a class="tl-maquina" href="{{ route('dashboard.maquina', $maquina) }}">{{ $maquina->nome }}<small>{{ $maquina->codigo }}</small></a>
              @else
                <span class="tl-maquina">{{ $maquina->nome }}<small>{{ $maquina->codigo }}</small></span>
              @endif
              @if ($sit && $sit['op'])
                <span class="tl-op" title="OP {{ $sit['op']->numero }}{{ $sit['produto'] ? ' · '.$sit['produto']->codigo().' '.$sit['produto']->descricao() : '' }}">
                  <strong>OP {{ $sit['op']->numero }}</strong>
                  <small>{{ $sit['produto'] ? $sit['produto']->codigo().' · '.$sit['produto']->descricao() : rtrim($sit['op']->c2_produto) }}</small>
                </span>
              @endif
            </div>
            <div class="tl-faixa">
              @foreach ($linhas[$maquina->id] ?? [] as $b)
                @php($legenda = $b['legenda'])
                @php($dica = $legenda->codigo.' · '.$legenda->descricao.' · '
                    .\Carbon\Carbon::createFromTimestamp($b['inicio'], $fuso)->format('d/m H:i').'–'
                    .($b['aberto'] && $b['fim'] >= now()->getTimestamp() - 60 ? 'agora' : \Carbon\Carbon::createFromTimestamp($b['fim'], $fuso)->format('H:i'))
                    .' ('.Formato::minutos(($b['fim'] - $b['inicio']) / 60).')'
                    .($b['apontamento']->ordemProducao ? ' · OP '.$b['apontamento']->ordemProducao->numero : '')
                    .($b['apontamento']->referencia ? ' · '.$b['apontamento']->referencia : ''))
                @php($estilo = 'left: '.$b['esquerda'].'%; width: '.$b['largura'].'%; background-color: '.$legenda->cor)
                @if ($podeApontar)
                  <a class="tl-bloco {{ $legenda->classeTexto() }} {{ $b['aberto'] ? 'aberto' : '' }}" style="{{ $estilo }}" title="{{ $dica }}"
                     href="{{ route('apontamentos.edit', ['apontamento' => $b['apontamento'], 'voltar' => 'timeline']) }}"><span>{{ $b['apontamento']->rotuloBarra() }}</span></a>
                @else
                  <div class="tl-bloco {{ $legenda->classeTexto() }} {{ $b['aberto'] ? 'aberto' : '' }}" style="{{ $estilo }}" title="{{ $dica }}"><span>{{ $b['apontamento']->rotuloBarra() }}</span></div>
                @endif
              @endforeach
              @if ($agora !== null)<i class="tl-agora" style="left: {{ $agora }}%" title="Agora"></i>@endif
            </div>
            @if ($sit && $sit['legenda'])
              <div class="tl-estado estado-{{ $sit['estado'] }}" title="Última legenda apontada: {{ $sit['legenda']->codigo }} · {{ $sit['legenda']->descricao }}">
                <span class="tl-estado-titulo"><i class="tl-pulso" aria-hidden="true"></i>{{ $sit['rotulo'] }}</span>
                <small>{{ $sit['legenda']->codigo }} · {{ $sit['legenda']->descricao }}</small>
                <small>{{ $sit['aberto'] ? 'desde' : 'último apontamento' }} {{ $sit['desde']->copy()->setTimezone($fuso)->format($sit['desde']->copy()->setTimezone($fuso)->isSameDay(now($fuso)) ? 'H:i' : 'd/m H:i') }}</small>
              </div>
            @else
              <div class="tl-estado estado-sem"><span class="tl-estado-titulo"><i class="tl-pulso" aria-hidden="true"></i>Sem apontamento</span></div>
            @endif
          </div>
        @endforeach
        <div class="tl-linha tl-linha-eixo">
          <span></span>
          <div class="tl-eixo">
            @foreach ($marcas as $marca)
              <span @class(['tl-marca', 'virada' => $marca['virada']]) style="left: {{ $marca['esquerda'] }}%">{{ $marca['rotulo'] }}</span>
            @endforeach
          </div>
          <span></span>
        </div>
      </div>
    </div>
  @endif
</div>
