<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('titulo', 'Início') · {{ config('oee.nome') }}</title>
  <link rel="icon" type="image/svg+xml" href="{{ asset('img/alumbra-icone.svg') }}">
  <link rel="stylesheet" href="{{ asset('css/portal.css') }}?v={{ @filemtime(public_path('css/portal.css')) }}">
  {{-- Aplica o menu recolhido antes de desenhar a página (sem "piscar") --}}
  <script>try { if (localStorage.getItem('oee-menu') === 'recolhido') document.documentElement.classList.add('menu-recolhido'); } catch (e) {}</script>
</head>
<body>
@php($u = auth()->user())
@if ($u && $u->temAcesso())
  {{-- No celular o menu abre pelo botão do topo (checkbox, sem JavaScript). No computador ele recolhe para só ícones. --}}
  <input type="checkbox" id="menu-aberto" class="menu-alternar" aria-hidden="true">
  <div class="estrutura">
    <aside class="menu-lateral" aria-label="Menu do sistema">
      <a class="menu-marca" href="{{ route('inicio') }}" title="{{ config('oee.nome') }}">
        <img src="{{ asset('img/alumbra-icone.svg') }}" alt="">
        <span><strong>{{ config('oee.sigla') }}</strong><small>Alumbra</small></span>
      </a>
      <nav>
        @include('layouts.partials.menu-link', ['rota' => 'inicio', 'rotulo' => 'Início', 'icone' => 'inicio'])

        @if ($u->pode('dashboard_maquina') || $u->pode('timeline'))
          <p class="menu-secao">Dashboards</p>
          @if ($u->pode('dashboard_maquina'))
            @include('layouts.partials.menu-link', ['rota' => 'dashboard.maquinas', 'rotulo' => 'Dashboard por máquina', 'icone' => 'dashboard', 'ativo' => 'dashboard.*'])
          @endif
          @if ($u->pode('timeline'))
            @include('layouts.partials.menu-link', ['rota' => 'timeline', 'rotulo' => 'Timeline', 'icone' => 'timeline'])
          @endif
        @endif

        @if ($u->pode('apontamentos') || $u->pode('calculadora'))
          <p class="menu-secao">Produção</p>
          @if ($u->pode('apontamentos'))
            @include('layouts.partials.menu-link', ['rota' => 'apontamentos.index', 'rotulo' => 'Apontamentos', 'icone' => 'apontamento', 'ativo' => 'apontamentos.*'])
          @endif
          @if ($u->pode('calculadora'))
            @include('layouts.partials.menu-link', ['rota' => 'calculadora', 'rotulo' => 'Calculadora de OEE', 'icone' => 'calculadora'])
          @endif
        @endif

        @if ($u->ehAdmin())
          <p class="menu-secao">Administração</p>
          @include('layouts.partials.menu-link', ['rota' => 'admin.maquinas.index', 'rotulo' => 'Máquinas', 'icone' => 'maquina', 'ativo' => 'admin.maquinas.*'])
          @include('layouts.partials.menu-link', ['rota' => 'admin.estrutura', 'rotulo' => 'Plantas e linhas', 'icone' => 'estrutura', 'ativo' => 'admin.estrutura*'])
          @include('layouts.partials.menu-link', ['rota' => 'admin.legendas.index', 'rotulo' => 'Legendas', 'icone' => 'legenda', 'ativo' => 'admin.legendas.*'])
          @include('layouts.partials.menu-link', ['rota' => 'admin.iot.index', 'rotulo' => 'Dispositivos IoT', 'icone' => 'iot', 'ativo' => 'admin.iot.*'])
          <details class="menu-grupo" @if (request()->routeIs('cadastros.*')) open @endif>
            <summary title="Logins">@include('layouts.partials.icone', ['nome' => 'logins'])<span class="menu-rotulo">Logins</span>@include('layouts.partials.icone', ['nome' => 'seta', 'classe' => 'seta'])</summary>
            <div class="submenu">
              @include('layouts.partials.menu-link', ['rota' => 'cadastros.usuarios.index', 'rotulo' => 'Usuários', 'icone' => 'usuarios', 'ativo' => 'cadastros.usuarios.*'])
              @include('layouts.partials.menu-link', ['rota' => 'cadastros.grupos.index', 'rotulo' => 'Grupos', 'icone' => 'grupos', 'ativo' => 'cadastros.grupos.*'])
              @include('layouts.partials.menu-link', ['rota' => 'cadastros.acessos', 'rotulo' => 'Histórico de acessos', 'icone' => 'historico'])
            </div>
          </details>
          @include('layouts.partials.menu-link', ['rota' => 'admin.banco', 'rotulo' => 'Banco de dados', 'icone' => 'banco'])
        @endif
      </nav>
      <button type="button" class="menu-recolher" data-alternar-menu aria-expanded="true" title="Recolher ou expandir o menu">
        @include('layouts.partials.icone', ['nome' => 'recolher'])<span class="menu-rotulo">Recolher menu</span>
      </button>
    </aside>
    <label for="menu-aberto" class="menu-fundo" aria-hidden="true"></label>

    <div class="area-principal">
      <header class="topo">
        <button type="button" class="topo-icone so-desktop" data-alternar-menu aria-expanded="true" title="Recolher ou expandir o menu" aria-label="Recolher ou expandir o menu">@include('layouts.partials.icone', ['nome' => 'menu'])</button>
        <label for="menu-aberto" class="topo-icone so-celular" title="Abrir o menu" aria-label="Abrir o menu">@include('layouts.partials.icone', ['nome' => 'menu'])</label>
        @include('layouts.partials.marca')
        <span class="topo-espaco"></span>
        @include('layouts.partials.usuario')
      </header>
      <main>
        @include('layouts.partials.avisos')
        @yield('conteudo')
      </main>
    </div>
  </div>
  <script>
    (function () {
      var botoes = document.querySelectorAll('[data-alternar-menu]');
      function marcar(recolhido) { botoes.forEach(function (b) { b.setAttribute('aria-expanded', String(!recolhido)); }); }
      marcar(document.documentElement.classList.contains('menu-recolhido'));
      botoes.forEach(function (b) {
        b.addEventListener('click', function () {
          var recolhido = document.documentElement.classList.toggle('menu-recolhido');
          marcar(recolhido);
          try { localStorage.setItem('oee-menu', recolhido ? 'recolhido' : 'aberto'); } catch (e) {}
        });
      });
    })();
  </script>
@else
  {{-- Visitante ou usuário ainda sem acesso: sem menu --}}
  <header class="topo">
    @include('layouts.partials.marca')
    <span class="topo-espaco"></span>
    @if ($u)
      @include('layouts.partials.usuario')
    @elseif (! request()->routeIs('inicio'))
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
