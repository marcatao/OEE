{{-- Logotipo da Alumbra + nome do sistema, no topo de todas as páginas --}}
<a class="topo-logo" href="{{ route('inicio') }}" title="{{ config('oee.nome') }}">
  <img src="{{ asset('img/alumbra-logo.svg') }}" alt="Alumbra" width="126" height="22">
</a>
<span class="topo-divisor" aria-hidden="true"></span>
<span class="topo-sistema">
  <strong>{{ config('oee.sigla') }}</strong>
  <small>{{ config('oee.descricao') }}</small>
</span>
