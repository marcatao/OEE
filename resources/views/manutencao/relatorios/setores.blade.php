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

  @php
    $nomesUnidades = config('oee.unidades');
    // Uma seção por unidade (na ordem do cadastro de unidades), cada uma com o seu subtotal.
    $porUnidade = collect($linhas)->groupBy('unidade')->sortBy(fn ($l, $u) => array_search($u, array_keys($nomesUnidades), true));
    $resumo = $porUnidade->map(function ($ls) {
        $min = $ls->sum('minutos');
        $media = $ls->sum('media');

        return ['minutos' => $min, 'media' => $media, 'diferenca' => $media > 0 ? ($min - $media) / $media : null,
            'incomuns' => $ls->where('incomum', true)->count()];
    });
    $totMin = $resumo->sum('minutos');
    $totMedia = $resumo->sum('media');
  @endphp

  @if ($porUnidade->isEmpty())
    <p class="suave">Nenhuma hora apontada neste mês nem nos anteriores.</p>
  @else
    <h2>Resumo por unidade</h2>
    <div class="rolagem">
    <table class="tabela">
      <thead><tr><th>Unidade</th><th class="num">Horas no mês</th><th class="num">Média mensal</th><th class="num">Diferença</th><th class="num">Setores incomuns</th></tr></thead>
      <tbody>
      @foreach ($resumo as $unidade => $r)
        <tr>
          <td><a href="#unidade-{{ $unidade }}"><span class="etiqueta unidade-{{ $unidade }}">{{ $nomesUnidades[$unidade] ?? $unidade }}</span></a></td>
          <td class="num">{{ HorasManutencao::hhmm($r['minutos']) }}</td>
          <td class="num">{{ HorasManutencao::hhmm($r['media']) }}</td>
          <td class="num">{{ $r['diferenca'] === null ? '—' : number_format($r['diferenca'] * 100, 1, ',', '.').'%' }}</td>
          <td class="num {{ $r['incomuns'] > 0 ? 'incomum' : 'aceitavel' }}">{{ $r['incomuns'] }}</td>
        </tr>
      @endforeach
      </tbody>
      <tfoot><tr><th>Total</th><th class="num">{{ HorasManutencao::hhmm($totMin) }}</th><th class="num">{{ HorasManutencao::hhmm($totMedia) }}</th>
        <th class="num">{{ $totMedia > 0 ? number_format(($totMin - $totMedia) / $totMedia * 100, 1, ',', '.').'%' : '—' }}</th>
        <th class="num">{{ $resumo->sum('incomuns') }}</th></tr></tfoot>
    </table>
    </div>

    @foreach ($porUnidade as $unidade => $ls)
      <h2 id="unidade-{{ $unidade }}" style="margin-top:1.5rem"><span class="etiqueta unidade-{{ $unidade }}">{{ $nomesUnidades[$unidade] ?? $unidade }}</span></h2>
      <div class="rolagem">
      <table class="tabela">
        <thead><tr><th>Setor (centro de custo)</th><th class="num">Horas no mês</th><th class="num">Média mensal</th><th class="num">Diferença</th><th>Situação</th><th>Justificativa</th></tr></thead>
        <tbody>
        @foreach ($ls as $l)
          @php $chave = $l['unidade'].'|'.$l['centro_custo']; @endphp
          <tr>
            <td>{{ $l['centro_custo'] ?: 'Sem centro de custo' }}@if (isset($centros[$l['centro_custo']]))<br><small class="suave">{{ $centros[$l['centro_custo']]->descricao() }}</small>@endif</td>
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
        @endforeach
        </tbody>
        <tfoot><tr><th>Subtotal {{ $nomesUnidades[$unidade] ?? $unidade }}</th><th class="num">{{ HorasManutencao::hhmm($resumo[$unidade]['minutos']) }}</th>
          <th class="num">{{ HorasManutencao::hhmm($resumo[$unidade]['media']) }}</th>
          <th class="num">{{ $resumo[$unidade]['diferenca'] === null ? '—' : number_format($resumo[$unidade]['diferenca'] * 100, 1, ',', '.').'%' }}</th><th></th><th></th></tr></tfoot>
      </table>
      </div>
    @endforeach
  @endif
</section>

<section class="cartao">
  <h2>Total apontado × total disponível</h2>
  @php
    $aprovPorUnidade = collect($aproveitamento)->groupBy(fn ($l) => $l['mantenedor']->unidade)
        ->sortBy(fn ($l, $u) => array_search($u, array_keys($nomesUnidades), true));
  @endphp
  @forelse ($aprovPorUnidade as $unidade => $ls)
    <h3 style="margin-top:1rem"><span class="etiqueta unidade-{{ $unidade }}">{{ $nomesUnidades[$unidade] ?? $unidade }}</span></h3>
    @include('manutencao._aproveitamento', ['aproveitamento' => $ls->values()->all()])
  @empty
    @include('manutencao._aproveitamento', ['aproveitamento' => []])
  @endforelse
</section>
@endsection
