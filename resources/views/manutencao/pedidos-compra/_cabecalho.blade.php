{{-- Campos do cabeçalho do pedido ou da solicitação de compra. Variáveis: $centros (CentroCusto), $ccOrigem e $ccDestino
     (padrões), opcional $form (id do formulário, quando os campos ficam fora dele). --}}
@php($origemAtual = (string) old('centro_custo_origem', $ccOrigem))
@php($destinoAtual = (string) old('centro_custo_destino', $ccDestino))
@php($tipoAtual = (string) old('tipo', 'pedido'))
<fieldset class="pc-tipo opcoes-linha">
  <legend>Gerar no Protheus</legend>
  <label class="opcao"><input type="radio" name="tipo" value="pedido" @checked($tipoAtual !== 'solicitacao') @if (! empty($form)) form="{{ $form }}" @endif>
    <span>Pedido de compra <small>vai direto para o fornecedor padrão; o comprador ajusta e aprova</small></span></label>
  <label class="opcao"><input type="radio" name="tipo" value="solicitacao" @checked($tipoAtual === 'solicitacao') @if (! empty($form)) form="{{ $form }}" @endif>
    <span>Solicitação de compra <small>o setor de compras cota e gera o pedido</small></span></label>
</fieldset>
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
  <label class="pc-obs">Observação
    <input name="observacao_pedido" maxlength="250" value="{{ old('observacao_pedido') }}" placeholder="Opcional (vai para o Protheus)" @if (! empty($form)) form="{{ $form }}" @endif>
  </label>
</div>
