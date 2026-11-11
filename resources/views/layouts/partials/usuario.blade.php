@php($eu = auth()->user())
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
