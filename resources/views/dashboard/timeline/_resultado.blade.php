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
  @if ($pendentesOp > 0)
    <div class="aviso alerta tl-aviso-op">
      <p>{{ $pendentesOp }} {{ $pendentesOp === 1 ? 'apontamento exige' : 'apontamentos exigem' }} ordem de produção e
        {{ $pendentesOp === 1 ? 'está' : 'estão' }} sem. @if ($podeApontar) Clique em <strong>Informar OP</strong> abaixo (ou no bloco piscando na
        linha da máquina) para escolher a OP. @endif</p>
      <ul class="tl-pendentes">
        @foreach ($pendentes as $p)
          @php($pDica = $p['maquina']->nome.' · '.$p['legenda']->codigo.' · '.$p['legenda']->descricao.' · '
              .\Carbon\Carbon::createFromTimestamp($p['inicio'], $fuso)->format('d/m H:i:s').'–'
              .($p['aberto'] ? 'agora' : \Carbon\Carbon::createFromTimestamp($p['fim'], $fuso)->format('H:i:s'))
              .' ('.Formato::minutos(($p['fim'] - $p['inicio']) / 60).')')
          <li>
            <span>{{ $pDica }}</span>
            @if ($podeApontar)
              <button type="button" class="botao pequeno" data-informar-op="{{ route('apontamentos.informar-op', $p['apontamento']) }}"
                      data-maquina="{{ $p['maquina']->nome }}" data-descricao="{{ \Illuminate\Support\Str::after($pDica, $p['maquina']->nome.' · ') }}">Informar OP</button>
            @endif
          </li>
        @endforeach
      </ul>
    </div>
  @endif

  @if (count($semMotivo) > 0)
    {{-- Paradas sem motivo (98, do IoT): no formato do aviso de OP, 2 por vez; ao informar, aparecem as próximas. --}}
    @php($totalSemMotivo = count($semMotivo))
    <div class="aviso alerta tl-aviso-op tl-aviso-motivo">
      <p>{{ $totalSemMotivo }} {{ $totalSemMotivo === 1 ? 'parada está' : 'paradas estão' }} <strong>sem motivo</strong>.
        @if ($podeApontar) Clique em <strong>Informar motivo</strong> abaixo (ou no bloco piscando na linha da máquina) para escolher a legenda
        da parada. @endif</p>
      <ul class="tl-pendentes">
        @foreach (array_slice($semMotivo, 0, 2) as $p)
          @php($pDica = $p['maquina']->nome.' · '.$p['legenda']->codigo.' · '.$p['legenda']->descricao.' · '
              .\Carbon\Carbon::createFromTimestamp($p['inicio'], $fuso)->format('d/m H:i:s').'–'
              .($p['aberto'] ? 'agora' : \Carbon\Carbon::createFromTimestamp($p['fim'], $fuso)->format('H:i:s'))
              .' ('.Formato::minutos(($p['fim'] - $p['inicio']) / 60).')')
          <li>
            <span>{{ $pDica }}</span>
            @if ($podeApontar)
              <button type="button" class="botao pequeno" data-informar-motivo="{{ route('apontamentos.informar-motivo', $p['apontamento']) }}"
                      data-maquina="{{ $p['maquina']->nome }}" data-descricao="{{ \Illuminate\Support\Str::after($pDica, $p['maquina']->nome.' · ') }}">Informar motivo</button>
            @endif
          </li>
        @endforeach
      </ul>
      @if ($totalSemMotivo > 2)
        <p class="suave tl-mais">E mais {{ $totalSemMotivo - 2 }} {{ $totalSemMotivo - 2 === 1 ? 'parada' : 'paradas' }} sem motivo: aparecem aqui conforme as de cima forem informadas.</p>
      @endif
    </div>
  @endif

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
      <p>Nenhuma máquina ativa e com IoT cadastrado com estes filtros.</p>
    </div>
  @else
    <div class="tl-grade">
      <div class="tl-tabela">
        @foreach ($maquinas as $maquina)
          @php($sit = $situacoes[$maquina->id] ?? null)
          <div class="tl-linha">
            <div class="tl-maquina-info" title="{{ $maquina->nome }} · {{ $maquina->localizacao() }}">
              @if ($u->pode('dashboard_maquina'))
                <a class="tl-maquina" href="{{ route('dashboard.maquina', $maquina) }}">{{ $maquina->nome }}</a>
              @else
                <span class="tl-maquina">{{ $maquina->nome }}</span>
              @endif
              @if ($sit && $sit['op'])
                <span class="tl-op" title="OP {{ $sit['op']->numero }}{{ $sit['produto'] ? ' · '.$sit['produto']->codigo().' '.$sit['produto']->descricao() : '' }}">
                  <strong>OP {{ $sit['op']->numero }}@if ($podeApontar)<button type="button" class="tl-trocar-op"
                      data-trocar-op="{{ route('apontamentos.trocar-op', $maquina->id) }}" data-maquina="{{ $maquina->nome }}"
                      data-op="{{ $sit['op']->numero }}">Trocar OP</button>@endif</strong>
                  <small>{{ $sit['produto'] ? $sit['produto']->codigo().' · '.$sit['produto']->descricao() : rtrim($sit['op']->c2_produto) }}</small>
                  @foreach ($sit['moldes'] as $molde)
                    <small class="tl-molde" title="Molde {{ $molde['codigo'] }}{{ $molde['ferramenta'] ? ' · '.$molde['ferramenta']->h4_descri.' · '.$molde['ferramenta']->h4_qtdcavativas.' cavidades ativas de '.$molde['ferramenta']->h4_qtdcav : '' }}">
                      Molde <b>{{ $molde['codigo'] }}</b>@if ($molde['ferramenta']) · <b>{{ $molde['ferramenta']->h4_qtdcavativas }}</b>/{{ $molde['ferramenta']->h4_qtdcav }} cav.@endif
                    </small>
                  @endforeach
                </span>
              @elseif ($podeApontar)
                <span class="tl-op"><button type="button" class="tl-trocar-op" data-trocar-op="{{ route('apontamentos.trocar-op', $maquina->id) }}"
                  data-maquina="{{ $maquina->nome }}" data-op="">Informar OP</button></span>
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
                @if ($b['pendenteOp'] && $podeApontar)
                  <button type="button" class="tl-bloco tl-pendente-op {{ $b['aberto'] ? 'aberto' : '' }}" style="{{ $estilo }}" title="INFORMAR OP · {{ $dica }}"
                     data-informar-op="{{ route('apontamentos.informar-op', $b['apontamento']) }}" data-maquina="{{ $maquina->nome }}"
                     data-descricao="{{ $dica }}"><span><b>INFORMAR OP</b><em class="tl-desc"> · {{ $legenda->codigo }}</em></span></button>
                @elseif ($b['semMotivo'] && $podeApontar)
                  <button type="button" class="tl-bloco tl-pendente-op {{ $b['aberto'] ? 'aberto' : '' }}" style="{{ $estilo }}" title="INFORMAR MOTIVO · {{ $dica }}"
                     data-informar-motivo="{{ route('apontamentos.informar-motivo', $b['apontamento']) }}" data-maquina="{{ $maquina->nome }}"
                     data-descricao="{{ $dica }}"><span><b>INFORMAR MOTIVO</b><em class="tl-desc"> · {{ $legenda->codigo }}</em></span></button>
                @elseif ($b['pendenteOp'])
                  <div class="tl-bloco tl-pendente-op {{ $b['aberto'] ? 'aberto' : '' }}" style="{{ $estilo }}" title="Sem OP · {{ $dica }}"><span><b>SEM OP</b><em class="tl-desc"> · {{ $legenda->codigo }}</em></span></div>
                @elseif ($podeApontar)
                  <a class="tl-bloco {{ $legenda->classeTexto() }} {{ $b['aberto'] ? 'aberto' : '' }}" style="{{ $estilo }}" title="{{ $dica }}"
                     href="{{ route('apontamentos.edit', ['apontamento' => $b['apontamento'], 'voltar' => 'timeline']) }}"><span><b>{{ $b['apontamento']->rotuloBarra() }}</b><em class="tl-desc"> · {{ $legenda->descricao }}</em></span></a>
                @else
                  <div class="tl-bloco {{ $legenda->classeTexto() }} {{ $b['aberto'] ? 'aberto' : '' }}" style="{{ $estilo }}" title="{{ $dica }}"><span><b>{{ $b['apontamento']->rotuloBarra() }}</b><em class="tl-desc"> · {{ $legenda->descricao }}</em></span></div>
                @endif
              @endforeach
              @if ($agora !== null)<i class="tl-agora" style="left: {{ $agora }}%" title="Agora"></i>@endif
            </div>
            @if ($sit && $sit['estado'] !== 'sem')
              <div class="tl-estado estado-{{ $sit['estado'] }}" title="Situação no cadastro da máquina{{ $sit['legenda'] ? ' (pela legenda '.$sit['legenda']->codigo.' · '.$sit['legenda']->descricao.')' : ' (informada no cadastro)' }}">
                <span class="tl-estado-titulo" title="{{ $sit['rotulo'] }}"><i class="tl-pulso" aria-hidden="true"></i><span class="tl-estado-texto">{{ $sit['rotulo'] }}</span></span>
                <small>{{ $sit['legenda'] ? $sit['legenda']->codigo.' · '.$sit['legenda']->descricao : 'informada no cadastro' }}</small>
                <small title="Início da situação atual · ciclo médio do apontamento atual (segundos por peça)">{{ ($sit['desde'] ? 'às '.$sit['desde']->copy()->setTimezone($fuso)->format($sit['desde']->copy()->setTimezone($fuso)->isSameDay(now($fuso)) ? 'H:i' : 'd/m H:i').' · ' : '').'cl: '.($sit['cicloMedio'] !== null ? $sit['cicloMedio'].' s' : '—') }}</small>
              </div>
            @else
              <div class="tl-estado estado-sem"><span class="tl-estado-titulo" title="Sem informação"><i class="tl-pulso" aria-hidden="true"></i><span class="tl-estado-texto">Sem informação</span></span></div>
            @endif
            @if ($sit && ! empty($sit['chamado']))
              {{-- Máquina parada com SS/OS aberta pela legenda: número e situação no lugar da meta. --}}
              @php($ch = $sit['chamado'])
              <a class="tl-meta tl-chamado chamado-{{ $ch['status'] }}" title="{{ $ch['numero'] }} · {{ $ch['rotulo'] }}"
                 href="{{ $ch['tipo'] === 'os' ? route('os.ordens.show', $ch['id']) : route('os.solicitacoes.show', $ch['id']) }}">
                <span class="tl-meta-titulo">{{ $ch['numero'] }}</span>
                <small>{{ $ch['rotulo'] }}</small>
              </a>
            @else
              {{-- Meta de produção da máquina: em breve --}}
              <div class="tl-meta" title="Meta de produção: em breve">
                <span class="tl-meta-titulo">Meta</span>
                <small>em breve</small>
              </div>
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
          <span></span>
        </div>
      </div>
    </div>
  @endif
</div>
