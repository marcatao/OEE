<a href="{{ route('perfil') }}" class="topo-usuario">{{ auth()->user()->nome ?? auth()->user()->email }}</a>
<form method="post" action="{{ route('logout') }}">
  @csrf
  <button class="botao secundario" type="submit">Sair</button>
</form>
