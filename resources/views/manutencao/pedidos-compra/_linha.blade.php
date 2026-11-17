{{-- Uma linha (produto) do pedido de compra gerado na OS. Variáveis: $i (índice ou "__I__" no modelo), $linha (valores antigos). --}}
@php($codigoPc = trim((string) ($linha['produto_codigo'] ?? '')))
<div class="pc-linha" data-pc-linha>
  <span class="sm-item-numero" data-pc-numero></span>
  <label>Produto
    <select name="itens[{{ $i }}][produto_codigo]" data-busca="Buscar produto por código ou descrição…" data-busca-url="{{ route('os.pedidos-compra.produtos') }}">
      <option value="">Escolha o produto…</option>
      @if ($codigoPc !== '')<option value="{{ $codigoPc }}" selected>{{ $codigoPc }}</option>@endif
    </select>
  </label>
  <label>Quantidade
    <input type="number" name="itens[{{ $i }}][quantidade]" min="0" step="any" value="{{ $linha['quantidade'] ?? '' }}" placeholder="0">
  </label>
  <label>Observação
    <input name="itens[{{ $i }}][observacao]" maxlength="250" value="{{ $linha['observacao'] ?? '' }}" placeholder="Opcional">
  </label>
  <button type="button" class="botao pequeno secundario" data-pc-remover title="Remover este produto" aria-label="Remover este produto">@include('layouts.partials.icone', ['nome' => 'lixeira'])</button>
</div>
