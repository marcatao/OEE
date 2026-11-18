@extends('layouts.portal')

@section('titulo', 'Preventivas (PAMP)')

@section('conteudo')
@php($meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'])
@php($hoje = now(config('oee.fuso_exibicao'))->startOfDay())
<section class="cartao">
  <p class="trilha">Ordens de serviço</p>
  <div class="titulo-com-acao">
    <h1>Preventivas — PAMP {{ $ano }}</h1>
    <div class="os-cabecalho">
      <a class="botao secundario" href="{{ route('os.preventivas.index', ['ano' => $ano - 1] + array_filter(['unidade' => $unidade])) }}">@include('layouts.partials.icone', ['nome' => 'anterior']) {{ $ano - 1 }}</a>
      <a class="botao secundario" href="{{ route('os.preventivas.index', ['ano' => $ano + 1] + array_filter(['unidade' => $unidade])) }}">{{ $ano + 1 }} @include('layouts.partials.icone', ['nome' => 'seta'])</a>
      <form method="post" action="{{ route('os.preventivas.gerar') }}" style="margin:0">@csrf<button class="botao secundario" type="submit">Gerar OS agora</button></form>
      <a class="botao" href="{{ route('os.preventivas.create') }}">@include('layouts.partials.icone', ['nome' => 'mais']) Nova preventiva</a>
    </div>
  </div>
  <p class="suave">O sistema gera sozinho a OS de cada preventiva todo dia de madrugada, alguns dias antes da data (antecedência do plano).
    No calendário: <span class="marca programada" style="display:inline-block;padding:0 .3rem">P</span> programada,
    <span class="etiqueta os-concluida">em aberto</span>, <span class="etiqueta os-rejeitada">atrasada</span>, <span class="etiqueta os-encerrada">feita</span>.</p>
  @include('partials.filtro-unidade', ['manter' => ['ano' => $ano]])

  <div class="rolagem">
  <table class="tabela pamp">
    <thead><tr><th>Preventiva</th><th>Máquina</th><th>Área / responsável</th><th>Periodicidade</th><th>Próxima</th>
      @foreach ($meses as $m)<th class="mes">{{ $m }}</th>@endforeach<th></th></tr></thead>
    <tbody>
    @forelse ($planos as $p)
      <tr @class(['suave' => ! $p->ativo])>
        <td><strong>{{ $p->codigo }}</strong> {{ $p->descricao }}<br><small class="suave">{{ $p->itens_count }} itens no checklist{{ $p->ativo ? '' : ' · inativa' }}</small></td>
        <td>@include('manutencao._maquina', ['maquina' => $p->maquina])</td>
        <td>{{ $p->areaExecutante?->nome }}<br><small class="suave">{{ $p->responsavel?->nome }}</small></td>
        <td>{{ $p->rotuloPeriodicidade() }}</td>
        <td>{{ $p->proxima_em?->format('d/m/Y') ?? '—' }}</td>
        @for ($mes = 1; $mes <= 12; $mes++)
          <td class="mes">
            @foreach ($calendario[$p->id][$mes] ?? [] as $os)
              @php($classe = in_array($os->status, ['concluida', 'encerrada'], true) ? 'feita' : ($os->status === 'cancelada' ? 'programada' : ($os->prevista_para->lt($hoje) ? 'atrasada' : 'aberta')))
              <a class="marca {{ $classe }}" href="{{ route('os.ordens.show', $os) }}" title="{{ $os->numeroFormatado() }} — {{ $os->prevista_para->format('d/m') }} — {{ $os->rotuloStatus() }}">{{ $os->prevista_para->format('d') }}</a>
            @endforeach
            @if (empty($calendario[$p->id][$mes]) && $p->ativo && $p->periodicidade === 'meses' && $p->temNoMes($mes))<span class="marca programada">P</span>@endif
          </td>
        @endfor
        <td><a class="botao pequeno secundario" href="{{ route('os.preventivas.edit', $p) }}">Editar</a></td>
      </tr>
    @empty
      <tr><td colspan="18" class="suave">Nenhuma preventiva cadastrada.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>
@endsection
