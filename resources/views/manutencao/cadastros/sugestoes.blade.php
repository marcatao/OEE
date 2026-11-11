@extends('layouts.portal')

@section('titulo', 'Sugestões de descrição')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço › Cadastros</p>
  <h1>Sugestões de descrição</h1>
  <p class="suave">Textos prontos que aparecem como botões ao abrir uma solicitação de serviço (ex.: "Vazamento de óleo", "Motor não liga").</p>
  @include('manutencao._erros')
  <div class="rolagem">
  <table class="tabela tabela-form">
    <thead><tr><th>Unidade</th><th>Texto</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    @foreach ($sugestoes as $s)
      <tr>
        <td>@include('partials.campo-unidade', ['registro' => $s, 'semRotulo' => true, 'form' => 'sug-'.$s->id])</td>
        <td><input form="sug-{{ $s->id }}" name="texto" required maxlength="200" value="{{ $s->texto }}" aria-label="Texto" style="width:100%"></td>
        <td><select form="sug-{{ $s->id }}" name="ativo" aria-label="Situação"><option value="1" @selected($s->ativo)>Ativa</option><option value="0" @selected(! $s->ativo)>Inativa</option></select></td>
        <td><form id="sug-{{ $s->id }}" method="post" action="{{ route('os.cadastros.sugestoes.update', $s) }}">@csrf @method('put')<button class="botao pequeno" type="submit">Salvar</button></form></td>
      </tr>
    @endforeach
      <tr>
        <td>@include('partials.campo-unidade', ['registro' => $nova, 'semRotulo' => true, 'form' => 'sug-nova'])</td>
        <td><input form="sug-nova" name="texto" required maxlength="200" placeholder="Nova sugestão" aria-label="Texto" style="width:100%"></td>
        <td><input form="sug-nova" type="hidden" name="ativo" value="1">Ativa</td>
        <td><form id="sug-nova" method="post" action="{{ route('os.cadastros.sugestoes.store') }}">@csrf<button class="botao pequeno" type="submit">Incluir</button></form></td>
      </tr>
    </tbody>
  </table>
  </div>
</section>
@endsection
