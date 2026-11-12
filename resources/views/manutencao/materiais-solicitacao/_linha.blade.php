{{-- Uma linha (item) da solicitação de material. Variáveis: $i (índice ou "__I__" no modelo), $item (valores antigos),
     $centros, $ccUsuario, $produtosAntigos. --}}
@php($codigo = trim((string) ($item['produto_codigo'] ?? '')))
@php($produto = $codigo !== '' ? ($produtosAntigos[$codigo] ?? null) : null)
<div class="sm-item" data-sm-item>
  <span class="sm-item-numero" data-sm-numero></span>
  <label class="sm-produto">Produto
    <select name="itens[{{ $i }}][produto_codigo]" data-busca="Buscar produto por código ou descrição…" data-busca-url="{{ route('os.materiais-solicitacao.produtos') }}">
      <option value="">Escolha o produto…</option>
      @if ($codigo !== '')<option value="{{ $codigo }}" selected>{{ $codigo }}{{ $produto ? ' — '.$produto->descricao().' ('.trim((string) $produto->b1_um).')' : '' }}</option>@endif
    </select>
  </label>
  <label class="sm-cc">Centro de custo
    @php($ccItem = (string) ($item['centro_custo'] ?? $ccUsuario))
    <select name="itens[{{ $i }}][centro_custo]" data-busca="Buscar centro de custo…">
      <option value="">Escolha…</option>
      @foreach ($centros as $c)<option value="{{ $c->codigo() }}" @selected($ccItem === $c->codigo())>{{ $c->codigo() }} — {{ $c->descricao() }}</option>@endforeach
    </select>
  </label>
  <label class="sm-qtd">Quantidade
    <input type="number" name="itens[{{ $i }}][quantidade]" min="0" step="any" value="{{ $item['quantidade'] ?? '' }}" placeholder="0">
  </label>
  <label class="sm-prio">Prioridade
    <select name="itens[{{ $i }}][prioridade]" title="Prioridade do item">
      @foreach (\App\Models\Manutencao\SolicitacaoMaterialItem::prioridades() as $v => $r)<option value="{{ $v }}" @selected(($item['prioridade'] ?? \App\Models\Manutencao\SolicitacaoMaterialItem::PRIORIDADE_PADRAO) === $v)>{{ $v }} — {{ $r }}</option>@endforeach
    </select>
  </label>
  <label class="sm-obs">Observação / utilização
    <input name="itens[{{ $i }}][observacao]" maxlength="1000" value="{{ $item['observacao'] ?? '' }}" placeholder="Onde e para que vai ser usado">
  </label>
  <button type="button" class="botao pequeno secundario sm-remover" data-sm-remover title="Remover este item" aria-label="Remover este item">@include('layouts.partials.icone', ['nome' => 'lixeira'])</button>
</div>
