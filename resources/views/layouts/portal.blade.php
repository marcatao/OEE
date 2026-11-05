<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('titulo', 'Início') · {{ config('app.name') }}</title>
  <link rel="stylesheet" href="{{ asset('css/portal.css') }}">
</head>
<body>
@php($u = auth()->user())
@if ($u && $u->temAcesso())
  {{-- Menu lateral: no celular abre pelo botão "Menu" (checkbox, sem JavaScript) --}}
  <input type="checkbox" id="menu-aberto" class="menu-alternar" aria-hidden="true">
  <div class="estrutura">
    <aside class="menu-lateral" aria-label="Menu do sistema">
      <a class="marca" href="{{ route('inicio') }}">{{ config('app.name') }}</a>
      <nav>
        <a href="{{ route('inicio') }}" @class(['ativo' => request()->routeIs('inicio')])>Início</a>

        @if ($u->pode('calculadora'))
          <p class="menu-secao">Produção</p>
          <a href="{{ route('calculadora') }}" @class(['ativo' => request()->routeIs('calculadora')])>Calculadora de OEE</a>
        @endif

        @if ($u->ehAdmin())
          <p class="menu-secao">Cadastros</p>
          <details class="menu-grupo" @if (request()->routeIs('cadastros.*')) open @endif>
            <summary>Logins</summary>
            <a href="{{ route('cadastros.usuarios.index') }}" @class(['ativo' => request()->routeIs('cadastros.usuarios.*')])>Usuários</a>
            <a href="{{ route('cadastros.grupos.index') }}" @class(['ativo' => request()->routeIs('cadastros.grupos.*')])>Grupos</a>
            <a href="{{ route('cadastros.acessos') }}" @class(['ativo' => request()->routeIs('cadastros.acessos')])>Histórico de acessos</a>
          </details>

          <p class="menu-secao">Administração</p>
          <a href="{{ route('admin.banco') }}" @class(['ativo' => request()->routeIs('admin.banco')])>Banco de dados</a>
        @endif
      </nav>
    </aside>
    <label for="menu-aberto" class="menu-fundo" aria-hidden="true"></label>

    <div class="area-principal">
      <header class="topo">
        <label for="menu-aberto" class="botao secundario menu-botao">Menu</label>
        <span class="topo-espaco"></span>
        @include('layouts.partials.usuario')
      </header>
      <main>
        @include('layouts.partials.avisos')
        @yield('conteudo')
      </main>
    </div>
  </div>
@else
  {{-- Visitante ou usuário ainda sem acesso: sem menu --}}
  <header class="topo">
    <a class="marca" href="{{ route('inicio') }}">{{ config('app.name') }}</a>
    <span class="topo-espaco"></span>
    @if ($u)
      @include('layouts.partials.usuario')
    @else
      <a class="botao" href="{{ route('login') }}">Entrar com Microsoft</a>
    @endif
  </header>
  <main class="sozinho">
    @include('layouts.partials.avisos')
    @yield('conteudo')
  </main>
@endif
</body>
</html>
