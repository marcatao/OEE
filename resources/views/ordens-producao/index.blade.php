@extends('layouts.portal')

@section('titulo', 'Ordens de produção')

@use('App\Models\OrdemProducao')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção</p>
  <h1>Ordens de produção</h1>
  <p class="suave">OPs do Protheus (tabela SC2), só para consulta.
    @if ($ultimaSincronizacao) Última sincronização: @dataHora($ultimaSincronizacao). @else Ainda não sincronizadas. @endif
    O <strong>status</strong> é calculado pelo portal: <em>fechada</em> (com data de encerramento, C2_DATRF, no Protheus), <em>parada</em> (suspensa no
    Protheus ou com apontamento de parada em aberto), <em>andamento</em> (com produção ou apontamentos) ou <em>aberta</em>.</p>

  <div class="op-totais">
    @foreach (OrdemProducao::STATUS as $chave => $rotulo)
      <a href="{{ route('ordens-producao.index', ['status' => [$chave]]) }}" @class(['op-total', 'ativo' => $status === [$chave]])>
        <span class="etiqueta op-{{ $chave }}">{{ $rotulo }}</span> <strong>{{ number_format($totais[$chave] ?? 0, 0, ',', '.') }}</strong>
      </a>
    @endforeach
  </div>

  <form method="get" class="filtros">
    <label>Buscar <input name="busca" value="{{ $busca }}" placeholder="Número da OP, código ou descrição do produto"></label>
    <fieldset class="op-filtro-status">
      <legend>Status</legend>
      @foreach (OrdemProducao::STATUS as $chave => $rotulo)
        <label class="opcao"><input type="checkbox" name="status[]" value="{{ $chave }}" @checked(in_array($chave, $status, true))> <span>{{ $rotulo }}</span></label>
      @endforeach
    </fieldset>
    <button class="botao secundario" type="submit">Filtrar</button>
    @if ($busca !== '' || $status)<a href="{{ route('ordens-producao.index') }}">Limpar</a>@endif
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>OP</th><th>Status</th><th>Produto</th><th class="num">Quantidade</th><th class="num">Produzido</th><th class="num">Perda</th>
      <th>Emissão</th><th>Previsão</th><th>Encerrada em</th><th class="num">Apontamentos</th></tr></thead>
    <tbody>
    @forelse ($ordens as $op)
      @php($produto = $produtos[$op->c2_produto] ?? null)
      <tr>
        <td><code>{{ $op->numero }}</code>@if (trim($op->c2_filial) !== '')<br><small class="suave">filial {{ $op->c2_filial }}</small>@endif</td>
        <td><span class="etiqueta op-{{ $op->status }}">{{ $op->rotuloStatus() }}</span>@if ($op->c2_tpop === 'P')<br><small class="suave">prevista</small>@endif</td>
        <td class="quebra"><code>{{ $op->c2_produto }}</code><br><small>{{ $produto?->descricao() ?? 'produto não sincronizado' }}</small></td>
        <td class="num">{{ number_format($op->c2_quant, 0, ',', '.') }} <small class="suave">{{ $op->c2_um ?: $produto?->b1_um }}</small></td>
        <td class="num">{{ number_format($op->c2_quje, 0, ',', '.') }}
          @if ($op->c2_quant > 0)<br><small class="suave">{{ number_format(min($op->c2_quje / $op->c2_quant, 9.99) * 100, 0, ',', '.') }}%</small>@endif</td>
        <td class="num">{{ number_format($op->c2_perda, 0, ',', '.') }}</td>
        <td>{{ OrdemProducao::data($op->c2_emissao) }}</td>
        <td>{{ OrdemProducao::data($op->c2_datpri) }} a {{ OrdemProducao::data($op->c2_datprf) }}</td>
        <td>{{ OrdemProducao::data($op->c2_datrf) }}</td>
        <td class="num">{{ $op->apontamentos_count }}</td>
      </tr>
    @empty
      <tr><td colspan="10" class="suave">Nenhuma ordem de produção encontrada. As OPs vêm do Protheus: sincronize em Administração › Banco de dados.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $ordens->links('partials.paginacao') }}
</section>
@endsection
