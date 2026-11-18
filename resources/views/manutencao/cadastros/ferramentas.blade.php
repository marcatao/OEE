@extends('layouts.portal')

@section('titulo', 'Equipamentos')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço › Cadastros</p>
  <div class="titulo-com-acao">
    <h1>Equipamentos</h1>
    <a class="botao" href="{{ route('os.cadastros.ferramentas.create') }}">@include('layouts.partials.icone', ['nome' => 'mais']) Novo equipamento</a>
  </div>
  <p class="suave">Equipamentos da manutenção (instrumentos, ferramentas, notebooks…) e com qual mantenedor cada um está. <strong>Avulso</strong> =
    sem mantenedor (no almoxarifado ou na ferramentaria). Amarre pelo cadastro do equipamento ou pelo cadastro do mantenedor.</p>

  <div class="os-totais">
    <a href="{{ route('os.cadastros.ferramentas', array_filter(['unidade' => $unidade])) }}" @class(['ativo' => $situacao === ''])>Todos <strong>{{ $totais['todas'] }}</strong></a>
    <a href="{{ route('os.cadastros.ferramentas', ['situacao' => 'com_mantenedor'] + array_filter(['unidade' => $unidade])) }}" @class(['ativo' => $situacao === 'com_mantenedor'])><span class="etiqueta os-em_execucao">Com mantenedor</span> <strong>{{ $totais['com_mantenedor'] }}</strong></a>
    <a href="{{ route('os.cadastros.ferramentas', ['situacao' => 'avulsas'] + array_filter(['unidade' => $unidade])) }}" @class(['ativo' => $situacao === 'avulsas'])><span class="etiqueta os-aberta">Avulsos</span> <strong>{{ $totais['avulsas'] }}</strong></a>
    <a href="{{ route('os.cadastros.ferramentas', ['situacao' => 'inativas'] + array_filter(['unidade' => $unidade])) }}" @class(['ativo' => $situacao === 'inativas'])>Inativos</a>
  </div>

  <form method="get" class="filtros">
    <input type="hidden" name="situacao" value="{{ $situacao }}">
    @if (count($unidades) > 1)
      <label>Unidade
        <select name="unidade"><option value="">Todas as minhas</option>
          @foreach ($unidades as $un)<option value="{{ $un }}" @selected($unidade === $un)>{{ config('oee.unidades')[$un] ?? $un }}</option>@endforeach
        </select></label>
    @endif
    <label>Buscar <input name="busca" value="{{ $busca }}" placeholder="Código, descrição, marca, nº de série ou atributo (ex.: IP)"></label>
    <label>Mantenedor
      <select name="mantenedor" data-busca="Buscar mantenedor…"><option value="">Todos</option>
        @foreach ($mantenedores as $m)<option value="{{ $m->id }}" @selected($mantenedorFiltro === $m->id)>{{ $m->nomeExibicao() }}</option>@endforeach
      </select></label>
    <button class="botao secundario" type="submit">Filtrar</button>
    @if ($busca !== '' || $mantenedorFiltro || $unidade !== '')<a href="{{ route('os.cadastros.ferramentas', ['situacao' => $situacao]) }}">Limpar</a>@endif
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Código</th><th>Descrição</th><th>Marca / modelo</th><th>Nº de série</th><th>Atributos</th><th>Está com</th><th>Desde</th><th>Unidade</th><th></th></tr></thead>
    <tbody>
    @forelse ($ferramentas as $f)
      <tr @class(['suave' => ! $f->ativo])>
        <td><strong>{{ $f->codigo }}</strong></td>
        <td>{{ $f->descricao }}@if (! $f->ativo) <small>(inativo)</small>@endif</td>
        <td>{{ $f->marca_modelo }}</td>
        <td>{{ $f->numero_serie }}</td>
        <td>@forelse ($f->atributos as $a)<small><strong>{{ $a->nome }}:</strong> {{ $a->valor ?? '—' }}</small>@if (! $loop->last)<br>@endif @empty<span class="suave">—</span>@endforelse</td>
        <td>@if ($f->mantenedor)
              <span style="display:inline-flex;gap:.5rem;align-items:center">@if ($f->mantenedor->usuario)@include('partials.avatar', ['usuario' => $f->mantenedor->usuario])@endif {{ $f->mantenedor->nomeExibicao() }}</span>
            @else<span class="etiqueta os-aberta">Avulso</span>@endif</td>
        <td>{{ $f->atribuida_em ? \App\Providers\AppServiceProvider::dataHora($f->atribuida_em) : '—' }}</td>
        <td><span class="etiqueta unidade-{{ $f->unidade }}">{{ $f->rotuloUnidade() }}</span></td>
        <td><a class="botao pequeno secundario" href="{{ route('os.cadastros.ferramentas.edit', $f) }}">Editar</a></td>
      </tr>
    @empty
      <tr><td colspan="9" class="suave">Nenhum equipamento encontrado.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $ferramentas->links('partials.paginacao') }}
</section>
@endsection
