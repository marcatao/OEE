@extends('layouts.portal')

@section('titulo', 'Estruturas de produto')

@use('App\Models\OrdemProducao')
@use('App\Support\Formato')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção</p>
  <h1>Estruturas de produto</h1>
  <p class="suave">Lista de materiais do Protheus (tabela SG1), só para consulta: os componentes de cada produto e a quantidade por unidade.
    @if ($ultimaSincronizacao) Última sincronização: @dataHora($ultimaSincronizacao). @else Ainda não sincronizadas. @endif @include('partials.proxima-sincronizacao', ['chave' => 'estruturas'])
    Clique num componente marcado com <em>estrutura</em> para ver o nível seguinte.</p>

  <form method="get" class="filtros">
    <label>Buscar <input name="busca" value="{{ $busca }}" placeholder="Código ou descrição do produto, ou código do componente"></label>
    <label>Validade
      <select name="validade">
        <option value="vigentes" @selected($validade === 'vigentes')>Vigentes hoje</option>
        <option value="todas" @selected($validade === 'todas')>Todas</option>
      </select>
    </label>
    @if ($produto !== '')<input type="hidden" name="produto" value="{{ $produto }}">@endif
    <button class="botao secundario" type="submit">Filtrar</button>
    @if ($busca !== '' || $produto !== '' || $validade !== 'vigentes')<a href="{{ route('estruturas.index') }}">Limpar</a>@endif
  </form>
  @if ($produto !== '')
    <p>Estrutura do produto <code>{{ $produto }}</code> {{ ($produtos[$produto] ?? null)?->descricao() }}
      · <a href="{{ route('estruturas.index', ['validade' => $validade]) }}">ver todas</a></p>
  @endif

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Produto</th><th>Seq.</th><th>Componente</th><th class="num">Quantidade</th><th class="num">Perda</th><th>Qtd.</th>
      <th>Validade</th><th>Revisão</th></tr></thead>
    <tbody>
    @forelse ($linhas as $l)
      <tr>
        <td class="quebra"><a href="{{ route('estruturas.index', ['produto' => rtrim($l->g1_cod), 'validade' => $validade]) }}"><code>{{ $l->g1_cod }}</code></a>
          <br><small>{{ ($produtos[rtrim($l->g1_cod)] ?? null)?->descricao() }}</small></td>
        <td>{{ $l->g1_trt ?: '-' }}</td>
        <td class="quebra"><code>{{ $l->g1_comp }}</code>
          @if (isset($comEstrutura[rtrim($l->g1_comp)]))<a class="etiqueta op-andamento" href="{{ route('estruturas.index', ['produto' => rtrim($l->g1_comp), 'validade' => $validade]) }}">estrutura</a>@endif
          <br><small>{{ ($produtos[rtrim($l->g1_comp)] ?? null)?->descricao() ?? 'produto não sincronizado' }}</small></td>
        <td class="num">{{ Formato::quantidade($l->g1_quant) }} <small class="suave">{{ ($produtos[rtrim($l->g1_comp)] ?? null)?->b1_um }}</small></td>
        <td class="num">{{ $l->g1_perda > 0 ? Formato::quantidade($l->g1_perda).'%' : '-' }}</td>
        <td>{{ ['F' => 'Fixa', 'V' => 'Variável'][trim($l->g1_fixvar)] ?? '-' }}</td>
        <td>{{ OrdemProducao::data($l->g1_ini) }} a {{ OrdemProducao::data($l->g1_fim) }}</td>
        <td>{{ trim($l->g1_revini.' – '.$l->g1_revfim, ' –') ?: '-' }}</td>
      </tr>
    @empty
      <tr><td colspan="8" class="suave">Nenhuma estrutura encontrada. As estruturas vêm do Protheus: sincronize em Segurança › Banco de dados.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $linhas->links('partials.paginacao') }}
</section>
@endsection
