@extends('layouts.portal')

@section('titulo', 'Calendários')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Administração</p>
  <h1>Calendários</h1>
  <p class="suave">Calendários anuais de dias úteis. Cada unidade vê só os seus e pode ter vários (por turno, por setor…). Um calendário
    novo já vem com os feriados nacionais, do Estado de São Paulo e de São Bernardo do Campo; de segunda a sexta são úteis e o resto você
    ajusta clicando no dia (feriado, ponte, folga, compensação ou dia útil extra). Máquinas e mantenedores escolhem o calendário deles.</p>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Calendário</th><th>Ano</th><th>Unidade</th><th class="num">Máquinas</th><th class="num">Mantenedores</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    @forelse ($calendarios as $c)
      <tr>
        <td><a href="{{ route('admin.calendarios.show', $c) }}"><strong>{{ $c->nome }}</strong></a>@if ($c->descricao)<br><small class="suave">{{ $c->descricao }}</small>@endif</td>
        <td>{{ $c->ano }}</td>
        <td><span class="etiqueta unidade-{{ $c->unidade }}">{{ $c->rotuloUnidade() }}</span></td>
        <td class="num">{{ $c->maquinas_count }}</td>
        <td class="num">{{ $usoMantenedores[$c->id] ?? 0 }}</td>
        <td><span class="etiqueta {{ $c->ativo ? 'situacao-online' : 'situacao-inativo' }}">{{ $c->ativo ? 'Ativo' : 'Inativo' }}</span></td>
        <td><a class="botao pequeno secundario" href="{{ route('admin.calendarios.show', $c) }}">Abrir</a></td>
      </tr>
    @empty
      <tr><td colspan="7" class="suave">Nenhum calendário ainda. Crie o primeiro abaixo.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>

<section class="cartao">
  <h2>Novo calendário</h2>
  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif
  <form method="post" action="{{ route('admin.calendarios.store') }}" class="formulario-vertical" style="margin-top:0">
    @csrf
    <div class="campos">
      <label>Nome <input name="nome" required maxlength="80" value="{{ old('nome') }}" placeholder="Ex.: Padrão Manutenção, Turno noturno"></label>
      <label>Ano <input type="number" name="ano" required min="2020" max="2100" value="{{ old('ano', $novo->ano) }}"></label>
      @include('partials.campo-unidade', ['registro' => $novo])
      <label class="duplo">Descrição <input name="descricao" maxlength="300" value="{{ old('descricao') }}" placeholder="Opcional"></label>
    </div>
    <div class="acoes"><button class="botao" type="submit">Criar com os feriados</button></div>
  </form>
</section>
@endsection
