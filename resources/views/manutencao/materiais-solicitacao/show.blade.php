@extends('layouts.portal')

@section('titulo', $sm->numeroFormatado())

@use('App\Models\Manutencao\SolicitacaoMaterialItem')

@section('conteudo')
@php($pendentes = $sm->itens->where('status', 'pendente'))
<section class="cartao">
  <p class="trilha">Ordens de serviço › <a href="{{ route('os.materiais-solicitacao.index') }}">Solicitações de material</a></p>
  <div class="titulo-com-acao">
    <div>
      <h1>{{ $sm->numeroFormatado() }}</h1>
      <div class="os-chips">
        <span class="etiqueta sm-{{ $sm->status }}">{{ $sm->rotuloStatus() }}</span>
        <span class="etiqueta unidade-{{ $sm->unidade }}">{{ config('oee.unidades')[$sm->unidade] ?? $sm->unidade }}</span>
      </div>
    </div>
    @if ($podeCancelar)
      <form method="post" action="{{ route('os.materiais-solicitacao.cancelar', $sm) }}" onsubmit="return confirm('Cancelar esta solicitação?')">@csrf
        <button class="botao secundario" type="submit">Cancelar solicitação</button></form>
    @endif
  </div>
  @if ($errors->any())
    <div class="aviso erro" role="alert">@foreach (array_unique($errors->all()) as $erro)<div>{{ $erro }}</div>@endforeach</div>
  @endif

  <dl class="dados-curtos">
    <div><dt>Solicitante</dt><dd><span class="os-pessoa">@if ($sm->solicitante)@include('partials.avatar', ['usuario' => $sm->solicitante])@endif {{ $sm->solicitante?->nome }}</span></dd></div>
    <div><dt>Pedida em</dt><dd>@dataHora($sm->criado_em)</dd></div>
    <div><dt>Itens</dt><dd>{{ $sm->itens->count() }} ({{ $pendentes->count() }} a analisar)</dd></div>
    @if ($sm->observacao)<div><dt>Observação</dt><dd class="os-texto">{{ $sm->observacao }}</dd></div>@endif
  </dl>

  <form method="post" action="{{ route('os.materiais-solicitacao.analisar', $sm) }}" id="sm-analise">
    @csrf
    <div class="rolagem">
    <table class="tabela sm-tabela">
      <thead><tr><th>#</th><th>Produto</th><th>Centro de custo</th><th class="num">Solicitada</th><th>Prioridade</th><th>Observação / utilização</th>
        <th>Análise</th></tr></thead>
      <tbody>
      @foreach ($sm->itens->sortBy('id')->values() as $n => $item)
        <tr @class(['sm-reprovado' => $item->status === 'reprovado'])>
          <td class="suave">{{ $n + 1 }}</td>
          <td><code>{{ $item->produto_codigo }}</code><br>{{ $item->produto_descricao }}</td>
          <td>{{ $item->centro_custo }}@if (isset($centros[$item->centro_custo]))<br><small class="suave">{{ $centros[$item->centro_custo]->descricao() }}</small>@endif</td>
          <td class="num"><strong>{{ SolicitacaoMaterialItem::quantidade($item->quantidade_solicitada) }}</strong> {{ $item->um }}</td>
          <td><span class="sm-prio-{{ $item->prioridade }}">{{ SolicitacaoMaterialItem::PRIORIDADES[$item->prioridade] ?? $item->prioridade }}</span></td>
          <td class="os-texto">{{ $item->observacao }}</td>
          <td>
            @if ($item->status === 'pendente' && $podeAnalisar)
              <div class="sm-analisar">
                <label>Qtd. aprovada <input type="number" name="quantidade[{{ $item->id }}]" min="0" step="any" value="{{ old('quantidade.'.$item->id, (float) $item->quantidade_solicitada) }}"> {{ $item->um }}</label>
                <div class="sm-botoes">
                  <button class="botao pequeno" type="submit" name="acao" value="aprovar" onclick="this.form.item.value='{{ $item->id }}'">@include('layouts.partials.icone', ['nome' => 'check']) Aprovar</button>
                  <button class="botao pequeno secundario" type="submit" name="acao" value="reprovar" onclick="this.form.item.value='{{ $item->id }}'">Reprovar</button>
                </div>
                <input name="motivo[{{ $item->id }}]" maxlength="500" placeholder="Motivo (obrigatório para reprovar)" value="{{ old('motivo.'.$item->id) }}" aria-label="Motivo da reprovação">
              </div>
            @elseif ($item->status === 'pendente')
              <span class="etiqueta sm-pendente">Aguardando análise</span>
            @else
              <span class="etiqueta sm-item-{{ $item->status }}">{{ $item->rotuloStatus() }}@if ($item->status === 'aprovado'): {{ SolicitacaoMaterialItem::quantidade($item->quantidade_aprovada) }} {{ $item->um }}@endif</span>
              @if ($item->motivo_reprovacao)<br><small>{{ $item->motivo_reprovacao }}</small>@endif
              <br><small class="suave">{{ $item->aprovadoPor?->nome }} · @dataHora($item->aprovado_em)</small>
              @if ($item->status === 'aprovado' && $podeAnalisar)
                <div class="sm-botoes">
                  <button class="botao pequeno secundario" type="button" disabled title="Em breve">Gerar requisição</button>
                  <button class="botao pequeno secundario" type="button" disabled title="Em breve">Gerar pedido de compra</button>
                </div>
              @endif
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
    <input type="hidden" name="item" value="">
    @if ($podeAnalisar && $pendentes->count() > 1)
      <div class="acoes"><button class="botao" type="submit" name="acao" value="aprovar_todos" onclick="this.form.item.value=''">@include('layouts.partials.icone', ['nome' => 'check']) Aprovar todos os pendentes com as quantidades acima</button></div>
    @endif
  </form>
  @if ($podeAnalisar && $sm->itens->where('status', 'aprovado')->isNotEmpty())
    <p class="suave sm-em-breve">Gerar a requisição ao almoxarifado ou o pedido de compra a partir dos itens aprovados chega na próxima etapa.</p>
  @endif
</section>
@endsection
