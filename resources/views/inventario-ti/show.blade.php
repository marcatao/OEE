@extends('layouts.portal')

@section('titulo', 'Item '.$i->codigo())

@section('conteudo')
<section class="cartao">
  <p class="trilha">Tecnologia › <a href="{{ route('inventario-ti.index') }}">Inventário de TI</a></p>
  <div class="titulo-com-acao">
    <h1>{{ $i->identificacao() }}</h1>
    <div class="ti-acoes">
      @if ($podeAbrirSs)
        <a class="botao secundario" href="{{ route('os.solicitacoes.create', ['item_ti' => $i->id]) }}">@include('layouts.partials.icone', ['nome' => 'solicitacao']) Abrir SS para este item</a>
      @endif
      @if ($podeEditar)
        <a class="botao" href="{{ route('inventario-ti.edit', $i) }}">Editar / movimentar</a>
      @endif
    </div>
  </div>
  <p>
    <span class="etiqueta ti-situacao-{{ $i->situacao }}">{{ $i->rotuloSituacao() }}</span>
    <span class="etiqueta">{{ $i->rotuloCategoria() }} · {{ $i->tipo?->nome }}</span>
    <span class="etiqueta unidade-{{ $i->unidade }}">{{ $i->rotuloUnidade() }}</span>
  </p>
  @if ($i->situacao === 'baixado')
    <p class="suave">Baixado em @dataHora($i->baixado_em){{ $i->motivo_baixa ? ' — '.$i->motivo_baixa : '' }}.</p>
  @endif

  <dl class="dados-curtos">
    <div><dt>Responsável</dt><dd>{{ $i->responsavel?->nome ?? '—' }}@if ($i->responsavel_desde)<br><small class="suave">desde @dataHora($i->responsavel_desde)</small>@endif</dd></div>
    <div><dt>Local</dt><dd>{{ $i->local ?: '—' }}</dd></div>
    <div><dt>Centro de custo</dt><dd>{{ $i->centro_custo ?: '—' }}</dd></div>
    <div><dt>Marca / modelo</dt><dd>{{ $i->marcaModelo() ?: '—' }}</dd></div>
    <div><dt>{{ $i->ehLinha() ? 'ICCID' : 'Nº de série' }}</dt><dd>{{ $i->numero_serie ?: '—' }}</dd></div>
    @if ($i->ehHardware())
      <div><dt>Nome na rede</dt><dd>{{ $i->hostname ?: '—' }}</dd></div>
      <div><dt>Acessórios</dt><dd>{{ $i->acessorios ?: '—' }}</dd></div>
    @endif
    @if ($i->ehLinha())
      <div><dt>Linha</dt><dd>{{ $i->linha_numero ?: '—' }}</dd></div>
      <div><dt>Operadora / plano</dt><dd>{{ trim(($i->operadora ?? '').' '.($i->plano ? '· '.$i->plano : ''), ' ·') ?: '—' }}</dd></div>
      <div><dt>Aparelho</dt><dd>@if ($i->vinculado)<a href="{{ route('inventario-ti.show', $i->vinculado->id) }}">{{ $i->vinculado->identificacao() }}</a>@else<span class="suave">—</span>@endif</dd></div>
    @endif
    @if ($i->ehLicenca())
      <div><dt>Licenças</dt><dd>{{ $atribuicoes->whereNull('removida_em')->count() }} em uso{{ $i->quantidade_licencas ? ' de '.$i->quantidade_licencas : '' }}</dd></div>
      @if ($podeEditar)<div><dt>Chave / contrato</dt><dd><code>{{ $i->licenca_chave ?: '—' }}</code></dd></div>@endif
    @endif
    <div><dt>Compra</dt><dd>{{ $i->data_compra?->format('d/m/Y') ?? '—' }}{{ $i->nota_fiscal ? ' · NF '.$i->nota_fiscal : '' }}{{ $i->fornecedor ? ' · '.$i->fornecedor : '' }}</dd></div>
    <div><dt>Valor</dt><dd>{{ $i->valor !== null ? 'R$ '.number_format((float) $i->valor, 2, ',', '.') : '—' }}</dd></div>
    <div><dt>Garantia até</dt><dd>@if ($i->garantia_ate)<span @class(['ti-vencido' => $i->garantiaVencida()])>{{ $i->garantia_ate->format('d/m/Y') }}{{ $i->garantiaVencida() ? ' (vencida)' : '' }}</span>@else — @endif</dd></div>
    @if (! $i->ehHardware())
      <div><dt>Vencimento</dt><dd>@if ($i->vencimento)<span @class(['ti-vencido' => $i->vencimento->lt(today())])>{{ $i->vencimento->format('d/m/Y') }}</span>@else — @endif</dd></div>
    @endif
  </dl>
  @if ($i->atributos->isNotEmpty())
    <h2>Atributos</h2>
    <dl class="dados-curtos">
      @foreach ($i->atributos as $a)<div><dt>{{ $a->nome }}</dt><dd>{{ $a->valor ?? '—' }}</dd></div>@endforeach
    </dl>
  @endif
  @if ($i->especificacao)<h2>Especificação</h2><p class="ti-especificacao">{{ $i->especificacao }}</p>@endif
  @if ($i->observacao)<p class="suave">{{ $i->observacao }}</p>@endif
  <p class="suave"><small>Cadastrado por {{ $i->criadoPor?->nome ?? '—' }} em @dataHora($i->criado_em)@if ($i->atualizadoPor) · alterado por {{ $i->atualizadoPor->nome }} em @dataHora($i->atualizado_em)@endif</small></p>
</section>

<div class="ti-grade">
  @if ($i->temTermo())
    <section class="cartao">
      <div class="titulo-com-acao">
        <h2>Termos de responsabilidade</h2>
        @if ($podeEditar && $i->responsavel_id)
          <form method="post" action="{{ route('inventario-ti.gerar-termo', $i) }}" onsubmit="return confirm('Gerar um termo novo para {{ $i->responsavel?->nome }}? O pendente (se houver) é cancelado.')">
            @csrf
            <button class="botao pequeno secundario" type="submit">Gerar termo novo</button>
          </form>
        @endif
      </div>
      @forelse ($termos as $t)
        <p><a href="{{ route('inventario-ti.termos.show', $t) }}"><strong>{{ $t->numeroFormatado() }}</strong></a> · {{ $t->usuario?->nome }}
          <span class="etiqueta ti-termo-{{ $t->status }}">{{ $t->rotuloStatus() }}</span>
          @if ($t->status !== 'cancelado')<small class="suave">· testemunhas {{ $t->testemunhas->filter->assinou()->count() }}/2</small>@endif<br>
          <small class="suave">Gerado em @dataHora($t->criado_em)@if ($t->assinado_em) · assinado em @dataHora($t->assinado_em){{ $t->presencial ? ' (presencial, com '.$t->registradoPor?->nome.')' : '' }}@endif
            @if ($t->cancelado_em) · cancelado em @dataHora($t->cancelado_em) — {{ $t->motivo_cancelamento }}@endif</small></p>
      @empty
        <p class="suave">Nenhum termo. Ao definir um responsável, o termo é gerado para ele assinar.</p>
      @endforelse
    </section>
  @endif

  @if ($i->ehLicenca())
    <section class="cartao">
      <h2>Onde a licença está em uso</h2>
      <table class="tabela">
        <thead><tr><th>Equipamento / pessoa</th><th>Desde</th><th></th></tr></thead>
        <tbody>
        @forelse ($atribuicoes as $a)
          <tr @class(['suave' => $a->removida_em])>
            <td>@if ($a->item)<a href="{{ route('inventario-ti.show', $a->item_id) }}">{{ $a->item->identificacao() }}</a>@endif
              @if ($a->usuario){{ $a->item ? ' · ' : '' }}{{ $a->usuario->nome }}@endif
              @if ($a->observacao)<br><small class="suave">{{ $a->observacao }}</small>@endif</td>
            <td>@dataHora($a->atribuida_em)@if ($a->removida_em)<br><small>liberada em @dataHora($a->removida_em)</small>@endif</td>
            <td>@if ($podeEditar && ! $a->removida_em)
                <form method="post" action="{{ route('inventario-ti.licencas.remover', [$i, $a->id]) }}">@csrf<button class="botao pequeno secundario" type="submit">Liberar</button></form>
              @endif</td>
          </tr>
        @empty
          <tr><td colspan="3" class="suave">Nenhuma atribuição.</td></tr>
        @endforelse
        </tbody>
      </table>
      @if ($podeEditar && $i->situacao !== 'baixado')
        <form method="post" action="{{ route('inventario-ti.licencas.store', $i) }}" class="formulario-vertical">
          @csrf
          <div class="campos">
            <label class="largo">Equipamento
              <select name="item_id" data-busca="Buscar equipamento…"><option value="">Nenhum</option>
                @foreach ($equipamentos as $e)<option value="{{ $e->id }}">{{ $e->identificacao() }}</option>@endforeach
              </select></label>
            <label class="largo">Pessoa
              <select name="usuario_id" data-busca="Buscar usuário…"><option value="">Ninguém</option>
                @foreach ($usuarios as $usu)<option value="{{ $usu->id }}">{{ $usu->nome }}</option>@endforeach
              </select></label>
            <label class="largo">Observação <input name="observacao" maxlength="255"></label>
          </div>
          <button class="botao secundario" type="submit">Atribuir licença</button>
        </form>
      @endif
    </section>
  @endif

  @if ($i->ehHardware() && ($licencas->isNotEmpty() || $i->chips->isNotEmpty()))
    <section class="cartao">
      <h2>Neste equipamento</h2>
      @foreach ($licencas as $a)
        <p>Licença: <a href="{{ route('inventario-ti.show', $a->licenca_id) }}">{{ $a->licenca?->identificacao() }}</a> <small class="suave">desde @dataHora($a->atribuida_em)</small></p>
      @endforeach
      @foreach ($i->chips as $chip)
        <p>Linha / chip: <a href="{{ route('inventario-ti.show', $chip->id) }}">{{ $chip->identificacao() }}</a></p>
      @endforeach
    </section>
  @endif

  <section class="cartao">
    <h2>Chamados (SS/OS)</h2>
    @forelse ($solicitacoes as $ss)
      <p><a href="{{ route('os.solicitacoes.show', $ss) }}"><strong>{{ $ss->numeroFormatado() }}</strong></a> {{ $ss->titulo }}
        <span class="etiqueta">{{ $ss->rotuloStatus() }}</span>
        @if ($ss->ordemServico) → <a href="{{ route('os.ordens.show', $ss->ordemServico) }}">{{ $ss->ordemServico->numeroFormatado() }}</a> <span class="etiqueta">{{ $ss->ordemServico->rotuloStatus() }}</span>@endif
        <br><small class="suave">@dataHora($ss->criado_em)</small></p>
    @empty
      @if ($ordens->isEmpty())<p class="suave">Nenhum chamado para este item.</p>@endif
    @endforelse
    @foreach ($ordens->whereNull('solicitacao_id') as $os)
      <p><a href="{{ route('os.ordens.show', $os) }}"><strong>{{ $os->numeroFormatado() }}</strong></a> {{ $os->titulo }} <span class="etiqueta">{{ $os->rotuloStatus() }}</span></p>
    @endforeach
  </section>
</div>

<section class="cartao">
  <h2>Histórico de movimentação</h2>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Quando</th><th>O que</th><th>De</th><th>Para</th><th>Observação</th><th>Por</th></tr></thead>
    <tbody>
    @forelse ($movimentacoes as $m)
      <tr>
        <td>@dataHora($m->criado_em)</td>
        <td>{{ $m->rotuloCampo() }}</td>
        <td>{{ $m->valor_anterior ?? '—' }}</td>
        <td>{{ $m->valor_novo ?? '—' }}</td>
        <td>{{ $m->observacao }}</td>
        <td>{{ $m->usuario?->nome ?? '—' }}</td>
      </tr>
    @empty
      <tr><td colspan="6" class="suave">Sem movimentações.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>
@endsection
