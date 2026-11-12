@extends('layouts.portal')

@section('titulo', 'Solicitações de material')

@use('App\Models\Manutencao\SolicitacaoMaterial')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço</p>
  <div class="titulo-com-acao">
    <h1>Solicitações de material</h1>
    @if ($podeSolicitar)
      <a class="botao" href="{{ route('os.materiais-solicitacao.create') }}">@include('layouts.partials.icone', ['nome' => 'mais']) Nova solicitação</a>
    @endif
  </div>
  <p class="suave">O mantenedor pede o material à administração da área; o administrador analisa item a item (aprova a quantidade ou
    reprova). Depois o item aprovado vira requisição ao almoxarifado ou solicitação de compra.</p>

  <div class="os-totais">
    <a href="{{ route('os.materiais-solicitacao.index', ['status' => 'pendentes']) }}" @class(['ativo' => $status === 'pendentes'])>A analisar <strong>{{ ($totais['pendente'] ?? 0) + ($totais['em_analise'] ?? 0) }}</strong></a>
    @foreach (SolicitacaoMaterial::STATUS as $chave => $rotulo)
      <a href="{{ route('os.materiais-solicitacao.index', ['status' => $chave]) }}" @class(['ativo' => $status === $chave])><span class="etiqueta sm-{{ $chave }}">{{ $rotulo }}</span> <strong>{{ $totais[$chave] ?? 0 }}</strong></a>
    @endforeach
    <a href="{{ route('os.materiais-solicitacao.index', ['status' => '']) }}" @class(['ativo' => $status === ''])>Todas</a>
  </div>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Solicitação</th><th>Situação</th><th>Área</th><th>Solicitante</th><th>Pedida em</th><th class="num">Itens</th><th class="num">A analisar</th><th class="num">Aprovados</th></tr></thead>
    <tbody>
    @forelse ($solicitacoes as $sm)
      <tr>
        <td><a href="{{ route('os.materiais-solicitacao.show', $sm) }}"><strong>{{ $sm->numeroFormatado() }}</strong></a></td>
        <td><span class="etiqueta sm-{{ $sm->status }}">{{ $sm->rotuloStatus() }}</span></td>
        <td><span class="etiqueta unidade-{{ $sm->unidade }}">{{ config('oee.unidades')[$sm->unidade] ?? $sm->unidade }}</span></td>
        <td><span class="os-pessoa">@if ($sm->solicitante)@include('partials.avatar', ['usuario' => $sm->solicitante])@endif {{ $sm->solicitante?->nome }}</span></td>
        <td>@dataHora($sm->criado_em)</td>
        <td class="num">{{ $sm->itens_count }}</td>
        <td class="num {{ $sm->itens_pendentes_count ? 'texto-alerta' : 'suave' }}">{{ $sm->itens_pendentes_count }}</td>
        <td class="num">{{ $sm->itens_aprovados_count }}</td>
      </tr>
    @empty
      <tr><td colspan="8" class="suave">Nenhuma solicitação de material aqui.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $solicitacoes->links('partials.paginacao') }}
</section>
@endsection
