@extends('layouts.portal')

@section('titulo', 'Áreas executantes')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço › Cadastros</p>
  <h1>Áreas executantes</h1>
  <p class="suave">Quem executa o serviço (ex.: Elétrica, Mecânica, Ferramentaria, TI). A unidade da área decide se a SS vai para a
    Manutenção, a Tecnologia ou a Ferramentaria.</p>
  @include('manutencao._erros')
  <div class="rolagem">
  <table class="tabela tabela-form">
    <thead><tr><th>Unidade</th><th>Código</th><th>Nome</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    @foreach ($areas as $a)
      <tr>
        <td>@include('partials.campo-unidade', ['registro' => $a, 'semRotulo' => true, 'form' => 'area-'.$a->id])</td>
        <td><input form="area-{{ $a->id }}" name="codigo" required maxlength="20" value="{{ $a->codigo }}" aria-label="Código" size="8"></td>
        <td><input form="area-{{ $a->id }}" name="nome" required maxlength="80" value="{{ $a->nome }}" aria-label="Nome"></td>
        <td><select form="area-{{ $a->id }}" name="ativo" aria-label="Situação"><option value="1" @selected($a->ativo)>Ativa</option><option value="0" @selected(! $a->ativo)>Inativa</option></select></td>
        <td><form id="area-{{ $a->id }}" method="post" action="{{ route('os.cadastros.areas.update', $a) }}">@csrf @method('put')<button class="botao pequeno" type="submit">Salvar</button></form></td>
      </tr>
    @endforeach
      <tr>
        <td>@include('partials.campo-unidade', ['registro' => $nova, 'semRotulo' => true, 'form' => 'area-nova'])</td>
        <td><input form="area-nova" name="codigo" required maxlength="20" placeholder="Código" aria-label="Código" size="8"></td>
        <td><input form="area-nova" name="nome" required maxlength="80" placeholder="Nova área" aria-label="Nome"></td>
        <td><input form="area-nova" type="hidden" name="ativo" value="1">Ativa</td>
        <td><form id="area-nova" method="post" action="{{ route('os.cadastros.areas.store') }}">@csrf<button class="botao pequeno" type="submit">Incluir</button></form></td>
      </tr>
    </tbody>
  </table>
  </div>
</section>
@endsection
