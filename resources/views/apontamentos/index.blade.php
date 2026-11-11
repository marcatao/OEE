@extends('layouts.portal')

@section('titulo', 'Apontamento real')

@use('App\Support\Formato')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção</p>
  <div class="titulo-com-acao">
    <h1>Apontamento real</h1>
    <div class="acoes">
      @if (auth()->user()->pode('timeline'))
        <a class="botao secundario" href="{{ route('timeline') }}">@include('layouts.partials.icone', ['nome' => 'timeline']) Ver timeline</a>
      @endif
      <a class="botao" href="{{ route('apontamentos.create', array_filter(['maquina_id' => $filtroMaquina])) }}">@include('layouts.partials.icone', ['nome' => 'mais']) Novo apontamento</a>
    </div>
  </div>
  <p class="suave">Cada apontamento diz em que estado (legenda) a máquina ficou, do horário inicial ao final. Sem horário final, a máquina continua nesse estado; ao registrar o próximo, o anterior é encerrado sozinho.</p>

  <form method="get" class="filtros">
    <label>Máquina
      <select name="maquina_id">
        <option value="">Todas</option>
        @foreach ($maquinas as $m)
          <option value="{{ $m->id }}" @selected($filtroMaquina === $m->id)>{{ $m->nome }}</option>
        @endforeach
      </select>
    </label>
    <label>Dia <input type="date" name="data" value="{{ $filtroData }}"></label>
    <button class="botao secundario" type="submit">Filtrar</button>
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Máquina</th><th>Legenda</th><th>Início</th><th>Fim</th><th class="num">Duração</th><th>Ordem de produção</th><th>Referência</th><th>Registrado por</th><th></th></tr></thead>
    <tbody>
    @forelse ($apontamentos as $a)
      <tr>
        <td>{{ $a->maquina?->nome }}</td>
        <td><span class="tl-chip"><i class="tl-cor" style="background: {{ $a->legenda?->cor }}"></i>{{ $a->legenda?->codigo }} <small class="suave">{{ $a->legenda?->descricao }}</small></span></td>
        <td>@dataHora($a->inicio)</td>
        <td>{!! $a->fim ? e(\App\Providers\AppServiceProvider::dataHora($a->fim)) : '<span class="etiqueta situacao-online">em andamento</span>' !!}</td>
        <td class="num">{{ Formato::minutos($a->duracaoMin()) }}</td>
        <td>@if ($a->ordemProducao)<code>{{ $a->ordemProducao->numero }}</code> <span class="etiqueta op-{{ $a->ordemProducao->status }}">{{ $a->ordemProducao->rotuloStatus() }}</span>@else - @endif</td>
        <td>{{ $a->referencia ?? '-' }}</td>
        <td>{{ $a->criadoPor?->nome ?? '-' }}</td>
        <td><a class="botao pequeno secundario" href="{{ route('apontamentos.edit', $a) }}">Editar</a>
          @if (in_array($a->legenda?->tipo, ['parada', 'planejada'], true) && (auth()->user()->pode('os_solicitar') || auth()->user()->pode('os_aprovar')))
            <a class="botao pequeno secundario" href="{{ route('os.solicitacoes.create', ['apontamento' => $a->id]) }}" title="Abrir solicitação de serviço para esta parada">Abrir SS</a>
          @endif</td>
      </tr>
    @empty
      <tr><td colspan="9" class="suave">Nenhum apontamento encontrado.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $apontamentos->links('partials.paginacao') }}
</section>
@endsection
