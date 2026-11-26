@extends('layouts.portal')

@section('titulo', 'Sessões ativas')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Segurança</p>
  <h1>Sessões ativas</h1>
  <p>Quem está conectado agora. "Desconectar" obriga a pessoa a entrar de novo (para bloquear de vez, desative o usuário em Segurança › Usuários).</p>

  <h2>No portal <small class="suave">(uso nos últimos {{ $minutos }} minutos)</small></h2>
  @if (! $portalDisponivel)
    <p class="aviso">As sessões do portal só aparecem com <code>SESSION_DRIVER=database</code> no <code>.env</code>.</p>
  @elseif ($portal->isEmpty())
    <p class="suave">Ninguém conectado no portal.</p>
  @else
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Usuário</th><th>Navegador</th><th>IP</th><th>Última atividade</th><th></th></tr></thead>
    <tbody>
    @foreach ($portal as $s)
      <tr>
        <td>@include('partials.usuario-seguranca', ['usuario' => $s['usuario']]) @if ($s['usuario']?->email)<small class="suave">{{ $s['usuario']->email }}</small>@endif</td>
        <td>{{ $s['navegador'] }}</td>
        <td>{{ $s['ip'] }}</td>
        <td>@dataHora($s['ultima_atividade'])</td>
        <td>
          @if ($s['atual'])
            <span class="etiqueta">Esta sessão</span>
          @else
            <form method="post" action="{{ route('cadastros.sessoes.encerrar', $s['chave']) }}" onsubmit="return confirm('Desconectar esta sessão do portal?')">@csrf
              <button class="botao pequeno secundario" type="submit">Desconectar</button></form>
          @endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
  <p class="suave">{{ $portal->count() }} sessão(ões); {{ $portal->pluck('usuario.id')->filter()->unique()->count() }} usuário(s).</p>
  @endif

  <h2>No aplicativo <small class="suave">(celulares com login válido)</small></h2>
  @if ($celulares->isEmpty())
    <p class="suave">Nenhum celular conectado.</p>
  @else
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Usuário</th><th>Celular</th><th>Versão</th><th>Conectado em</th><th>Último uso</th><th></th></tr></thead>
    <tbody>
    @foreach ($celulares as $c)
      <tr>
        <td>@include('partials.usuario-seguranca', ['usuario' => $c->usuario])</td>
        <td>{{ $c->dispositivo ?? '-' }}</td>
        <td>{{ $c->versao_app ?? '-' }}</td>
        <td>@dataHora($c->criado_em)</td>
        <td>@dataHora($c->ultimo_uso_em)</td>
        <td><form method="post" action="{{ route('cadastros.sessoes.revogar-celular', $c->id) }}" onsubmit="return confirm('Desconectar este celular?')">@csrf
          <button class="botao pequeno secundario" type="submit">Desconectar</button></form></td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
  @endif
</section>
@endsection
