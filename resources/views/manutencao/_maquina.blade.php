{{-- Máquina (ou ferramenta/molde) de uma SS/OS: código e nome (ou "—"). Variáveis: $maquina; opcional $ferramenta. --}}
@if ($maquina ?? null)<code>{{ $maquina->codigo }}</code> {{ $maquina->nome }}@elseif ($ferramenta ?? null)<span class="os-ferramenta">Ferramenta/molde</span> <code>{{ $ferramenta->codigo() }}</code> {{ trim($ferramenta->h4_descri) }}@else<span class="suave">—</span>@endif
