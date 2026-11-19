@extends('layouts.portal')

@section('titulo', $ss->numeroFormatado())

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.solicitacoes.index') }}">Solicitações</a></p>
  <div class="titulo-com-acao">
    <h1>{{ $ss->numeroFormatado() }}@if ($ss->titulo) — {{ $ss->titulo }}@endif</h1>
    <div class="os-cabecalho">
      <span class="etiqueta os-{{ $ss->status }}">{{ $ss->rotuloStatus() }}</span>
      @if ($ss->ordemServico)<a class="botao" href="{{ route('os.ordens.show', $ss->ordemServico) }}">Abrir {{ $ss->ordemServico->numeroFormatado() }}</a>@endif
      @if (! $revisar && (int) $ss->solicitante_id === (int) auth()->id() && $ss->status === 'pendente')
        <a class="botao secundario" href="{{ route('os.solicitacoes.edit', $ss) }}">Editar</a>
      @endif
    </div>
  </div>
  @include('manutencao._erros')

  @if ($ss->status === 'rejeitada')
    <p class="aviso erro"><strong>Rejeitada</strong> por {{ $ss->analisadoPor?->nome }} em @dataHora($ss->analisado_em): {{ $ss->motivo_rejeicao }}</p>
  @elseif ($ss->status === 'aprovada')
    <p class="aviso ok">Aprovada por {{ $ss->analisadoPor?->nome }} em @dataHora($ss->analisado_em).</p>
  @endif

  @if ($revisar)
    {{-- Aprovador: revisa e altera o que precisar direto aqui, escolhe os mantenedores e aprova. --}}
    <dl class="dados-curtos">
      <div><dt>Solicitante</dt><dd>{{ $ss->solicitante?->nome }}<br><small class="suave">{{ $ss->centro_custo_solicitante }}</small></dd></div>
      <div><dt>Aberta em</dt><dd>@dataHora($ss->criado_em)</dd></div>
    </dl>
    <form method="post" action="{{ route('os.solicitacoes.aprovar', $ss) }}" class="formulario-vertical ss-form ss-revisao">
      @csrf
      <p class="aviso">Revise a solicitação: você pode alterar qualquer campo antes de aprovar. Depois de aprovada, a OS não muda mais.</p>
      @include('manutencao.solicitacoes._campos', $campos)
      @include('manutencao._escolher-mantenedores', ['area' => $ss->areaExecutante?->nome, 'mantenedores' => $mantenedores, 'marcados' => [], 'fixo' => $meuMantenedor])
      <div class="acoes">
        <button class="botao" type="submit" name="acao" value="aprovar">@include('layouts.partials.icone', ['nome' => 'check']) Aprovar e emitir OS</button>
        <button class="botao secundario" type="submit" name="acao" value="salvar">Só salvar as alterações</button>
      </div>
    </form>
    <div class="ss-analise">
      <form method="post" action="{{ route('os.solicitacoes.rejeitar', $ss) }}" class="ss-analise-bloco ss-rejeitar">
        @csrf
        <label>Motivo da rejeição <input name="motivo_rejeicao" maxlength="500" required placeholder="O motivo vai para o solicitante"></label>
        <button class="botao secundario" type="submit">Rejeitar</button>
      </form>
      @if ($podeCancelar)
        <form method="post" action="{{ route('os.solicitacoes.cancelar', $ss) }}" class="ss-analise-bloco" onsubmit="return confirm('Cancelar esta solicitação?')">
          @csrf
          <button class="botao secundario" type="submit">Cancelar solicitação</button>
        </form>
      @endif
    </div>
  @else
    <dl class="dados-curtos">
      <div><dt>Solicitante</dt><dd>{{ $ss->solicitante?->nome }}<br><small class="suave">{{ $ss->centro_custo_solicitante }}</small></dd></div>
      <div><dt>Aberta em</dt><dd>@dataHora($ss->criado_em)</dd></div>
      <div><dt>Tipo</dt><dd>{{ $ss->rotuloTipo() }}</dd></div>
      <div><dt>Prioridade</dt><dd class="prioridade-{{ $ss->prioridade }}">{{ $ss->rotuloPrioridade() }}</dd></div>
      <div><dt>Máquina</dt><dd>@include('manutencao._maquina', ['maquina' => $ss->maquina])@if ($ss->maquina_parada)<br><span class="os-parada">máquina parada</span>@endif
        @if ($ss->risco_integridade)<br><span class="os-parada">oferece risco à integridade</span>@endif</dd></div>
      <div><dt>Área executante</dt><dd>{{ $ss->areaExecutante?->nome }} <span class="etiqueta unidade-{{ $ss->unidade }}">{{ config('oee.unidades')[$ss->unidade] ?? $ss->unidade }}</span></dd></div>
      <div><dt>Centro de custo</dt><dd>{{ $ss->centro_custo ?: '—' }}</dd></div>
    </dl>
    <h2>Descrição</h2>
    <p class="os-texto">{{ $ss->descricao }}</p>

    @if ($ss->status === 'pendente' && ($podeAprovar || $podeCancelar))
      <div class="ss-analise">
        @if ($podeAprovar)
          <h2>Análise da solicitação</h2>
          <form method="post" action="{{ route('os.solicitacoes.aprovar', $ss) }}" class="ss-analise-bloco">
            @csrf
            @include('manutencao._escolher-mantenedores', ['area' => $ss->areaExecutante?->nome, 'mantenedores' => $mantenedores, 'marcados' => [], 'fixo' => $meuMantenedor])
            <div class="acoes"><button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'check']) Aprovar e emitir OS</button></div>
          </form>
          <form method="post" action="{{ route('os.solicitacoes.rejeitar', $ss) }}" class="ss-analise-bloco ss-rejeitar">
            @csrf
            <label>Motivo da rejeição <input name="motivo_rejeicao" maxlength="500" required placeholder="O motivo vai para o solicitante"></label>
            <button class="botao secundario" type="submit">Rejeitar</button>
          </form>
        @endif
        @if ($podeCancelar)
          <form method="post" action="{{ route('os.solicitacoes.cancelar', $ss) }}" class="ss-analise-bloco" onsubmit="return confirm('Cancelar esta solicitação?')">
            @csrf
            <button class="botao secundario" type="submit">Cancelar solicitação</button>
          </form>
        @endif
      </div>
    @endif
  @endif
</section>

<section class="cartao">
  <h2>Histórico</h2>
  @include('manutencao._historico', ['historico' => $historico])
</section>
@endsection
