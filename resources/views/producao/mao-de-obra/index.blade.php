@extends('layouts.portal')

@section('titulo', 'Mão de obra')

@use('App\Support\Formato')

@section('conteudo')
@php($podeEditar = auth()->user()->ehAdmin())
<section class="cartao">
  <p class="trilha">Produção</p>
  <h1>Mão de obra</h1>
  <p class="suave">Produtos do <strong>tipo MO</strong> (mão de obra) da SB1 do Protheus. Os campos do produto vêm do Protheus a cada
    sincronização. O <strong>fator de serviço</strong> (0 a 100%) é do portal e entra na meta de produção das OPs cujo produto tem esse MO
    na estrutura: horas por peça = ((quantidade do MO + fator%) × 100) ÷ 1,6667; segundos por peça = horas × 60; meta = 60 ÷ segundos
    (pç/min). Ex.: 0,00034 com 5% → 46,69 pç/min. Alterar o fator vale a partir de agora: as metas passadas não mudam.</p>

  <form method="get" class="filtros">
    <label>Buscar <input name="busca" value="{{ $busca }}" placeholder="Código ou descrição do MO"></label>
    <button class="botao secundario" type="submit">Filtrar</button>
    @if ($busca !== '')<a href="{{ route('mao-de-obra.index') }}">Limpar</a>@endif
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Código</th><th>Descrição</th><th>Tipo</th><th>UM</th><th>Grupo</th><th class="num">Qtd. base</th><th>Bloqueado</th>
      <th class="num" title="Produtos que têm esse MO na estrutura (SG1)">Estruturas</th><th class="num">Fator de serviço</th></tr></thead>
    <tbody>
    @forelse ($maos as $m)
      @php($p = $produtos[rtrim($m->b1_cod)] ?? null)
      <tr>
        <td><code>{{ rtrim($m->b1_cod) }}</code>@if ($p && trim($p->b1_filial) !== '')<br><small class="suave">filial {{ $p->b1_filial }}</small>@endif</td>
        <td class="quebra">{{ $p ? trim($p->b1_desc) : '—' }}@unless ($p)<br><small class="texto-alerta">não está mais na SB1</small>@endunless</td>
        <td>{{ $p?->b1_tipo }}</td>
        <td>{{ $p?->b1_um }}</td>
        <td>{{ $p?->b1_grupo }}</td>
        <td class="num">{{ $p && $p->b1_qb ? Formato::quantidade($p->b1_qb) : '—' }}</td>
        <td>{{ $p && $p->b1_msblql === '1' ? 'Sim' : 'Não' }}</td>
        <td class="num">@if ($usos[rtrim($m->b1_cod)] ?? 0)<a href="{{ route('estruturas.index', ['busca' => rtrim($m->b1_cod)]) }}">{{ $usos[rtrim($m->b1_cod)] }}</a>@else 0 @endif</td>
        <td class="num">
          @if ($podeEditar)
            <form method="post" action="{{ route('mao-de-obra.update', $m) }}" class="em-linha" style="display:flex;gap:.35rem;justify-content:flex-end;align-items:center">
              @csrf @method('PUT')
              <input type="number" name="fator_servico" min="0" max="100" step="0.01" value="{{ number_format((float) $m->fator_servico, 2, '.', '') }}"
                     style="width:6.5rem;text-align:right" aria-label="Fator de serviço de {{ rtrim($m->b1_cod) }} (%)"> %
              <button class="botao pequeno" type="submit">Salvar</button>
            </form>
          @else
            {{ number_format((float) $m->fator_servico, 2, ',', '.') }}%
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="9" class="suave">Nenhum produto do tipo MO. Eles vêm da SB1 do Protheus: sincronize em Segurança › Banco de dados.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $maos->links('partials.paginacao') }}
</section>
@endsection
