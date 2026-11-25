{{-- Assinatura de uma testemunha: ela mesma ($ehEla) ou colhida pelo administrador na hora. Variáveis: $t, $te, $ehEla, $rgTestemunha. --}}
<form method="post" action="{{ route('inventario-ti.termos.testemunha.assinar', [$t, $te->ordem]) }}" class="formulario-vertical nao-imprimir termo-assinar">
  @csrf
  @if ($ehEla)<h2>Assinar como testemunha {{ $te->ordem }}</h2>@endif
  @unless ($ehEla)
    <p class="aviso">Assinatura presencial de <strong>{{ $te->usuario?->nome }}</strong> (testemunha {{ $te->ordem }}): entregue o aparelho para ela assinar. Fica registrado que foi no seu aparelho.</p>
  @endunless
  <div class="campos">
    <label class="largo">Nome <input name="nome" maxlength="120" value="{{ old('nome', $te->usuario?->nome) }}"></label>
    <label>RG <input name="rg" maxlength="30" required value="{{ old('rg', $ehEla ? $rgTestemunha : '') }}" placeholder="00.000.000-0 SSP/SP"></label>
  </div>
  <div class="assinatura-quadro" data-assinatura>
    <canvas aria-label="Quadro de assinatura da testemunha: assine com o dedo ou o mouse"></canvas>
    <input type="hidden" name="assinatura_testemunha" data-assinatura-campo>
    <div class="assinatura-acoes">
      <small class="suave">Assine no quadro acima com o dedo (celular) ou o mouse.</small>
      <button type="button" class="botao pequeno secundario" data-assinatura-limpar>Limpar</button>
    </div>
  </div>
  @unless ($ehEla)
    <label class="opcao"><input type="checkbox" name="confirmo_presencial" value="1" required> <span>Confirmo que quem está assinando agora é <strong>{{ $te->usuario?->nome }}</strong>, e não eu.</span></label>
  @endunless
  <div class="acoes"><button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'check']) {{ $ehEla ? 'Assinar como testemunha' : 'Registrar a assinatura de '.$te->usuario?->primeiroNome() }}</button></div>
</form>
