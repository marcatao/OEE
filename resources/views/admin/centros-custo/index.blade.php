@extends('layouts.portal')

@section('titulo', 'Centros de custo')

@use('App\Models\CentroCusto')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Administração</p>
  <div class="titulo-com-acao">
    <h1>Centros de custo</h1>
    <a class="botao" href="{{ route('admin.centros-custo.create') }}">@include('layouts.partials.icone', ['nome' => 'mais']) Novo centro de custo</a>
  </div>
  <p class="suave">Setores das máquinas, no mesmo formato da tabela CTT do Protheus (de onde os dados podem ser importados).
    A máquina guarda só o código. Para tirar de uso, bloqueie.</p>

  <form method="get" class="filtros">
    <label>Buscar <input name="busca" value="{{ $busca }}" placeholder="Código ou descrição"></label>
    <button class="botao secundario" type="submit">Buscar</button>
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Filial</th><th>Unidade</th><th>Código</th><th>Descrição</th><th>Classe</th><th>C. custo superior</th><th>Existência</th><th class="num">Máquinas</th><th>Origem</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    @forelse ($centros as $c)
      <tr>
        <td><code>{{ rtrim($c->ctt_filial) ?: '-' }}</code></td>
        <td><span class="etiqueta unidade-{{ $c->unidade }}">{{ $c->rotuloUnidade() }}</span></td>
        <td><strong>{{ $c->codigo() }}</strong></td>
        <td>{{ $c->descricao() }}</td>
        <td>{{ CentroCusto::CLASSES[$c->ctt_classe] ?? $c->ctt_classe }}</td>
        <td>{{ rtrim($c->ctt_ccsup) ?: '-' }}</td>
        <td>{{ CentroCusto::dataProtheus($c->ctt_dtexis) }} a {{ CentroCusto::dataProtheus($c->ctt_dtexsf) }}</td>
        <td class="num">{{ $usoPorCodigo[$c->codigo()] ?? 0 }}</td>
        <td>{{ $c->veioDoProtheus() ? 'Protheus' : 'Portal' }}</td>
        <td><span class="etiqueta {{ $c->bloqueado() ? 'situacao-inativo' : 'situacao-online' }}">{{ $c->bloqueado() ? 'Bloqueado' : 'Liberado' }}</span></td>
        <td><a class="botao pequeno secundario" href="{{ route('admin.centros-custo.edit', $c) }}">Editar</a></td>
      </tr>
    @empty
      <tr><td colspan="11" class="suave">Nenhum centro de custo cadastrado.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $centros->links('partials.paginacao') }}
</section>
@endsection
