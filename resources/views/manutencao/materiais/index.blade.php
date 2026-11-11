@extends('layouts.portal')

@section('titulo', 'Materiais das OS')

@use('App\Models\Manutencao\MaterialOs')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço</p>
  <h1>Materiais (almoxarifado e compras)</h1>
  <p class="suave">Requisições dos mantenedores. Tem em estoque: <em>Disponível</em>. Não tem: <em>Em compra</em> (informe a solicitação de
    compra), depois <em>Recebido</em> e <em>Entregue à manutenção</em>.</p>
  @include('manutencao._erros')
  <div class="os-totais">
    <a href="{{ route('os.materiais.index', ['status' => 'pendentes']) }}" @class(['ativo' => $status === 'pendentes'])>Pendentes</a>
    @foreach (MaterialOs::STATUS as $v => $r)<a href="{{ route('os.materiais.index', ['status' => $v]) }}" @class(['ativo' => $status === $v])>{{ $r }}</a>@endforeach
  </div>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>OS</th><th>Material</th><th class="num">Qtde</th><th>Requisitado</th><th>Atualizar</th></tr></thead>
    <tbody>
    @forelse ($materiais as $m)
      <tr>
        <td><a href="{{ route('os.ordens.show', $m->ordem_servico_id) }}">{{ $m->ordemServico?->numeroFormatado() }}</a><br><small class="suave">@include('manutencao._maquina', ['maquina' => $m->ordemServico?->maquina])</small></td>
        <td>@if ($m->produto_codigo)<code>{{ $m->produto_codigo }}</code> @endif{{ $m->descricao }}<br><span class="etiqueta os-{{ $m->status }}">{{ $m->rotuloStatus() }}</span></td>
        <td class="num">{{ number_format((float) $m->quantidade, 2, ',', '.') }} {{ $m->um }}</td>
        <td>{{ $m->criadoPor?->nome }}<br><small class="suave">@dataHora($m->criado_em)</small></td>
        <td>
          @if (! $m->ordemServico?->estaAberta())
            <small class="suave">OS {{ mb_strtolower($m->ordemServico?->rotuloStatus() ?? '') }}: reabra para alterar.</small>
          @else
          <form method="post" action="{{ route('os.materiais.update', $m) }}" class="os-acoes" style="margin:0">
            @csrf @method('put')
            <select name="status" aria-label="Situação">@foreach (MaterialOs::STATUS as $v => $r)<option value="{{ $v }}" @selected($m->status === $v)>{{ $r }}</option>@endforeach</select>
            <input name="solicitacao_compra" maxlength="30" size="10" placeholder="Sol. compra" value="{{ $m->solicitacao_compra }}" aria-label="Solicitação de compra">
            <input type="number" step="0.0001" min="0" name="custo_unitario" placeholder="Custo unit." value="{{ $m->custo_unitario }}" aria-label="Custo unitário" style="width:7rem">
            <input name="observacao" maxlength="500" placeholder="Observação" value="{{ $m->observacao }}" aria-label="Observação">
            <button class="botao pequeno" type="submit">Salvar</button>
          </form>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="5" class="suave">Nenhuma requisição.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $materiais->links('partials.paginacao') }}
</section>
@endsection
