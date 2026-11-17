{{-- Campos do cabeçalho do pedido de compra. Variáveis: $centros (CentroCusto), $ccOrigem e $ccDestino (padrões),
     opcional $form (id do formulário, quando os campos ficam fora dele). --}}
@php($origemAtual = (string) old('centro_custo_origem', $ccOrigem))
@php($destinoAtual = (string) old('centro_custo_destino', $ccDestino))
<div class="pc-campos">
  <label>Centro de custo de origem <small class="suave">(quem pede)</small>
    <select name="centro_custo_origem" data-busca="Buscar centro de custo…" required @if (! empty($form)) form="{{ $form }}" @endif>
      <option value="">Escolha…</option>
      @foreach ($centros as $c)<option value="{{ $c->codigo() }}" @selected($origemAtual === $c->codigo())>{{ $c->codigo() }} — {{ $c->descricao() }}</option>@endforeach
    </select>
  </label>
  <label>Centro de custo de destino <small class="suave">(recebe o custo)</small>
    <select name="centro_custo_destino" data-busca="Buscar centro de custo…" required @if (! empty($form)) form="{{ $form }}" @endif>
      <option value="">Escolha…</option>
      @foreach ($centros as $c)<option value="{{ $c->codigo() }}" @selected($destinoAtual === $c->codigo())>{{ $c->codigo() }} — {{ $c->descricao() }}</option>@endforeach
    </select>
  </label>
  <label class="pc-obs">Observação do pedido
    <input name="observacao_pedido" maxlength="500" value="{{ old('observacao_pedido') }}" placeholder="Opcional (vai para o Protheus)" @if (! empty($form)) form="{{ $form }}" @endif>
  </label>
</div>
