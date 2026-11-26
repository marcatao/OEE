{{-- Nome do usuário nas telas de controle de acessos: link para o cadastro só para quem cuida da Segurança. --}}
@if ($usuario)
  @if (auth()->user()->podeSeguranca())<a href="{{ route('cadastros.usuarios.edit', $usuario) }}">{{ $usuario->nome }}</a>@else{{ $usuario->nome }}@endif
@else
  -
@endif
