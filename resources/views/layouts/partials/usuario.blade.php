@php($eu = auth()->user())
{{-- Tema claro/escuro: sem escolha segue o sistema; o botão alterna e guarda no navegador (public/js/componentes.js). --}}
<button type="button" class="topo-icone tema-botao" data-alternar-tema title="Alternar tema claro/escuro" aria-label="Alternar tema claro/escuro">
  <span class="tema-icone-claro">@include('layouts.partials.icone', ['nome' => 'lua'])</span>
  <span class="tema-icone-escuro">@include('layouts.partials.icone', ['nome' => 'sol'])</span>
</button>
@include('layouts.partials.notificacoes')
<a href="{{ route('perfil') }}" class="topo-usuario" title="Meu perfil">
  @include('partials.avatar', ['usuario' => $eu])
  <span class="topo-usuario-texto">
    <strong>{{ $eu->nome ?? $eu->email }}</strong>
    <small>{{ $eu->cargo ?: $eu->rotuloPerfilCurto() }}</small>
  </span>
</a>
<form method="post" action="{{ route('logout') }}">
  @csrf
  <button class="topo-icone" type="submit" title="Sair" aria-label="Sair">@include('layouts.partials.icone', ['nome' => 'sair'])</button>
</form>
