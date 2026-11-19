@extends('layouts.portal')

@section('titulo', $os->numeroFormatado())

@use('App\Models\Manutencao\MaterialOs')
@use('App\Models\ApontamentoEstado')
@use('App\Services\Manutencao\HorasManutencao')

@section('conteudo')
@php($aberta = in_array($os->status, ['aberta', 'em_execucao', 'aguardando_material'], true))
@php($atribuidos = $os->mantenedoresAtribuidos())
<section class="cartao os-detalhe">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.ordens.index') }}">OS</a></p>

  {{-- 1. Do que se trata: número, título e situação --}}
  <div class="os-topo">
    <div class="os-topo-texto">
      <p class="os-numero">{{ $os->numeroFormatado() }}@if ($os->solicitacao) · aberta pela <a href="{{ route('os.solicitacoes.show', $os->solicitacao) }}">{{ $os->solicitacao->numeroFormatado() }}</a>@elseif ($os->plano) · preventiva {{ $os->plano->codigo }}@endif</p>
      <h1>{{ $os->titulo ?: \Illuminate\Support\Str::limit($os->descricao, 90) }}</h1>
      <div class="os-chips">
        <span class="etiqueta os-{{ $os->status }}">{{ $os->rotuloStatus() }}</span>
        <span class="etiqueta">{{ $os->rotuloTipo() }}</span>
        <span class="etiqueta prioridade-{{ $os->prioridade }}">Prioridade {{ mb_strtolower($os->rotuloPrioridade()) }}</span>
        @if ($os->maquina_parada)<span class="etiqueta os-rejeitada">Máquina parada</span>@endif
        @if ($os->risco_integridade)<span class="etiqueta os-risco">Risco à integridade</span>@endif
        @if ($os->automatica)<span class="etiqueta os-automatica" title="Aberta pelo apontamento da máquina{{ $os->origem_iot ? ' — '.$os->origem_iot : '' }}">Automática{{ $os->origem_iot ? ' · '.$os->origem_iot : '' }}</span>@endif
        @if ($os->avaliacao && $os->status === 'encerrada')<span class="etiqueta avaliacao-{{ $os->avaliacao }}">Avaliação: {{ \App\Models\Manutencao\OrdemServico::AVALIACOES[$os->avaliacao] }}</span>@endif
      </div>
    </div>
    <div class="os-cabecalho">
      <a class="botao secundario" href="{{ route('os.ordens.imprimir', $os) }}" target="_blank">@include('layouts.partials.icone', ['nome' => 'imprimir']) Imprimir</a>
      @if ($podeExecutar && $editavel)
        <form method="post" action="{{ route('os.ordens.maquina-parada', $os) }}" style="margin:0">@csrf
          <input type="hidden" name="maquina_parada" value="{{ $os->maquina_parada ? 0 : 1 }}">
          <button class="botao secundario" type="submit">{{ $os->maquina_parada ? 'Máquina voltou a funcionar' : 'Marcar máquina parada' }}</button>
        </form>
      @endif
    </div>
  </div>
  @include('manutencao._erros')

  {{-- 2. Avaliação pendente: bem visível para o solicitante --}}
  @if ($podeAvaliar)
    <form method="post" action="{{ route('os.ordens.avaliar', $os) }}" class="os-avaliar" id="avaliar">
      @csrf
      <div class="os-avaliar-titulo">@include('layouts.partials.icone', ['nome' => 'alerta'])
        <div><strong>Serviço concluído: avalie o atendimento</strong>
          <p>A manutenção terminou esta OS{{ $os->concluida_em ? ' em '.\App\Providers\AppServiceProvider::dataHora($os->concluida_em) : '' }}. Confira o resultado e avalie — sua avaliação encerra a OS (vale como sua assinatura). <strong>Ruim</strong> devolve a OS para a manutenção.</p></div>
      </div>
      <div class="os-avaliar-linha">
        <div class="os-avaliar-opcoes" role="radiogroup" aria-label="Avaliação">
          @foreach (['excelente' => 'Excelente', 'boa' => 'Boa', 'ruim' => 'Ruim'] as $v => $r)
            <label class="os-nota avaliacao-{{ $v }}"><input type="radio" name="avaliacao" value="{{ $v }}" required @checked(old('avaliacao') === $v)><span>{{ $r }}</span></label>
          @endforeach
        </div>
        <label class="os-avaliar-obs"><span>Comentário <small class="suave" id="avaliar-dica">(opcional)</small></span>
          <textarea name="observacao" rows="1" maxlength="500" id="avaliar-obs" placeholder="Algo a dizer sobre o serviço?">{{ old('observacao') }}</textarea></label>
        <button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'check']) Enviar avaliação</button>
      </div>
    </form>
    <script>
      (function () {
        var obs = document.getElementById('avaliar-obs'), dica = document.getElementById('avaliar-dica');
        document.querySelectorAll('#avaliar input[name=avaliacao]').forEach(function (r) {
          r.addEventListener('change', function () {
            var ruim = r.value === 'ruim' && r.checked;
            obs.required = ruim;
            dica.textContent = ruim ? '(obrigatório: o que ficou ruim? A OS volta para a manutenção)' : '(opcional)';
            obs.placeholder = ruim ? 'Explique o motivo' : 'Algo a dizer sobre o serviço?';
          });
        });
      })();
    </script>
  @elseif ($os->status === 'concluida')
    <p class="aviso">Serviço concluído: aguardando a avaliação de {{ $os->solicitante?->nome ?? 'quem solicitou' }}.</p>
  @endif
  @if ($os->status === 'cancelada')<p class="aviso erro">Cancelada: {{ $os->cancelamento_motivo }}</p>@endif
  @if (! $editavel && ! $podeAvaliar)
    @if ($os->status === 'encerrada')
      <p class="aviso">OS encerrada (avaliada pelo solicitante): fica só para consulta e não pode ser reaberta. Se o problema voltou, abra uma nova solicitação.</p>
    @else
      <p class="aviso">OS fechada: dados, conversa, horas e materiais ficam só para consulta. Para alterar, reabra a OS.</p>
    @endif
  @endif
  @if ($os->avaliacao === 'ruim' && $os->status !== 'encerrada' && $os->aceite_observacao)
    <p class="aviso erro">Avaliada como <strong>Ruim</strong> por {{ $os->aceitePor?->nome }} em @dataHora($os->aceite_em): {{ $os->aceite_observacao }}</p>
  @endif

  {{-- 3. O problema e o que foi feito --}}
  <div class="os-corpo">
    <div class="os-principal">
      <div class="os-descricao">
        <h2>Problema / serviço solicitado</h2>
        <p class="os-texto">{{ $os->descricao }}</p>
        @if ($os->solucao)<h2>Serviço realizado</h2><p class="os-texto">{{ $os->solucao }}</p>@endif
      </div>

      @if ($avaliada)
        <div class="os-avaliacao-feita" id="avaliacao">
          <h2>Avaliação do solicitante</h2>
          <div class="os-avaliacao-dados">
            <div>
              <p class="os-avaliacao-nota avaliacao-{{ $os->avaliacao }}">{{ \App\Models\Manutencao\OrdemServico::AVALIACOES[$os->avaliacao] ?? $os->avaliacao }}</p>
              <p class="suave">{{ $os->aceitePor?->nome ?? $assinatura?->nome_assinante ?? '—' }} · @dataHora($os->aceite_em)@if ($assinatura) · pelo aplicativo @endif</p>
              @if ($os->aceite_observacao)
                <h3>Comentário</h3>
                <p class="os-texto">{{ $os->aceite_observacao }}</p>
              @else
                <p class="suave">Sem comentário.</p>
              @endif
            </div>
            @if ($assinatura)
              <figure class="os-assinatura">
                <img src="data:image/png;base64,{{ $assinatura->imagem_png }}" alt="Assinatura de {{ $assinatura->nome_assinante }}">
                <figcaption>{{ $assinatura->nome_assinante }}<small class="suave">assinado no aplicativo · @dataHora($assinatura->criado_em)</small></figcaption>
              </figure>
            @endif
          </div>
        </div>
      @endif

    {{-- 5. Linha do tempo --}}
    <ol class="os-linha-tempo">
      @foreach ($marcos as $m)
        <li @class(['feito' => $m['quando'] !== null])>
          <span class="os-marco-ponto"></span>
          <strong>{{ $m['rotulo'] }}</strong>
          @if ($m['quando'])
            <span>{{ $m['quando'] instanceof \Carbon\CarbonInterface && $m['rotulo'] === 'Programada para' ? $m['quando']->format('d/m/Y') : \App\Providers\AppServiceProvider::dataHora($m['quando']) }}</span>
            @if ($m['quem'])<small class="suave">{{ $m['quem'] }}</small>@endif
            @if ($m['detalhe'])<small>{{ $m['detalhe'] }}</small>@endif
            @if (! empty($m['duracao']))<small class="os-marco-duracao">+{{ $m['duracao'] }}</small>@endif
          @else
            <small class="suave">pendente</small>
          @endif
        </li>
      @endforeach
    </ol>

    {{-- 6. Ações --}}
    @php($acoesVisiveis = collect($acoes)->filter(fn ($r, $a) => in_array($a, ['cancelar', 'reabrir'], true) ? $podeCancelar : $podeExecutar))
    @if ($acoesVisiveis->isNotEmpty() || ($podeAprovar && $aberta))
      <div class="os-acoes-bloco">
        @if ($acoesVisiveis->isNotEmpty())
          <form method="post" action="{{ route('os.ordens.acao', $os) }}" class="os-acoes">
            @csrf
            <label>Observação <input name="observacao" maxlength="2000" placeholder="opcional (obrigatória para cancelar)"></label>
            @if (isset($acoesVisiveis['concluir']))
              {{-- Para concluir é obrigatório informar a área executante que atendeu (vem a da OS; pode corrigir). --}}
              <label>Área executante que atendeu
                <select name="area_executante_id" aria-label="Área executante que atendeu">
                  @foreach ($areasConclusao as $ar)<option value="{{ $ar->id }}" @selected((int) old('area_executante_id', $os->area_executante_id) === $ar->id)>{{ $ar->nome }}</option>@endforeach
                </select></label>
            @endif
            @foreach ($acoesVisiveis as $acao => $rotulo)
              <button class="botao {{ in_array($acao, ['cancelar', 'reabrir', 'aguardar_material'], true) ? 'secundario' : '' }}" type="submit" name="acao" value="{{ $acao }}"
                @if ($acao === 'cancelar') onclick="return confirm('Cancelar esta OS?')" @endif>{{ $rotulo }}</button>
            @endforeach
          </form>
        @endif
        @if ($podeAprovar && $aberta)
          <form method="post" action="{{ route('os.ordens.distribuir', $os) }}" class="ss-analise-bloco">
            @csrf
            @include('manutencao._escolher-mantenedores', ['area' => $os->areaExecutante?->nome, 'mantenedores' => $mantenedores, 'marcados' => $atribuidos->pluck('id')->all()])
            <div class="acoes"><button class="botao secundario" type="submit">Salvar mantenedores</button></div>
          </form>
        @endif
      </div>
    @endif
    </div>

    {{-- 4. Onde e quem --}}
    <div class="os-ficha">
      <div class="os-ficha-bloco">
        <h3>Equipamento</h3>
        <dl>
          <dt>Máquina</dt><dd>@include('manutencao._maquina', ['maquina' => $os->maquina])</dd>
          <dt>Centro de custo</dt><dd>{{ $os->centro_custo ?: '—' }}@if ($ccDescricao) <small class="suave">{{ $ccDescricao }}</small>@endif</dd>
        </dl>
      </div>
      <div class="os-ficha-bloco">
        <h3>Solicitação</h3>
        <dl>
          <dt>Solicitante</dt>
          <dd>@if ($os->solicitante)<span class="os-pessoa">@include('partials.avatar', ['usuario' => $os->solicitante]) <span>{{ $os->solicitante->nome }}@if ($os->centro_custo_solicitante)<small class="suave">{{ $os->centro_custo_solicitante }}</small>@endif</span></span>@else{{ $os->plano ? 'PAMP '.$os->plano->codigo : '—' }}@endif</dd>
          <dt>Aprovada por</dt><dd>{{ $os->aprovadoPor?->nome ?? '—' }}</dd>
        </dl>
      </div>
      <div class="os-ficha-bloco">
        <h3>Execução</h3>
        <dl>
          <dt>Área executante</dt><dd>{{ $os->areaExecutante?->nome }} <span class="etiqueta unidade-{{ $os->unidade }}">{{ config('oee.unidades')[$os->unidade] ?? $os->unidade }}</span></dd>
          <dt>Mantenedores</dt>
          <dd>@forelse ($atribuidos as $mt)<span class="os-pessoa">@if ($mt->usuario)@include('partials.avatar', ['usuario' => $mt->usuario])@endif <span>{{ $mt->nomeExibicao() }}</span></span>@empty<span class="suave">definir depois</span>@endforelse</dd>
          <dt>Horas apontadas</dt><dd><strong>{{ \App\Services\Manutencao\HorasManutencao::hhmm($os->total_minutos) }}</strong></dd>
        </dl>
      </div>
      <div class="os-ficha-bloco os-custo" id="custo-os">
        <h3>Quanto esta OS está custando</h3>
        <p class="os-custo-total">{{ \App\Services\Manutencao\CustoOrdemServico::reais($custo['total']) }}</p>
        <dl>
          <dt>Compras (PC e SC)</dt>
          <dd>{{ \App\Services\Manutencao\CustoOrdemServico::reais($custo['pedidos']) }}
            <small class="suave">{{ $custo['pedidos_qtd'] }} pedido(s)/solicitação(ões)@if ($custo['pedidos_sem_valor']) · {{ $custo['pedidos_sem_valor'] }} ainda sem valor @endif</small></dd>
          <dt>Materiais (almoxarifado)</dt>
          <dd>{{ \App\Services\Manutencao\CustoOrdemServico::reais($custo['materiais']) }}
            @if ($custo['materiais_sem_custo'])<small class="suave">{{ $custo['materiais_sem_custo'] }} sem custo informado</small>@endif</dd>
          <dt>Mão de obra</dt><dd>{{ \App\Services\Manutencao\HorasManutencao::hhmm($custo['minutos']) }} <small class="suave">horas apontadas (sem custo/hora)</small></dd>
        </dl>
      </div>
    </div>
  </div>
</section>


<section class="cartao">
  <div class="titulo-com-acao">
    <h2>Apontamento de horas</h2>
    @if ($aberta && $podeExecutar)
      <form method="post" action="{{ route('os.apontamentos.iniciar', $os) }}" class="os-acoes" style="margin:0">
        @csrf
        @if ($podeAprovar)
          <select name="mantenedor_id" aria-label="Mantenedor">
            @if ($meuMantenedor)<option value="">Eu ({{ $meuMantenedor->nome }})</option>@endif
            @foreach ($mantenedores as $m)@if ($m->id !== $meuMantenedor?->id)<option value="{{ $m->id }}">{{ $m->nome }}</option>@endif @endforeach
          </select>
        @endif
        <button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'cronometro']) Iniciar apontamento</button>
      </form>
    @endif
  </div>
  <p class="suave">O tempo corre até o mantenedor encerrar. Hora normal × extra é separada pela jornada de cada mantenedor.</p>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Mantenedor</th><th>Início</th><th>Fim</th><th class="num">Normal</th><th class="num">Extra</th><th>Descrição do serviço</th><th>Lançado por</th><th></th></tr></thead>
    <tbody>
    @forelse ($apontamentos as $ap)
      <tr>
        <td>{{ $ap->mantenedor?->nome }}</td>
        <td>@dataHora($ap->inicio)</td>
        <td>@if ($ap->fim) @dataHora($ap->fim) @else <span class="etiqueta os-em_execucao">rodando</span> @endif</td>
        <td class="num">{{ $ap->fim ? HorasManutencao::hhmm($ap->minutos_normais) : HorasManutencao::hhmm($ap->minutosTotais()) }}</td>
        <td class="num">{{ $ap->fim ? HorasManutencao::hhmm($ap->minutos_extras) : '' }}</td>
        <td class="os-texto">
          @if ($ap->fim === null && ($podeAprovar || ($meuMantenedor && (int) $meuMantenedor->id === (int) $ap->mantenedor_id)))
            <form method="post" action="{{ route('os.apontamentos.encerrar', $ap) }}" class="os-acoes" style="margin:0">
              @csrf
              <input name="descricao" maxlength="1000" required placeholder="O que foi feito" value="{{ $ap->descricao }}">
              <label class="opcao ap-concluir"><input type="checkbox" name="concluir_os" value="1"> <span>Concluir a OS também</span></label>
              <button class="botao pequeno" type="submit">Encerrar apontamento</button>
            </form>
          @else
            {{ $ap->descricao }}
          @endif
        </td>
        <td>{{ $ap->criadoPor?->nome }}</td>
        <td>
          @if ($editavel && $ap->fim && ($podeAprovar || ($meuMantenedor && (int) $meuMantenedor->id === (int) $ap->mantenedor_id)))
            <details><summary class="botao pequeno secundario">Corrigir</summary>
              <form method="post" action="{{ route('os.apontamentos.update', $ap) }}" class="formulario-vertical">
                @csrf @method('put')
                <input type="hidden" name="mantenedor_id" value="{{ $ap->mantenedor_id }}">
                <label>Início <input type="datetime-local" name="inicio" required value="{{ ApontamentoEstado::paraHoraLocal($ap->inicio) }}"></label>
                <label>Fim <input type="datetime-local" name="fim" required value="{{ ApontamentoEstado::paraHoraLocal($ap->fim) }}"></label>
                <label>Descrição <input name="descricao" maxlength="1000" value="{{ $ap->descricao }}"></label>
                <label>Min. normais <input type="number" name="minutos_normais" min="0" placeholder="pela jornada"></label>
                <label>Min. extras <input type="number" name="minutos_extras" min="0" placeholder="pela jornada"></label>
                <button class="botao pequeno" type="submit">Salvar</button>
              </form>
              @if ($podeAprovar)
                <form method="post" action="{{ route('os.apontamentos.destroy', $ap) }}" onsubmit="return confirm('Excluir este apontamento?')">
                  @csrf @method('delete')<button class="botao pequeno secundario" type="submit">Excluir</button>
                </form>
              @endif
            </details>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="8" class="suave">Nenhum apontamento ainda.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  @if ($podeAprovar && $editavel)
    <details>
      <summary class="botao pequeno secundario">Lançar horas manualmente</summary>
      <form method="post" action="{{ route('os.apontamentos.store', $os) }}" class="filtros">
        @csrf
        <label>Mantenedor <select name="mantenedor_id" required>@foreach ($mantenedores as $m)<option value="{{ $m->id }}">{{ $m->nome }}</option>@endforeach</select></label>
        <label>Início <input type="datetime-local" name="inicio" required></label>
        <label>Fim <input type="datetime-local" name="fim" required></label>
        <label>Descrição <input name="descricao" maxlength="1000"></label>
        <button class="botao" type="submit">Lançar</button>
      </form>
    </details>
  @endif
</section>

<section class="cartao">
  <h2>Materiais</h2>
  <p class="suave">Requisição ao almoxarifado. Sem estoque, o almoxarifado manda para compra; a OS pode ficar "aguardando material".</p>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Material</th><th class="num">Qtde</th><th>Situação</th><th>Sol. compra</th><th>Observação</th><th>Requisitado por</th></tr></thead>
    <tbody>
    @forelse ($materiais as $mat)
      <tr>
        <td>@if ($mat->produto_codigo)<code>{{ $mat->produto_codigo }}</code> @endif{{ $mat->descricao }}</td>
        <td class="num">{{ number_format((float) $mat->quantidade, 2, ',', '.') }} {{ $mat->um }}</td>
        <td><span class="etiqueta os-{{ $mat->status }}">{{ $mat->rotuloStatus() }}</span></td>
        <td>{{ $mat->solicitacao_compra }}</td>
        <td>{{ $mat->observacao }}</td>
        <td>{{ $mat->criadoPor?->nome }}<br><small class="suave">@dataHora($mat->criado_em)</small></td>
      </tr>
    @empty
      <tr><td colspan="6" class="suave">Nenhum material requisitado.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  @if ($aberta && $podeExecutar)
    <form method="post" action="{{ route('os.materiais.store', $os) }}" class="filtros">
      @csrf
      <label>Código (Protheus) <input name="produto_codigo" maxlength="30" value="{{ old('produto_codigo') }}"></label>
      <label>Descrição <input name="descricao" maxlength="200" value="{{ old('descricao') }}" placeholder="se não tiver código"></label>
      <label>Quantidade <input type="number" step="0.0001" min="0" name="quantidade" required value="{{ old('quantidade', 1) }}"></label>
      <label>UM <input name="um" maxlength="4" size="4" value="{{ old('um') }}"></label>
      <label>Observação <input name="observacao" maxlength="500"></label>
      <button class="botao" type="submit">Requisitar ao almoxarifado</button>
    </form>
  @endif
</section>

{{-- OS aberta: os pedidos/SC e o formulário. OS fechada: só se houver pedido ou SC, com produto e valor do concluído. --}}
@if ($editavel || $pedidos->isNotEmpty())
<section class="cartao" id="pedidos-compra">
  <h2>Compras no Protheus</h2>
  @if ($editavel)
  <p class="suave">Pedidos e solicitações de compra gerados pelo administrador da unidade. O Protheus devolve o número, a aprovação, a
    conclusão e os valores (somados no custo da OS; na solicitação, o valor dos pedidos gerados a partir dela).</p>
  @include('manutencao.pedidos-compra._lista', ['pedidos' => $pedidos, 'podeAdministrar' => $podeAdministrarPedidos, 'muroOk' => $muroOk])
  @else
  <p class="suave">Pedidos e solicitações de compra desta OS. O valor de cada produto aparece quando o pedido é concluído no Protheus
    (pedido: o que foi entregue; solicitação: o pedido gerado a partir dela).</p>
  @include('manutencao.pedidos-compra._resumo', ['pedidos' => $pedidos, 'muroOk' => $muroOk])
  @endif
  @if ($podeGerarPedido)
    @php($itensPc = old('itens') ?: [['produto_codigo' => '', 'quantidade' => '', 'observacao' => '']])
    <form method="post" action="{{ route('os.pedidos-compra.da-ordem', $os) }}" class="pc-novo" id="os-pedido">
      @csrf
      <h3>Gerar pedido ou solicitação de compra</h3>
      @error('pedido')<p class="aviso erro" role="alert">{{ $message }}</p>@enderror
      @include('manutencao.pedidos-compra._cabecalho', ['centros' => $centrosPedido, 'ccOrigem' => (string) $os->centro_custo, 'ccDestino' => (string) $os->centro_custo])
      <div id="pc-linhas">
        @foreach (array_values($itensPc) as $n => $linhaPc)
          @include('manutencao.pedidos-compra._linha', ['i' => $n, 'linha' => $linhaPc])
        @endforeach
      </div>
      <template id="pc-modelo">@include('manutencao.pedidos-compra._linha', ['i' => '__I__', 'linha' => []])</template>
      <div class="acoes">
        <button class="botao secundario" type="button" id="pc-adicionar">@include('layouts.partials.icone', ['nome' => 'mais']) Adicionar produto</button>
        <button class="botao" type="submit">Gerar no Protheus</button>
      </div>
    </form>
    <script>
      (function () {
        var lista = document.getElementById('pc-linhas'), modelo = document.getElementById('pc-modelo'), proximo = {{ count($itensPc) }};
        function renumerar() {
          var linhas = lista.querySelectorAll('[data-pc-linha]');
          linhas.forEach(function (l, n) { l.querySelector('[data-pc-numero]').textContent = n + 1; l.querySelector('[data-pc-remover]').hidden = linhas.length === 1; });
        }
        document.getElementById('pc-adicionar').addEventListener('click', function () {
          var caixa = document.createElement('div'); caixa.innerHTML = modelo.innerHTML.replace(/__I__/g, String(proximo++)).trim();
          var linha = caixa.firstElementChild; lista.appendChild(linha); window.OeeComponentes.iniciar(linha); renumerar();
        });
        lista.addEventListener('click', function (e) { var b = e.target.closest('[data-pc-remover]'); if (b) { b.closest('[data-pc-linha]').remove(); renumerar(); } });
        renumerar();
      })();
    </script>
  @endif
</section>
@endif

@if ($checklist->isNotEmpty())
<section class="cartao">
  <h2>Checklist da preventiva</h2>
  <form method="post" action="{{ route('os.ordens.checklist', $os) }}">
    @csrf @method('put')
    <div class="rolagem">
    <table class="tabela">
      <thead><tr><th>Item</th><th>Resposta</th><th>Observação</th><th>Respondido</th></tr></thead>
      <tbody>
      @foreach ($checklist as $item)
        <tr>
          <td>{{ $item->descricao }}</td>
          <td>
            @foreach (\App\Models\Manutencao\ChecklistOs::RESPOSTAS as $v => $r)
              <label class="opcao"><input type="radio" name="resposta[{{ $item->id }}]" value="{{ $v }}" @checked($item->resposta === $v) @disabled(! $podeExecutar || ! $editavel)> <span>{{ $r }}</span></label>
            @endforeach
          </td>
          <td><input name="observacao[{{ $item->id }}]" maxlength="300" value="{{ $item->observacao }}" @disabled(! $podeExecutar || ! $editavel)></td>
          <td>{{ $item->respondidoPor?->nome }}@if ($item->respondido_em)<br><small class="suave">@dataHora($item->respondido_em)</small>@endif</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
    @if ($podeExecutar && $editavel)<div class="acoes"><button class="botao" type="submit">Salvar checklist</button></div>@endif
  </form>
</section>
@endif

{{-- Interações e histórico: a conversa de todos os envolvidos (com anexos) e os eventos da SS/OS, em ordem cronológica --}}
<section class="cartao" id="interacoes">
  <h2>Interações e histórico</h2>
  <p class="suave">Conversa entre o solicitante, a manutenção e todos que acompanham esta OS, junto com tudo o que aconteceu com ela, em ordem.
    Anexe documentos, prints (cole com Ctrl+V) ou links.</p>
  <div class="chat">
    @forelse ($conversa as $linha)
      @if ($linha['tipo'] === 'mensagem')
        @php($msg = $linha['item'])
        <article @class(['chat-msg', 'minha' => (int) $msg->usuario_id === (int) auth()->id()])>
          @if ($msg->usuario)@include('partials.avatar', ['usuario' => $msg->usuario])@endif
          <div class="chat-balao">
            <header><strong>{{ $msg->usuario?->nome }}</strong> <small class="suave">@dataHora($msg->criado_em)</small></header>
            @if ($msg->texto)<div class="chat-texto">{!! $msg->textoHtml() !!}</div>@endif
            @if ($msg->anexos->isNotEmpty())
              <div class="chat-anexos">
                @foreach ($msg->anexos as $an)
                  @if ($an->ehImagem())
                    <a href="{{ route('os.interacoes.anexo', $an) }}" target="_blank" class="chat-imagem" title="{{ $an->nome }}"><img src="{{ route('os.interacoes.anexo', $an) }}" alt="{{ $an->nome }}" loading="lazy"></a>
                  @else
                    <a href="{{ route('os.interacoes.anexo', $an) }}" target="_blank" class="chat-arquivo">@include('layouts.partials.icone', ['nome' => 'apontamento']) <span>{{ $an->nome }}<small class="suave">{{ $an->tamanhoLegivel() }}</small></span></a>
                  @endif
                @endforeach
              </div>
            @endif
          </div>
        </article>
      @else
        {{-- Evento do histórico: mesmo balão, com a etiqueta "Histórico" e a mudança de situação. --}}
        @php($h = $linha['item'])
        <article @class(['chat-msg', 'chat-evento', 'minha' => $h->usuario_id !== null && (int) $h->usuario_id === (int) auth()->id(), 'marco' => $h->ehMarco()])>
          @if ($h->usuario)@include('partials.avatar', ['usuario' => $h->usuario])@else<span class="chat-sistema" title="Sistema">@include('layouts.partials.icone', ['nome' => 'cronometro'])</span>@endif
          <div class="chat-balao">
            <header><strong>{{ $h->usuario?->nome ?? 'Sistema' }}</strong> <small class="suave">@dataHora($h->criado_em)</small></header>
            <div class="chat-texto"><span class="chat-etiqueta">Histórico</span> <strong>{{ $h->rotuloAcao() }}</strong>
              @if ($h->mudancaStatus() !== '')<small class="suave chat-status">{{ $h->mudancaStatus() }}</small>@endif</div>
            @if ($h->observacao)<div class="chat-texto os-texto">{{ $h->observacao }}</div>@endif
          </div>
        </article>
      @endif
    @empty
      <p class="suave chat-vazio">Nenhuma interação ainda. Escreva a primeira mensagem.</p>
    @endforelse
  </div>

  @if ($editavel)
  <form method="post" action="{{ route('os.interacoes.store', $os) }}" enctype="multipart/form-data" class="chat-form" id="chat-form">
    @csrf
    <textarea name="texto" rows="2" maxlength="4000" placeholder="Escreva uma mensagem… (links viram clicáveis; cole prints com Ctrl+V)" id="chat-texto">{{ old('texto') }}</textarea>
    <div class="chat-form-rodape">
      <label class="botao secundario pequeno chat-anexar">@include('layouts.partials.icone', ['nome' => 'mais']) Anexar
        <input type="file" name="anexos[]" multiple id="chat-arquivos" hidden
               accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z,.dwg,.dxf,.mp4,.mov"></label>
      <span class="suave chat-limite">até {{ \App\Http\Controllers\Manutencao\InteracaoController::MAX_ARQUIVOS }} arquivos de {{ number_format($limiteAnexoKb / 1024, 1, ',', '.') }} MB</span>
      <span id="chat-selecionados" class="chat-selecionados"></span>
      <button class="botao" type="submit">Enviar</button>
    </div>
  </form>
  <script>
    (function () {
      var arquivos = document.getElementById('chat-arquivos'), lista = document.getElementById('chat-selecionados');
      var texto = document.getElementById('chat-texto'), limite = {{ $limiteAnexoKb }} * 1024;
      function mostrar() {
        lista.textContent = '';
        Array.prototype.forEach.call(arquivos.files, function (f) {
          var chip = document.createElement('span');
          chip.className = 'escolha-chip' + (f.size > limite ? ' grande' : '');
          chip.textContent = f.name + (f.size > limite ? ' (grande demais)' : '');
          lista.appendChild(chip);
        });
      }
      arquivos.addEventListener('change', mostrar);
      // Colar print (Ctrl+V) na caixa de mensagem: entra como anexo.
      texto.addEventListener('paste', function (e) {
        var imagens = Array.prototype.filter.call((e.clipboardData || {}).files || [], function (f) { return f.type.indexOf('image/') === 0; });
        if (!imagens.length) return;
        e.preventDefault();
        var dt = new DataTransfer();
        Array.prototype.forEach.call(arquivos.files, function (f) { dt.items.add(f); });
        imagens.forEach(function (f, i) {
          var nome = 'print-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + (i ? '-' + i : '') + '.png';
          dt.items.add(new File([f], nome, { type: f.type }));
        });
        arquivos.files = dt.files;
        mostrar();
      });
      // Ctrl+Enter envia.
      texto.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) document.getElementById('chat-form').requestSubmit(); });
      var chat = document.querySelector('.chat'); if (chat) chat.scrollTop = chat.scrollHeight;
    })();
  </script>
  @else
    <p class="aviso">Esta OS está {{ mb_strtolower($os->rotuloStatus()) }}: a conversa fica só para leitura.{{ $os->status === 'encerrada' ? '' : ' Para escrever, ela precisa ser reaberta.' }}</p>
  @endif
</section>
@endsection
