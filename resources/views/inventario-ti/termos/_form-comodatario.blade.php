{{-- Assinatura do comodatário: SÓ ele, logado com o próprio login. Variáveis: $t, $comodatario. --}}
<form method="post" action="{{ route('inventario-ti.termos.assinar', $t) }}" class="formulario-vertical nao-imprimir termo-assinar">
  @csrf
  <h2>Assinar o contrato como comodatário</h2>
  <p class="suave">Você está assinando como <strong>{{ auth()->user()->nome }}</strong>. Seus dados completam o contrato (como no contrato em papel) e ficam guardados só neste termo.</p>
  <div class="campos">
    <label>Cargo <input name="cargo" maxlength="100" required value="{{ old('cargo', $comodatario['cargo'] ?? '') }}"></label>
    <label>Nacionalidade <input name="nacionalidade" maxlength="40" required value="{{ old('nacionalidade', $comodatario['nacionalidade'] ?? 'Brasileiro(a)') }}"></label>
    <label>RG <input name="rg" maxlength="30" required value="{{ old('rg', $comodatario['rg'] ?? '') }}" placeholder="00.000.000-0"></label>
    <label>CPF <input name="cpf" maxlength="14" required inputmode="numeric" value="{{ old('cpf', $comodatario['cpf'] ?? '') }}" placeholder="000.000.000-00"></label>
    <label class="largo">Endereço <input name="endereco" maxlength="255" required value="{{ old('endereco', $comodatario['endereco'] ?? '') }}" placeholder="Rua, número, bairro – cidade/UF – CEP"></label>
  </div>
  <div class="assinatura-quadro" data-assinatura>
    <canvas aria-label="Quadro de assinatura: assine com o dedo ou o mouse"></canvas>
    <input type="hidden" name="assinatura" data-assinatura-campo>
    <div class="assinatura-acoes">
      <small class="suave">Assine no quadro acima com o dedo (celular) ou o mouse.</small>
      <button type="button" class="botao pequeno secundario" data-assinatura-limpar>Limpar</button>
    </div>
  </div>
  <label class="opcao"><input type="checkbox" name="aceite" value="1" required> <span>Li e concordo com o contrato de comodato.</span></label>
  <div class="acoes"><button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'check']) Assinar contrato</button></div>
</form>
