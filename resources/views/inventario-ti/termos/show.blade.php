@extends('layouts.portal')

@section('titulo', $t->numeroFormatado())

@use('App\Services\InventarioTi\TermosResponsabilidade')

@section('conteudo')
@php($dados = $t->conteudo ?? [])
@php($presencial = (int) auth()->id() !== (int) $t->usuario_id)
<section class="cartao termo">
  <div class="titulo-com-acao nao-imprimir">
    <p class="trilha">Tecnologia › @if (auth()->user()->pode('inventario_ti') && $t->item)<a href="{{ route('inventario-ti.show', $t->item_id) }}">{{ $t->item->codigo() }}</a>@else<a href="{{ route('inventario-ti.meus') }}">Meus equipamentos</a>@endif</p>
    <button class="botao secundario" type="button" onclick="window.print()">@include('layouts.partials.icone', ['nome' => 'imprimir']) Imprimir</button>
  </div>
  @include('manutencao._erros')

  <h1>{{ config('oee.inventario_ti.termo_titulo') }}</h1>
  <p class="suave" style="text-align:center">{{ config('oee.nome') }} · {{ $t->numeroFormatado() }} · <span class="etiqueta ti-termo-{{ $t->status }}">{{ $t->rotuloStatus() }}</span></p>

  @php($identificacao = collect([! empty($dados['responsavel_matricula']) ? 'matrícula '.$dados['responsavel_matricula'] : null, $dados['responsavel_cargo'] ?? null])->filter()->implode(', '))
  <p>Eu, <strong>{{ $dados['responsavel'] ?? $t->usuario?->nome }}</strong>{{ $identificacao !== '' ? ', '.$identificacao : '' }},
    declaro ter recebido o item abaixo:</p>
  <dl class="termo-dados">
    @foreach (TermosResponsabilidade::ROTULOS as $campo => $rotulo)
      @if (! empty($dados[$campo]))<div><dt>{{ $rotulo }}</dt><dd>{{ $dados[$campo] }}</dd></div>@endif
    @endforeach
  </dl>
  <div class="termo-texto">
    @foreach (config('oee.inventario_ti.termo', []) as $paragrafo)<p>{{ $paragrafo }}</p>@endforeach
  </div>

  @if ($t->status === 'assinado')
    <figure class="termo-assinado">
      <img src="data:image/png;base64,{{ $t->imagem_png }}" alt="Assinatura de {{ $t->nome_assinante }}">
      <figcaption><strong>{{ $t->nome_assinante }}</strong>
        <small>Assinado eletronicamente em @dataHora($t->assinado_em){{ $t->presencial ? ' na presença de '.$t->registradoPor?->nome : ' pelo portal' }}{{ $t->assinado_ip ? ' · IP '.$t->assinado_ip : '' }}</small></figcaption>
    </figure>
  @elseif ($t->status === 'cancelado')
    <p class="erro">Termo cancelado em @dataHora($t->cancelado_em){{ $t->motivo_cancelamento ? ' — '.$t->motivo_cancelamento : '' }}.</p>
  @elseif ($podeAssinar)
    <form method="post" action="{{ route('inventario-ti.termos.assinar', $t) }}" class="formulario-vertical nao-imprimir">
      @csrf
      @if ($presencial)
        <p class="aviso">Assinatura presencial: entregue o aparelho para <strong>{{ $t->usuario?->nome }}</strong> ler e assinar. Fica registrado que foi no seu aparelho.</p>
        <label>Nome de quem assina <input name="nome" maxlength="120" value="{{ old('nome', $t->usuario?->nome) }}"></label>
      @endif
      <div class="assinatura-quadro" data-assinatura>
        <canvas aria-label="Quadro de assinatura: assine com o dedo ou o mouse"></canvas>
        <input type="hidden" name="assinatura" data-assinatura-campo>
        <div class="assinatura-acoes">
          <small class="suave">Assine no quadro acima com o dedo (celular) ou o mouse.</small>
          <button type="button" class="botao pequeno secundario" data-assinatura-limpar>Limpar</button>
        </div>
      </div>
      <label class="opcao"><input type="checkbox" name="aceite" value="1" required> <span>Li e concordo com o termo de responsabilidade.</span></label>
      <div class="acoes"><button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'check']) Assinar termo</button></div>
    </form>
  @else
    <p class="suave">Aguardando a assinatura de {{ $t->usuario?->nome }} (pelo portal, em "Meus equipamentos").</p>
  @endif
  <p class="suave"><small>Gerado em @dataHora($t->criado_em) por {{ $t->geradoPor?->nome ?? '—' }}.</small></p>
</section>
@endsection
