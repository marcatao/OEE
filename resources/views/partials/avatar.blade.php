{{-- Foto do usuário ou, sem foto, as iniciais. Parâmetros: usuario e classe (opcional, ex.: "grande"). --}}
<span class="avatar {{ $classe ?? '' }}" aria-hidden="true">
  @if ($usuario->temFoto())
    <img src="{{ $usuario->urlFoto() }}" alt="" loading="lazy">
  @else
    {{ $usuario->iniciais() }}
  @endif
</span>
