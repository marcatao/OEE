@extends('layouts.portal')

@section('titulo', 'Horas por setor')

@use('App\Services\Manutencao\HorasManutencao')
@use('App\Services\Manutencao\RelatorioHorasManutencao')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço › Relatórios</p>
  <h1>Horas por setor — {{ $mes->translatedFormat('F/Y') }}</h1>
  <p class="suave">Horas apontadas por setor (centro de custo da OS) × média mensal dos {{ RelatorioHorasManutencao::MESES_MEDIA }} meses anteriores.
    Diferença de {{ RelatorioHorasManutencao::LIMITE_INCOMUM * 100 }}% ou mais = <span class="incomum">Incomum</span> (justifique); abaixo = <span class="aceitavel">Aceitável</span>.</p>
  @include('manutencao._erros')
  <form method="get" class="filtros">
    <label>Mês <input type="month" name="mes" value="{{ $mes->format('Y-m') }}"></label>
    <button class="botao secundario" type="submit">Ver</button>
    <button class="botao secundario nao-imprimir" type="button" onclick="window.print()">Imprimir</button>
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Setor (centro de custo)</th><th>Unidade</th><th class="num">Horas no mês</th><th class="num">Média mensal</th><th class="num">Diferença</th><th>Situação</th><th>Justificativa</th></tr></thead>
    <tbody>
    @forelse ($linhas as $l)
      @php($chave = $l['unidade'].'|'.$l['centro_custo'])
      <tr>
        <td>{{ $l['centro_custo'] ?: 'Sem centro de custo' }}@if (isset($centros[$l['centro_custo']]))<br><small class="suave">{{ $centros[$l['centro_custo']]->descricao() }}</small>@endif</td>
        <td><span class="etiqueta unidade-{{ $l['unidade'] }}">{{ config('oee.unidades')[$l['unidade']] ?? $l['unidade'] }}</span></td>
        <td class="num">{{ HorasManutencao::hhmm($l['minutos']) }}</td>
        <td class="num">{{ HorasManutencao::hhmm($l['media']) }}</td>
        <td class="num">{{ $l['diferenca'] === null ? '—' : number_format($l['diferenca'] * 100, 1, ',', '.').'%' }}</td>
        <td class="{{ $l['incomum'] ? 'incomum' : 'aceitavel' }}">{{ $l['incomum'] ? 'Incomum' : 'Aceitável' }}</td>
        <td>
          <form method="post" action="{{ route('os.relatorios.justificar') }}" class="os-acoes" style="margin:0">
            @csrf
            <input type="hidden" name="mes" value="{{ $mes->format('Y-m') }}">
            <input type="hidden" name="unidade" value="{{ $l['unidade'] }}">
            <input type="hidden" name="centro_custo" value="{{ $l['centro_custo'] }}">
            <input name="texto" maxlength="1000" value="{{ $justificativas[$chave]->texto ?? '' }}" placeholder="{{ $l['incomum'] ? 'Justifique a diferença' : 'opcional' }}" aria-label="Justificativa" @if ($l['incomum']) required @endif>
            <button class="botao pequeno secundario nao-imprimir" type="submit">Salvar</button>
          </form>
        </td>
      </tr>
    @empty
      <tr><td colspan="7" class="suave">Nenhuma hora apontada neste mês nem nos anteriores.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>

<section class="cartao">
  <h2>Total apontado × total disponível</h2>
  @include('manutencao._aproveitamento', ['aproveitamento' => $aproveitamento])
</section>
@endsection
