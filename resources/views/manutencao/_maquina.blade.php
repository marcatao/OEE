{{-- Máquina de uma SS/OS: código e nome (ou "—"). Variável: $maquina --}}
@if ($maquina)<code>{{ $maquina->codigo }}</code> {{ $maquina->nome }}@else<span class="suave">—</span>@endif
