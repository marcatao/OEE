@extends('layouts.portal')

@section('titulo', 'Apontamentos OP')

@use('App\Models\OrdemProducao')
@use('App\Support\Formato')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção</p>
  <h1>Apontamentos OP</h1>
  <p class="suave">Movimentos do Protheus ligados a uma OP, só para consulta: produções e baixas/consumos da SD3 (sem os excluídos e
    os estornados) e itens de nota fiscal da SD2 que citam a OP. O valor é o custo do movimento (D3_CUSTO1 / D2_CUSTO1) ou, enquanto o
    Protheus não o valorizou, quantidade × custo médio de estoque (SB2).
    @if ($ultimaSincronizacao) Última sincronização: @dataHora($ultimaSincronizacao). @else Ainda não sincronizados. @endif @include('partials.proxima-sincronizacao', ['chave' => 'movimentos_producao'])</p>

  <div class="op-totais">
    @foreach ($tipos->tipos() as $chave => $rotulo)
      @if (isset($totais[$chave]) || $chave !== 'outro')
        <a href="{{ route('apontamentos-producao.index', ['tipo' => [$chave]]) }}" @class(['op-total', 'ativo' => $tipo === [$chave]])>
          <span class="etiqueta mov-{{ $chave }}">{{ $rotulo }}</span>
          <strong>{{ Formato::moeda((float) ($totais[$chave]->valor ?? 0)) }}</strong>
          <small class="suave">{{ Formato::inteiro((int) ($totais[$chave]->linhas ?? 0)) }} mov.</small>
        </a>
      @endif
    @endforeach
  </div>

  <form method="get" class="filtros">
    <label>Buscar <input name="busca" value="{{ $busca }}" placeholder="OP, produto, descrição ou documento"></label>
    <label>De <input type="date" name="de" value="{{ $de }}"></label>
    <label>Até <input type="date" name="ate" value="{{ $ate }}"></label>
    <fieldset class="op-filtro-status">
      <legend>Movimento</legend>
      @foreach ($tipos->tipos() as $chave => $rotulo)
        <label class="opcao"><input type="checkbox" name="tipo[]" value="{{ $chave }}" @checked(in_array($chave, $tipo, true))> <span>{{ $rotulo }}</span></label>
      @endforeach
    </fieldset>
    <button class="botao secundario" type="submit">Filtrar</button>
    @if ($busca !== '' || $tipo || $de !== '' || $ate !== '')<a href="{{ route('apontamentos-producao.index') }}">Limpar</a>@endif
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Data</th><th>OP</th><th>Movimento</th><th>Produto</th><th class="num">Quantidade</th><th class="num">Valor</th>
      <th>Documento</th><th>Armazém</th></tr></thead>
    <tbody>
    @forelse ($movimentos as $m)
      <tr>
        <td>{{ OrdemProducao::data($m->emissao) }}</td>
        <td>
          @if (isset($ops[rtrim($m->filial).'|'.$m->op_numero]) && auth()->user()->pode('ordens_producao'))
            <a href="{{ route('ordens-producao.show', $ops[rtrim($m->filial).'|'.$m->op_numero]) }}"><code>{{ $m->op_numero }}</code></a>
          @else
            <code>{{ $m->op_numero }}</code>
          @endif
        </td>
        <td><span class="etiqueta mov-{{ $m->tipo }}">{{ $tipos->rotulo($m->tipo) }}</span><br>
          <small class="suave">@if ($m->tipo !== 'nota_fiscal'){{ $m->cf }} · @endif{{ $tipos->descricao($m->tipo === 'nota_fiscal' ? 'NF' : $m->cf) }}</small></td>
        <td class="quebra"><code>{{ $m->produto }}</code><br><small>{{ ($produtos[rtrim($m->produto)] ?? null)?->descricao() ?? 'produto não sincronizado' }}</small></td>
        <td class="num">{{ Formato::quantidade((float) $m->quantidade) }} <small class="suave">{{ $m->um }}</small></td>
        <td class="num">{{ Formato::moeda((float) $m->custo) }}@if ($m->tipo !== 'producao' && $m->valorizacao === 'medio')<br><small class="suave">custo médio</small>@elseif ($m->tipo !== 'producao' && $m->valorizacao === 'sem')<br><small class="texto-alerta">sem custo</small>@endif</td>
        <td>{{ $m->documento }}@if (trim($m->serie) !== '') <small class="suave">série {{ $m->serie }}</small>@endif</td>
        <td>{{ $m->armazem }}</td>
      </tr>
    @empty
      <tr><td colspan="8" class="suave">Nenhum movimento encontrado. Os movimentos vêm do Protheus: sincronize em Administração › Banco de dados.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $movimentos->links('partials.paginacao') }}

  <h3 style="margin-top:1.5rem">Legendas do D3_CF</h3>
  <ul class="legendas-mov">
    @foreach ($tipos->legendas() as $cf => $descricao)
      <li><span class="etiqueta mov-{{ $tipos->tipoDoCf($cf) }}"><code>{{ $cf }}</code></span> {{ $descricao }}</li>
    @endforeach
  </ul>
</section>
@endsection
