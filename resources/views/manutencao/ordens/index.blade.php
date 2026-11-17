@extends('layouts.portal')

@section('titulo', 'Ordens de serviço')

@use('App\Models\Manutencao\OrdemServico')
@use('App\Models\Manutencao\SolicitacaoServico')
@use('App\Services\Manutencao\HorasManutencao')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço</p>
  <div class="titulo-com-acao">
    <h1>Ordens de serviço (OS)</h1>
    @if (\App\Support\AcessoOs::podeSolicitar(auth()->user()))
      <a class="botao" href="{{ route('os.solicitacoes.create') }}">@include('layouts.partials.icone', ['nome' => 'mais']) Nova solicitação</a>
    @endif
  </div>
  <p class="suave">OS emitidas a partir das solicitações aprovadas e as preventivas geradas pela PAMP. Ordenadas por prioridade.</p>

  <div class="os-totais">
    <a href="{{ route('os.ordens.index', ['status' => 'em_aberto'] + $f) }}" @class(['ativo' => $f['status'] === 'em_aberto'])>Em aberto</a>
    @foreach (OrdemServico::STATUS as $chave => $rotulo)
      <a href="{{ route('os.ordens.index', ['status' => $chave] + $f) }}" @class(['ativo' => $f['status'] === $chave])>
        <span class="etiqueta os-{{ $chave }}">{{ $rotulo }}</span> <strong>{{ $totais[$chave] ?? 0 }}</strong></a>
    @endforeach
    <a href="{{ route('os.ordens.index', ['status' => ''] + $f) }}" @class(['ativo' => $f['status'] === ''])>Todas</a>
  </div>

  <form method="get" class="filtros">
    <input type="hidden" name="status" value="{{ $f['status'] }}">
    <label>Buscar <input name="busca" value="{{ $f['busca'] }}" placeholder="Nº da OS/SS, título ou descrição"></label>
    @if (count($unidades) > 1)
      <label>Unidade
        <select name="unidade"><option value="">Todas as minhas</option>
          @foreach ($unidades as $un)<option value="{{ $un }}" @selected($f['unidade'] === $un)>{{ config('oee.unidades')[$un] ?? $un }}</option>@endforeach
        </select></label>
    @endif
    <label>Emitida de <input type="date" name="de" value="{{ $f['de'] }}"></label>
    <label>até <input type="date" name="ate" value="{{ $f['ate'] }}"></label>
    <label>Tipo
      <select name="tipo"><option value="">Todos</option>
        @foreach (SolicitacaoServico::TIPOS as $v => $r)<option value="{{ $v }}" @selected($f['tipo'] === $v)>{{ $r }}</option>@endforeach
      </select></label>
    <label>Máquina
      <select name="maquina" data-busca><option value="">Todas</option>
        @foreach ($maquinas as $m)<option value="{{ $m->id }}" @selected($f['maquina'] === $m->id)>{{ $m->codigo }} — {{ $m->nome }}</option>@endforeach
      </select></label>
    <label>Mantenedor
      <select name="responsavel" data-busca><option value="">Todos</option>
        @foreach ($mantenedores as $m)<option value="{{ $m->id }}" @selected($f['responsavel'] === $m->id)>{{ $m->nome }}</option>@endforeach
      </select></label>
    <button class="botao secundario" type="submit">Filtrar</button>
    <a href="{{ route('os.ordens.index') }}">Limpar</a>
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>OS</th><th>Situação</th><th>Tipo</th><th>Prioridade</th><th>Máquina</th><th>Área / mantenedores</th><th>Título</th><th class="num">Horas</th><th>Emitida</th></tr></thead>
    <tbody>
    @forelse ($ordens as $os)
      <tr>
        <td><a href="{{ route('os.ordens.show', $os) }}"><strong>{{ $os->numeroFormatado() }}</strong></a>
          @if ($os->solicitacao_id)<br><small class="suave">SS {{ str_pad((string) $os->solicitacao_id, 6, '0', STR_PAD_LEFT) }}</small>@endif
          @if ($os->prevista_para)<br><small class="suave">prevista {{ $os->prevista_para->format('d/m/Y') }}</small>@endif</td>
        <td><span class="etiqueta os-{{ $os->status }}">{{ $os->rotuloStatus() }}</span></td>
        <td>{{ $os->rotuloTipo() }}</td>
        <td><span class="prioridade-{{ $os->prioridade }}">{{ $os->rotuloPrioridade() }}</span>@if ($os->maquina_parada && in_array($os->status, OrdemServico::STATUS_ABERTOS, true))<br><small class="os-parada">máquina parada</small>@endif</td>
        <td>@include('manutencao._maquina', ['maquina' => $os->maquina])</td>
        <td>{{ $os->areaExecutante?->nome }}<br><small class="suave">{{ $os->mantenedoresAtribuidos()->map->nomeExibicao()->join(', ') ?: 'definir depois' }}</small></td>
        <td><strong>{{ $os->titulo ?: \Illuminate\Support\Str::limit($os->descricao, 60) }}</strong>@if ($os->titulo)<br><small class="suave">{{ \Illuminate\Support\Str::limit($os->descricao, 80) }}</small>@endif</td>
        <td class="num">{{ HorasManutencao::hhmm($os->total_minutos) }}</td>
        <td>@dataHora($os->criado_em)</td>
      </tr>
    @empty
      <tr><td colspan="9" class="suave">Nenhuma OS encontrada.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $ordens->links('partials.paginacao') }}
</section>
@endsection
