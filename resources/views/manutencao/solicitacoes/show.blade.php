@extends('layouts.portal')

@section('titulo', $ss->numeroFormatado())

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.solicitacoes.index') }}">Solicitações</a></p>
  <div class="titulo-com-acao">
    <h1>{{ $ss->numeroFormatado() }}</h1>
    <div class="os-cabecalho">
      <span class="etiqueta os-{{ $ss->status }}">{{ $ss->rotuloStatus() }}</span>
      @if ($ss->ordemServico)<a class="botao" href="{{ route('os.ordens.show', $ss->ordemServico) }}">Abrir {{ $ss->ordemServico->numeroFormatado() }}</a>@endif
      @if ($podeAprovar || ($ss->solicitante_id === auth()->id() && $ss->status === 'pendente'))
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

  <dl class="dados-curtos">
    <div><dt>Solicitante</dt><dd>{{ $ss->solicitante?->nome }}<br><small class="suave">{{ $ss->centro_custo_solicitante }}</small></dd></div>
    <div><dt>Aberta em</dt><dd>@dataHora($ss->criado_em)</dd></div>
    <div><dt>Tipo</dt><dd>{{ $ss->rotuloTipo() }}</dd></div>
    <div><dt>Prioridade</dt><dd class="prioridade-{{ $ss->prioridade }}">{{ $ss->rotuloPrioridade() }}</dd></div>
    <div><dt>Máquina</dt><dd>@include('manutencao._maquina', ['maquina' => $ss->maquina])@if ($ss->maquina_parada)<br><span class="os-parada">máquina parada</span>@endif</dd></div>
    <div><dt>Área executante</dt><dd>{{ $ss->areaExecutante?->nome }} <span class="etiqueta unidade-{{ $ss->unidade }}">{{ config('oee.unidades')[$ss->unidade] ?? $ss->unidade }}</span></dd></div>
    <div><dt>Centro de custo</dt><dd>{{ $ss->centro_custo ?: '—' }}</dd></div>
  </dl>
  <h2>Descrição</h2>
  <p class="os-texto">{{ $ss->descricao }}</p>

  @if ($ss->status === 'pendente')
    <div class="os-acoes">
      @if ($podeAprovar)
        <form method="post" action="{{ route('os.solicitacoes.aprovar', $ss) }}">
          @csrf
          <label>Distribuir para
            <select name="responsavel_id">
              <option value="">Definir depois</option>
              @foreach ($mantenedores as $m)
                <option value="{{ $m->id }}">{{ $m->nome }}</option>
              @endforeach
            </select>
          </label>
          <button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'check']) Aprovar e emitir OS</button>
        </form>
        <form method="post" action="{{ route('os.solicitacoes.rejeitar', $ss) }}">
          @csrf
          <label>Motivo da rejeição <input name="motivo_rejeicao" maxlength="500" required></label>
          <button class="botao secundario" type="submit">Rejeitar</button>
        </form>
      @endif
      @if ($ss->solicitante_id === auth()->id() || $podeAprovar)
        <form method="post" action="{{ route('os.solicitacoes.cancelar', $ss) }}" onsubmit="return confirm('Cancelar esta solicitação?')">
          @csrf
          <button class="botao secundario" type="submit">Cancelar solicitação</button>
        </form>
      @endif
    </div>
  @endif
</section>

<section class="cartao">
  <h2>Histórico</h2>
  @include('manutencao._historico', ['historico' => $historico])
</section>
@endsection
