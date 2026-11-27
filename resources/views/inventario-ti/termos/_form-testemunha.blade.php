{{-- Assinatura de uma testemunha: SÓ ela, logada com o próprio login, no lugar dela. Variáveis: $t, $te, $rgTestemunha. --}}
<form method="post" action="{{ route('inventario-ti.termos.testemunha.assinar', [$t, $te->ordem]) }}" class="formulario-vertical nao-imprimir termo-assinar">
  @csrf
  <h2>Assinar como testemunha {{ $te->ordem }}</h2>
  <p class="suave">Você está assinando como <strong>{{ auth()->user()->nome }}</strong>, testemunha do contrato de {{ $t->usuario?->nome }}.</p>
  <div class="campos">
    <label>RG <input name="rg" maxlength="30" required value="{{ old('rg', $rgTestemunha) }}" placeholder="00.000.000-0 SSP/SP"></label>
  </div>
  <div class="assinatura-quadro" data-assinatura>
    <canvas aria-label="Quadro de assinatura da testemunha: assine com o dedo ou o mouse"></canvas>
    <input type="hidden" name="assinatura_testemunha" data-assinatura-campo>
    <div class="assinatura-acoes">
      <small class="suave">Assine no quadro acima com o dedo (celular) ou o mouse.</small>
      <button type="button" class="botao pequeno secundario" data-assinatura-limpar>Limpar</button>
    </div>
  </div>
  <div class="acoes"><button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'check']) Assinar como testemunha</button></div>
</form>
