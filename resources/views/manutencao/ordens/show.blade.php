@extends('layouts.portal')

@section('titulo', $os->numeroFormatado())

@use('App\Models\Manutencao\MaterialOs')
@use('App\Models\ApontamentoEstado')
@use('App\Services\Manutencao\HorasManutencao')

@section('conteudo')
@php($aberta = in_array($os->status, ['aberta', 'em_execucao', 'aguardando_material'], true))
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.ordens.index') }}">OS</a></p>
  <div class="titulo-com-acao">
    <h1>{{ $os->numeroFormatado() }} <small class="suave">{{ $os->rotuloTipo() }}</small></h1>
    <div class="os-cabecalho">
      <span class="etiqueta os-{{ $os->status }}">{{ $os->rotuloStatus() }}</span>
      <a class="botao secundario" href="{{ route('os.ordens.imprimir', $os) }}" target="_blank">@include('layouts.partials.icone', ['nome' => 'imprimir']) Imprimir</a>
      @if ($podeAprovar)<a class="botao secundario" href="{{ route('os.ordens.edit', $os) }}">Editar</a>@endif
    </div>
  </div>
  @include('manutencao._erros')

  <dl class="dados-curtos">
    <div><dt>Máquina</dt><dd>@include('manutencao._maquina', ['maquina' => $os->maquina])@if ($os->maquina_parada)<br><span class="os-parada">máquina parada</span>@endif</dd></div>
    <div><dt>Prioridade</dt><dd class="prioridade-{{ $os->prioridade }}">{{ $os->rotuloPrioridade() }}</dd></div>
    <div><dt>Área executante</dt><dd>{{ $os->areaExecutante?->nome }} <span class="etiqueta unidade-{{ $os->unidade }}">{{ config('oee.unidades')[$os->unidade] ?? $os->unidade }}</span></dd></div>
    <div><dt>Mantenedores</dt><dd>{{ $os->mantenedoresAtribuidos()->map->nomeExibicao()->join(', ') ?: 'definir depois' }}</dd></div>
    <div><dt>Centro de custo</dt><dd>{{ $os->centro_custo ?: '—' }}</dd></div>
    <div><dt>Solicitante</dt><dd>{{ $os->solicitante?->nome ?? ($os->plano ? 'PAMP '.$os->plano->codigo : '—') }}@if ($os->solicitacao)<br><a href="{{ route('os.solicitacoes.show', $os->solicitacao) }}"><small>{{ $os->solicitacao->numeroFormatado() }}</small></a>@endif</dd></div>
    <div><dt>Aprovada por</dt><dd>{{ $os->aprovadoPor?->nome ?? '—' }}</dd></div>
    <div><dt>Emitida</dt><dd>@dataHora($os->criado_em)@if ($os->prevista_para)<br><small>prevista {{ $os->prevista_para->format('d/m/Y') }}</small>@endif</dd></div>
    <div><dt>Início / conclusão</dt><dd>{{ $os->iniciada_em ? \App\Providers\AppServiceProvider::dataHora($os->iniciada_em) : '—' }}<br>{{ $os->concluida_em ? \App\Providers\AppServiceProvider::dataHora($os->concluida_em) : '—' }}</dd></div>
    <div><dt>Horas apontadas</dt><dd><strong>{{ HorasManutencao::hhmm($os->total_minutos) }}</strong></dd></div>
  </dl>
  <h2>Descrição</h2>
  <p class="os-texto">{{ $os->descricao }}</p>
  @if ($os->solucao)<h2>Serviço realizado</h2><p class="os-texto">{{ $os->solucao }}</p>@endif
  @if ($os->status === 'cancelada')<p class="aviso erro">Cancelada: {{ $os->cancelamento_motivo }}</p>@endif
  @if ($os->aceite_em)<p class="aviso ok">Aceite de {{ $os->aceitePor?->nome }} em @dataHora($os->aceite_em).@if ($os->aceite_observacao) {{ $os->aceite_observacao }}@endif</p>@endif

  <div class="os-acoes">
    @if ($podeExecutar && $acoes)
      <form method="post" action="{{ route('os.ordens.acao', $os) }}">
        @csrf
        <label>Observação <input name="observacao" maxlength="2000" placeholder="opcional (obrigatória para cancelar)"></label>
        @foreach ($acoes as $acao => $rotulo)
          @if (! in_array($acao, ['cancelar', 'reabrir'], true) || $podeAprovar)
            <button class="botao {{ in_array($acao, ['cancelar', 'reabrir', 'aguardar_material'], true) ? 'secundario' : '' }}" type="submit" name="acao" value="{{ $acao }}"
              @if ($acao === 'cancelar') onclick="return confirm('Cancelar esta OS?')" @endif>{{ $rotulo }}</button>
          @endif
        @endforeach
      </form>
    @endif
    @if ($podeAprovar && $aberta)
      <details style="flex-basis:100%">
        <summary class="botao secundario">Distribuir mantenedores</summary>
        <form method="post" action="{{ route('os.ordens.distribuir', $os) }}" class="formulario-vertical">
          @csrf
          @include('manutencao._escolher-mantenedores', ['mantenedores' => $mantenedores, 'marcados' => $os->mantenedoresAtribuidos()->pluck('id')->all()])
          <div class="acoes"><button class="botao" type="submit">Salvar mantenedores</button></div>
        </form>
      </details>
    @endif
  </div>

  @if ($podeAceitar)
    <form method="post" action="{{ route('os.ordens.aceite', $os) }}" class="os-rodando">
      @csrf
      <p><strong>Serviço entregue.</strong> Confira o resultado e dê o aceite (vale como sua assinatura) ou recuse explicando o que faltou.</p>
      <label>Observação <input name="observacao" maxlength="500"></label>
      <div class="acoes">
        <button class="botao" type="submit" name="resultado" value="aceito">@include('layouts.partials.icone', ['nome' => 'check']) Aceitar o serviço</button>
        <button class="botao secundario" type="submit" name="resultado" value="recusado">Recusar (volta para execução)</button>
      </div>
    </form>
  @endif
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
          @if ($ap->fim === null && ($podeAprovar || $meuMantenedor?->id === $ap->mantenedor_id))
            <form method="post" action="{{ route('os.apontamentos.encerrar', $ap) }}" class="os-acoes" style="margin:0">
              @csrf
              <input name="descricao" maxlength="1000" required placeholder="O que foi feito" value="{{ $ap->descricao }}">
              <button class="botao pequeno" type="submit">Encerrar</button>
            </form>
          @else
            {{ $ap->descricao }}
          @endif
        </td>
        <td>{{ $ap->criadoPor?->nome }}</td>
        <td>
          @if ($ap->fim && ($podeAprovar || $meuMantenedor?->id === $ap->mantenedor_id))
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
  @if ($podeAprovar)
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
              <label class="opcao"><input type="radio" name="resposta[{{ $item->id }}]" value="{{ $v }}" @checked($item->resposta === $v) @disabled(! $podeExecutar)> <span>{{ $r }}</span></label>
            @endforeach
          </td>
          <td><input name="observacao[{{ $item->id }}]" maxlength="300" value="{{ $item->observacao }}" @disabled(! $podeExecutar)></td>
          <td>{{ $item->respondidoPor?->nome }}@if ($item->respondido_em)<br><small class="suave">@dataHora($item->respondido_em)</small>@endif</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
    @if ($podeExecutar)<div class="acoes"><button class="botao" type="submit">Salvar checklist</button></div>@endif
  </form>
</section>
@endif

<section class="cartao">
  <h2>Histórico</h2>
  @include('manutencao._historico', ['historico' => $historico])
</section>
@endsection
