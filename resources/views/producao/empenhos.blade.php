@extends('layouts.portal')

@section('titulo', 'Empenhos OP')

@use('App\Models\OrdemProducao')
@use('App\Support\Formato')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção</p>
  <h1>Empenhos OP</h1>
  <p class="suave">Materiais reservados para cada ordem de produção no Protheus (tabela SD4), só para consulta. Empenhado = quantidade
    original; saldo = o que ainda falta baixar (cai conforme as baixas da OP).
    @if ($ultimaSincronizacao) Última sincronização: @dataHora($ultimaSincronizacao). @else Ainda não sincronizados. @endif @include('partials.proxima-sincronizacao', ['chave' => 'empenhos_op'])</p>

  <form method="get" class="filtros">
    <label>Buscar <input name="busca" value="{{ $busca }}" placeholder="OP, componente, descrição ou lote"></label>
    <input type="hidden" name="abertas" value="0">
    <label class="opcao"><input type="checkbox" name="abertas" value="1" @checked($abertas)> <span>Só OPs não fechadas</span></label>
    <label class="opcao"><input type="checkbox" name="saldo" value="1" @checked($saldo)> <span>Só com saldo a baixar</span></label>
    <button class="botao secundario" type="submit">Filtrar</button>
    @if ($busca !== '' || $saldo || ! $abertas)<a href="{{ route('empenhos-op.index') }}">Limpar</a>@endif
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Data</th><th>OP</th><th>Componente</th><th>Seq. (TRT)</th><th>Armazém</th><th>Lote</th><th class="num">Empenhado</th>
      <th class="num">Baixado</th><th class="num">Saldo</th><th class="num">Atendido</th></tr></thead>
    <tbody>
    @forelse ($empenhos as $e)
      @php($op = $ops[rtrim($e->d4_filial).'|'.$e->op_numero] ?? null)
      @php($componente = $produtos[rtrim($e->d4_cod)] ?? null)
      <tr>
        <td>{{ OrdemProducao::data($e->d4_data) }}</td>
        <td class="quebra">
          @if ($op && auth()->user()->pode('ordens_producao'))
            <a href="{{ route('ordens-producao.show', $op) }}#aba-empenhos"><code>{{ $e->op_numero }}</code></a>
          @else
            <code>{{ $e->op_numero }}</code>
          @endif
          @if ($op)<span class="etiqueta op-{{ $op->status }}">{{ $op->rotuloStatus() }}</span>
            <br><small class="suave">{{ rtrim($op->c2_produto) }} {{ ($produtos[rtrim($op->c2_produto)] ?? null)?->descricao() }}</small>@endif
        </td>
        <td class="quebra"><code>{{ $e->d4_cod }}</code><br><small>{{ $componente?->descricao() ?? 'produto não sincronizado' }}</small></td>
        <td>{{ $e->d4_trt ?: '-' }}</td>
        <td>{{ $e->d4_local }}</td>
        <td>{{ $e->d4_lotectl ?: '-' }}</td>
        <td class="num">{{ Formato::quantidade($e->d4_qtdeori) }} <small class="suave">{{ $componente?->b1_um }}</small></td>
        <td class="num">{{ Formato::quantidade($e->baixado()) }}</td>
        <td class="num">{{ Formato::quantidade($e->d4_quant) }}</td>
        <td class="num">{{ Formato::pct($e->atendido()) }}</td>
      </tr>
    @empty
      <tr><td colspan="10" class="suave">Nenhum empenho encontrado. Os empenhos vêm do Protheus: sincronize em Segurança › Banco de dados.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $empenhos->links('partials.paginacao') }}
</section>
@endsection
