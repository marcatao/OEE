@extends('layouts.portal')

@section('titulo', 'Solicitações de serviço')

@use('App\Models\Manutencao\SolicitacaoServico')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço</p>
  <div class="titulo-com-acao">
    <h1>Solicitações de serviço (SS)</h1>
    @if (\App\Support\AcessoOs::podeSolicitar(auth()->user()))
      <a class="botao" href="{{ route('os.solicitacoes.create') }}">@include('layouts.partials.icone', ['nome' => 'mais']) Nova solicitação</a>
    @endif
  </div>
  <p class="suave">Quem tem o perfil Solicitante abre a SS. Você vê as suas, as do seu centro de custo e, se for da manutenção, as da sua área. A manutenção analisa: <strong>aprovada</strong>, ela vira uma ordem de serviço (OS) com os mesmos
    dados; <strong>rejeitada</strong>, o motivo aparece aqui para o solicitante.</p>

  <div class="os-totais">
    <a href="{{ route('os.solicitacoes.index', ['status' => '']) }}" @class(['ativo' => $status === ''])>Todas</a>
    @foreach (SolicitacaoServico::STATUS as $chave => $rotulo)
      <a href="{{ route('os.solicitacoes.index', ['status' => $chave]) }}" @class(['ativo' => $status === $chave])>
        <span class="etiqueta os-{{ $chave }}">{{ $rotulo }}</span> <strong>{{ $totais[$chave] ?? 0 }}</strong></a>
    @endforeach
  </div>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>SS</th><th>Situação</th><th>Tipo</th><th>Prioridade</th><th>Máquina</th><th>Área executante</th><th>Título</th><th>Solicitante</th><th>Aberta em</th></tr></thead>
    <tbody>
    @forelse ($solicitacoes as $ss)
      <tr>
        <td><a href="{{ route('os.solicitacoes.show', $ss) }}"><strong>{{ $ss->numeroFormatado() }}</strong></a>
          @if ($ss->ordemServico)<br><a href="{{ route('os.ordens.show', $ss->ordemServico) }}"><small>{{ $ss->ordemServico->numeroFormatado() }}</small></a>@endif</td>
        <td><span class="etiqueta os-{{ $ss->status }}">{{ $ss->rotuloStatus() }}</span>
          @if ($ss->status === 'rejeitada' && $ss->motivo_rejeicao)<br><small class="erro">{{ \Illuminate\Support\Str::limit($ss->motivo_rejeicao, 60) }}</small>@endif</td>
        <td>{{ $ss->rotuloTipo() }}</td>
        <td><span class="prioridade-{{ $ss->prioridade }}">{{ $ss->rotuloPrioridade() }}</span>@if ($ss->maquina_parada)<br><small class="os-parada">máquina parada</small>@endif
          @if ($ss->risco_integridade)<br><small class="os-parada">risco à integridade</small>@endif
          @if ($ss->automatica)<br><small class="suave">automática</small>@endif</td>
        <td>@include('manutencao._maquina', ['maquina' => $ss->maquina, 'ferramenta' => $ss->ferramenta, 'itemTi' => $ss->itemTi])</td>
        <td>{{ $ss->areaExecutante?->nome }} <span class="etiqueta unidade-{{ $ss->unidade }}">{{ config('oee.unidades')[$ss->unidade] ?? $ss->unidade }}</span></td>
        <td><strong>{{ $ss->titulo ?: \Illuminate\Support\Str::limit($ss->descricao, 60) }}</strong>@if ($ss->titulo)<br><small class="suave">{{ \Illuminate\Support\Str::limit($ss->descricao, 80) }}</small>@endif</td>
        <td>{{ $ss->solicitante?->nome }}</td>
        <td>@dataHora($ss->criado_em)</td>
      </tr>
    @empty
      <tr><td colspan="9" class="suave">Nenhuma solicitação aqui.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $solicitacoes->links('partials.paginacao') }}
</section>
@endsection
