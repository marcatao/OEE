<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('titulo', 'Início') · {{ config('app.name') }}</title>
  <link rel="stylesheet" href="{{ asset('css/portal.css') }}">
</head>
<body>
  <header class="topo">
    <a class="marca" href="{{ route('inicio') }}">{{ config('app.name') }}</a>
    <nav>
      @auth
        <a href="{{ route('calculadora') }}">Calculadora</a>
        @if (auth()->user()->temPerfil('admin'))
          <a href="{{ route('admin.usuarios') }}">Usuários</a>
          <a href="{{ route('admin.banco') }}">Banco</a>
        @endif
        <a href="{{ route('perfil') }}">{{ auth()->user()->nome ?? auth()->user()->email }}</a>
        <form method="post" action="{{ route('logout') }}">
          @csrf
          <button class="botao secundario" type="submit">Sair</button>
        </form>
      @else
        <a class="botao" href="{{ route('login') }}">Entrar com Microsoft</a>
      @endauth
    </nav>
  </header>
  <main>
    @if (session('ok'))<p class="aviso ok">{{ session('ok') }}</p>@endif
    @if (session('erro'))<p class="aviso erro">{{ session('erro') }}</p>@endif
    @yield('conteudo')
  </main>
</body>
</html>
