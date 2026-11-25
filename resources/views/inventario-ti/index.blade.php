@extends('layouts.portal')

@section('titulo', 'Inventário de TI')

@use('App\Models\InventarioTi\ItemTi')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Tecnologia</p>
  <div class="titulo-com-acao">
    <h1>Inventário de TI</h1>
    @if ($podeCadastrar)
      <div class="ti-acoes">
        <a class="botao secundario" href="{{ route('inventario-ti.tipos') }}">Tipos de item</a>
        <a class="botao" href="{{ route('inventario-ti.create') }}">@include('layouts.partials.icone', ['nome' => 'mais']) Novo item</a>
      </div>
    @endif
  </div>
  <p class="suave">Equipamentos, licenças de software e linhas/chips da empresa: com quem estão, onde, em que situação e o histórico de
    cada um. Ao entregar um equipamento, o responsável assina o termo de responsabilidade pelo portal (celular ou computador).</p>

  @php($filtrosSemSituacao = array_filter(['categoria' => $f['categoria'], 'tipo' => $f['tipo'], 'responsavel' => $f['responsavel'], 'busca' => $f['busca']]))
  <div class="os-totais">
    <a href="{{ route('inventario-ti.index', $filtrosSemSituacao) }}" @class(['ativo' => $f['situacao'] === ''])>Ativos <strong>{{ $totais->except('baixado')->sum() }}</strong></a>
    @foreach (ItemTi::SITUACOES as $valor => $rotulo)
      <a href="{{ route('inventario-ti.index', ['situacao' => $valor] + $filtrosSemSituacao) }}" @class(['ativo' => $f['situacao'] === $valor])><span class="etiqueta ti-situacao-{{ $valor }}">{{ $rotulo }}</span> <strong>{{ $totais[$valor] ?? 0 }}</strong></a>
    @endforeach
    <a href="{{ route('inventario-ti.index', ['situacao' => 'vencendo'] + $filtrosSemSituacao) }}" @class(['ativo' => $f['situacao'] === 'vencendo'])>Garantia ou licença vencendo em 30 dias</a>
  </div>

  <form method="get" class="filtros">
    <input type="hidden" name="situacao" value="{{ $f['situacao'] }}">
    <label>Buscar <input name="busca" value="{{ $f['busca'] }}" placeholder="Patrimônio, descrição, série, nome na rede, linha, local ou responsável"></label>
    <label>Categoria
      <select name="categoria"><option value="">Todas</option>
        @foreach (ItemTi::CATEGORIAS as $valor => $rotulo)<option value="{{ $valor }}" @selected($f['categoria'] === $valor)>{{ $rotulo }}</option>@endforeach
      </select></label>
    <label>Tipo
      <select name="tipo"><option value="">Todos</option>
        @foreach ($tipos as $t)<option value="{{ $t->id }}" @selected($f['tipo'] === $t->id)>{{ $t->nome }}{{ $t->ativo ? '' : ' (inativo)' }}</option>@endforeach
      </select></label>
    <label>Responsável
      <select name="responsavel" data-busca="Buscar responsável…"><option value="">Todos</option>
        @foreach ($responsaveis as $r)<option value="{{ $r->id }}" @selected($f['responsavel'] === $r->id)>{{ $r->nome }}</option>@endforeach
      </select></label>
    <button class="botao secundario" type="submit">Filtrar</button>
    @if ($filtrosSemSituacao)<a href="{{ route('inventario-ti.index', array_filter(['situacao' => $f['situacao']])) }}">Limpar</a>@endif
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Patrimônio</th><th>Tipo</th><th>Descrição</th><th>Marca / modelo</th><th>Responsável</th><th>Local</th><th>Situação</th><th>Garantia / vencimento</th><th>Unidade</th></tr></thead>
    <tbody>
    @forelse ($itens as $i)
      @php($vence = $i->ehHardware() ? $i->garantia_ate : $i->vencimento)
      <tr @class(['suave' => $i->situacao === 'baixado'])>
        <td><a href="{{ route('inventario-ti.show', $i) }}"><strong>{{ $i->codigo() }}</strong></a></td>
        <td>{{ $i->tipo?->nome }}</td>
        <td><a href="{{ route('inventario-ti.show', $i) }}">{{ $i->descricao }}</a>@if ($i->numero_serie)<br><small class="suave">Série {{ $i->numero_serie }}</small>@endif</td>
        <td>{{ $i->marcaModelo() ?: '—' }}</td>
        <td>@if ($i->responsavel)<span style="display:inline-flex;gap:.5rem;align-items:center">@include('partials.avatar', ['usuario' => $i->responsavel]) {{ $i->responsavel->nome }}</span>@else<span class="suave">—</span>@endif</td>
        <td>{{ $i->local ?: '—' }}</td>
        <td><span class="etiqueta ti-situacao-{{ $i->situacao }}">{{ $i->rotuloSituacao() }}</span></td>
        <td>@if ($vence)<span @class(['ti-vencido' => $vence->lt(today())])>{{ $vence->format('d/m/Y') }}</span>@else<span class="suave">—</span>@endif</td>
        <td><span class="etiqueta unidade-{{ $i->unidade }}">{{ $i->rotuloUnidade() }}</span></td>
      </tr>
    @empty
      <tr><td colspan="9" class="suave">Nenhum item encontrado.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $itens->links('partials.paginacao') }}
</section>
@endsection
