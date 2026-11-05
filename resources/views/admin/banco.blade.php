@extends('layouts.portal')

@section('titulo', 'Banco de dados')

@section('conteudo')
<section class="cartao">
  <h1>Banco de dados</h1>
  <p>A conexão é configurada no arquivo <code>.env</code> do servidor (seção "Banco de dados"). Depois de alterar,
    rode <code>php artisan config:clear</code> e confira aqui.</p>
  <dl>
    @foreach ($conexao as $rotulo => $valor)
      <dt>{{ $rotulo }}</dt><dd><code>{{ $valor }}</code></dd>
    @endforeach
  </dl>
  <p class="{{ $ok ? 'ok' : 'erro' }}"><strong>{{ $ok ? 'Conexão OK' : 'Falha na conexão' }}:</strong> {{ $mensagem }}</p>

  @if ($tabelas)
    <h2>Tabelas</h2>
    <table class="tabela">
      <thead><tr><th>Tabela</th><th class="num">Registros</th></tr></thead>
      <tbody>
      @foreach ($tabelas as $nome => $qtd)
        <tr><td><code>{{ $nome }}</code></td><td class="num">{{ $qtd === null ? 'não criada' : number_format($qtd, 0, ',', '.') }}</td></tr>
      @endforeach
      </tbody>
    </table>
    @if (in_array(null, $tabelas, true))
      <p class="erro">Há tabelas não criadas: rode <code>php artisan migrate</code> no servidor.</p>
    @endif
  @endif
</section>
@endsection
