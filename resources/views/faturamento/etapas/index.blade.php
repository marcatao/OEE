@extends('layouts.portal')

@section('titulo', 'Etapas dos pedidos')

@section('conteudo')
@php($operadores = \App\Models\Faturamento\RegraEtapaPedido::OPERADORES)
<section class="cartao">
  <p class="trilha">Faturamento › <a href="{{ route('faturamento.pedidos.index') }}">Pedidos de venda</a></p>
  <h1>Etapas dos pedidos</h1>
  <p class="suave">Como o portal coloca cada pedido de venda numa etapa. As etapas fixas vêm do Protheus padrão; as do <strong>WMS</strong>
    (separação, conferência, logística e aguardando faturamento) são definidas pelas regras abaixo, sobre os campos da liberação (SC9). O pedido fica na etapa do item mais atrasado.</p>
  <ol class="pv-regra-fixa">
    <li><span class="pv-etapa pv-carteira">{{ $etapas['carteira']['rotulo'] }}</span> item com saldo sem liberação na SC9, ou liberado com bloqueio
      de crédito (<code>C9_BLCRED</code>) ou de estoque (<code>C9_BLEST</code>) — fixo.</li>
    <li><span class="pv-etapa pv-expedicao">{{ $etapas['expedicao']['rotulo'] }}</span> liberado (sem bloqueio e sem nota) e nenhuma regra do WMS
      vale — fixo. Dias na etapa: desde a liberação (<code>C9_DATALIB</code>).</li>
    @foreach ($configuraveis as $etapa)
      <li><span class="pv-etapa pv-{{ $etapa }}">{{ $etapas[$etapa]['rotulo'] }}</span>
        @if (($regras[$etapa] ?? collect())->where('ativo', true)->isEmpty()) <em>sem regra ativa: nenhum pedido chega aqui</em>
        @else {{ ($regras[$etapa] ?? collect())->where('ativo', true)->map->descricao()->implode(' ou ') }} @endif</li>
    @endforeach
    <li><span class="pv-etapa pv-faturado">{{ $etapas['faturado']['rotulo'] }}</span> todos os itens entregues (<code>C6_QTDENT</code> =
      <code>C6_QTDVEN</code>, ou o saldo eliminado por resíduo) — fixo. Mês pelo <code>C6_DATFAT</code>.</li>
  </ol>
  <p class="suave"><small>Quando mais de uma regra vale, ganha a etapa mais adiantada (aguardando faturamento › logística › conferência › separação).
    Ao salvar, os pedidos em aberto são recalculados na hora. Um campo novo passa a ser copiado na próxima sincronização
    "Pedidos de venda" (procedure do banco, que lê a SC9010; o portal não lê o Protheus) em Segurança › Banco de dados.</small></p>
</section>

<section class="cartao">
  <h2 style="margin-top:0">Nova regra</h2>
  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif
  <form method="post" action="{{ route('faturamento.etapas.store') }}" class="formulario-vertical">
    @csrf
    <div class="campos">
      <label>Etapa
        <select name="etapa" required>
          @foreach ($configuraveis as $etapa)
            <option value="{{ $etapa }}" @selected(old('etapa') === $etapa)>{{ $etapas[$etapa]['rotulo'] }}</option>
          @endforeach
        </select>
      </label>
      <label>Campo da SC9
        <input name="campo" required maxlength="10" value="{{ old('campo') }}" placeholder="Ex.: C9_STSERV" style="text-transform:uppercase" list="pv-campos">
        <datalist id="pv-campos">@foreach (array_unique([...$opcionais, ...array_keys($valores)]) as $c)<option value="{{ $c }}">@endforeach</datalist>
      </label>
      <label>Condição
        <select name="operador" required>
          @foreach ($operadores as $valor => $rotulo)
            <option value="{{ $valor }}" @selected(old('operador', 'igual') === $valor)>{{ $rotulo }}</option>
          @endforeach
        </select>
      </label>
      <label>Valores
        <input name="valores" maxlength="200" value="{{ old('valores') }}" placeholder="Ex.: 1,2">
        <small>Separados por vírgula. Não usado em "preenchido" e "vazio".</small>
      </label>
      <label>Observação
        <input name="observacao" maxlength="200" value="{{ old('observacao') }}" placeholder="Ex.: status do serviço WMS">
      </label>
    </div>
    <p><button class="botao" type="submit">Criar regra</button></p>
  </form>
</section>

<section class="cartao">
  <h2 style="margin-top:0">Regras</h2>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Etapa</th><th>Condição</th><th>Observação</th><th>Situação</th><th>Alterada</th><th></th></tr></thead>
    <tbody>
    @php($todas = collect($configuraveis)->flatMap(fn ($e) => $regras[$e] ?? collect()))
    @forelse ($todas as $r)
      <tr @unless ($r->ativo) class="suave" @endunless>
        <td><span class="pv-etapa pv-{{ $r->etapa }}">{{ $etapas[$r->etapa]['rotulo'] }}</span></td>
        <td>{{ $r->descricao() }}</td>
        <td class="quebra">{{ $r->observacao }}</td>
        <td>{{ $r->ativo ? 'Ativa' : 'Desativada' }}</td>
        <td>@dataHora($r->atualizado_em ?? $r->criado_em)<br><small class="suave">{{ ($r->atualizadoPor ?? $r->criadoPor)?->nome }}</small></td>
        <td>
          <form method="post" action="{{ route('faturamento.etapas.alternar', $r) }}">
            @csrf @method('patch')
            <button class="botao secundario pequeno" type="submit">{{ $r->ativo ? 'Desativar' : 'Reativar' }}</button>
          </form>
        </td>
      </tr>
    @empty
      <tr><td colspan="6" class="suave">Nenhuma regra ainda: todo pedido liberado fica "Em expedição". Crie as regras com os status do WMS.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>

<section class="cartao">
  <h2 style="margin-top:0">Valores encontrados nas liberações em aberto</h2>
  <p class="suave"><small>Campos do WMS/expedição lidos da SC9 (os existentes entre {{ implode(', ', $opcionais) }} e os usados nas regras), com quantas
    liberações sem nota têm cada valor. Use para montar as regras.</small></p>
  @if ($valores)
    <div class="pv-valores">
      @foreach ($valores as $campo => $lista)
        <div>
          <h3><code>{{ $campo }}</code></h3>
          <ul>
            @foreach ($lista as $valor => $quantas)
              <li><code>{{ $valor }}</code> <span class="suave">{{ $quantas }}</span></li>
            @endforeach
          </ul>
        </div>
      @endforeach
    </div>
  @else
    <p class="suave">Nenhum valor ainda: sincronize "Pedidos de venda" em Segurança › Banco de dados.</p>
  @endif
</section>
@endsection
