@extends('layouts.portal')

@section('titulo', $os->numeroFormatado())

@use('App\Services\Manutencao\HorasManutencao')

@section('conteudo')
<section class="cartao">
  <div class="titulo-com-acao">
    <h1>{{ config('oee.nome') }} — {{ $os->numeroFormatado() }}@if ($os->titulo)<br><small>{{ $os->titulo }}</small>@endif</h1>
    <button class="botao nao-imprimir" type="button" onclick="window.print()">@include('layouts.partials.icone', ['nome' => 'imprimir']) Imprimir</button>
  </div>
  <dl class="dados-curtos">
    <div><dt>Tipo</dt><dd>{{ $os->rotuloTipo() }}</dd></div>
    <div><dt>Situação</dt><dd>{{ $os->rotuloStatus() }}</dd></div>
    <div><dt>Prioridade</dt><dd>{{ $os->rotuloPrioridade() }}</dd></div>
    <div><dt>Máquina</dt><dd>@include('manutencao._maquina', ['maquina' => $os->maquina]){{ $os->maquina_parada ? ' (parada)' : '' }}</dd></div>
    <div><dt>Área executante</dt><dd>{{ $os->areaExecutante?->nome }}</dd></div>
    <div><dt>Mantenedores</dt><dd>{{ $os->mantenedoresAtribuidos()->map->nomeExibicao()->join(', ') ?: '—' }}</dd></div>
    <div><dt>Centro de custo</dt><dd>{{ $os->centro_custo ?: '—' }}</dd></div>
    <div><dt>Solicitante</dt><dd>{{ $os->solicitante?->nome ?? ($os->plano ? 'PAMP '.$os->plano->codigo : '—') }}</dd></div>
    <div><dt>Emitida</dt><dd>@dataHora($os->criado_em)</dd></div>
    @if ($os->prevista_para)<div><dt>Prevista</dt><dd>{{ $os->prevista_para->format('d/m/Y') }}</dd></div>@endif
  </dl>
  <h2>Descrição</h2>
  <p class="os-texto">{{ $os->descricao }}</p>
  <h2>Serviço realizado</h2>
  <p class="os-texto">{{ $os->solucao ?: "\n\n\n" }}</p>

  @if ($checklist->isNotEmpty())
    <h2>Checklist</h2>
    <table class="tabela">
      <thead><tr><th>Item</th><th>OK</th><th>Não OK</th><th>N/A</th><th>Observação</th></tr></thead>
      <tbody>
      @foreach ($checklist as $item)
        <tr><td>{{ $item->descricao }}</td><td>{{ $item->resposta === 'ok' ? '✔' : '☐' }}</td><td>{{ $item->resposta === 'nok' ? '✔' : '☐' }}</td><td>{{ $item->resposta === 'na' ? '✔' : '☐' }}</td><td>{{ $item->observacao }}</td></tr>
      @endforeach
      </tbody>
    </table>
  @endif

  <h2>Apontamento de horas</h2>
  <table class="tabela">
    <thead><tr><th>Mantenedor</th><th>Início</th><th>Fim</th><th class="num">Normal</th><th class="num">Extra</th><th>Descrição</th></tr></thead>
    <tbody>
    @foreach ($apontamentos as $ap)
      <tr><td>{{ $ap->mantenedor?->nome }}</td><td>@dataHora($ap->inicio)</td><td>{{ $ap->fim ? \App\Providers\AppServiceProvider::dataHora($ap->fim) : '' }}</td>
        <td class="num">{{ HorasManutencao::hhmm($ap->minutos_normais) }}</td><td class="num">{{ HorasManutencao::hhmm($ap->minutos_extras) }}</td><td>{{ $ap->descricao }}</td></tr>
    @endforeach
    @for ($i = $apontamentos->count(); $i < 4; $i++)<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>@endfor
    </tbody>
  </table>

  @if ($materiais->isNotEmpty())
    <h2>Materiais</h2>
    <table class="tabela">
      <thead><tr><th>Material</th><th class="num">Qtde</th><th>Situação</th></tr></thead>
      <tbody>@foreach ($materiais as $m)<tr><td>{{ $m->produto_codigo }} {{ $m->descricao }}</td><td class="num">{{ number_format((float) $m->quantidade, 2, ',', '.') }} {{ $m->um }}</td><td>{{ $m->rotuloStatus() }}</td></tr>@endforeach</tbody>
    </table>
  @endif

  <div class="assinaturas">
    <div>Mantenedor</div>
    <div>Responsável da manutenção</div>
    <div>@if ($assinatura)<img class="assinatura-img" src="data:image/png;base64,{{ $assinatura->imagem_png }}" alt="Assinatura"><br>@endif
      Solicitante{{ $os->aceite_em ? ' — aceite eletrônico: '.$os->aceitePor?->nome.' em '.\App\Providers\AppServiceProvider::dataHora($os->aceite_em) : '' }}@if ($os->avaliacao && $os->aceite_em) · avaliação: {{ \App\Models\Manutencao\OrdemServico::AVALIACOES[$os->avaliacao] ?? $os->avaliacao }}@endif</div>
  </div>
</section>
@endsection
