@extends('layouts.portal')

@section('titulo', 'Roteiros de operação')

@use('App\Support\Formato')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção</p>
  <h1>Roteiros de operação</h1>
  <p class="suave">Operações de fabricação de cada produto no Protheus (tabela SG2), só para consulta: centro de trabalho (máquina),
    recurso, ferramenta e tempos.
    @if ($ultimaSincronizacao) Última sincronização: @dataHora($ultimaSincronizacao). @else Ainda não sincronizados. @endif</p>

  <form method="get" class="filtros">
    <label>Buscar <input name="busca" value="{{ $busca }}" placeholder="Produto, descrição, operação, centro de trabalho, recurso ou ferramenta"></label>
    @if ($ferramenta !== '')<input type="hidden" name="ferramenta" value="{{ $ferramenta }}">@endif
    <button class="botao secundario" type="submit">Filtrar</button>
    @if ($busca !== '' || $ferramenta !== '')<a href="{{ route('roteiros.index') }}">Limpar</a>@endif
  </form>
  @if ($ferramenta !== '')<p>Operações que usam a ferramenta <code>{{ $ferramenta }}</code>.</p>@endif

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Produto</th><th>Roteiro</th><th>Operação</th><th>Centro de trabalho</th><th>Recurso</th><th>Ferramenta</th>
      <th>Tipo</th><th class="num">Setup</th><th class="num">Tempo padrão</th><th class="num">Lote padrão</th><th class="num">Mão de obra</th></tr></thead>
    <tbody>
    @forelse ($linhas as $l)
      <tr>
        <td class="quebra"><code>{{ $l->g2_produto }}</code><br><small>{{ ($produtos[rtrim($l->g2_produto)] ?? null)?->descricao() ?? 'produto não sincronizado' }}</small></td>
        <td>{{ $l->g2_codigo ?: '-' }}</td>
        <td class="quebra"><code>{{ $l->g2_operac }}</code> {{ $l->g2_descri }}</td>
        <td class="quebra">@if (trim($l->g2_ctrab) !== '')<code>{{ $l->g2_ctrab }}</code>
          @if ($m = $maquinas[rtrim($l->g2_ctrab)] ?? null)<br><small>{{ $m->nome }}</small>@endif @else - @endif</td>
        <td>{{ $l->g2_recurso ?: '-' }}</td>
        <td class="quebra">@if (trim($l->g2_ferram) !== '')<code>{{ $l->g2_ferram }}</code>
          @if ($f = $ferramentas[rtrim($l->g2_ferram)] ?? null)<br><small>{{ $f->h4_descri }} · {{ $f->h4_qtdcavativas }}/{{ $f->h4_qtdcav }} cavidades</small>@endif @else - @endif</td>
        <td>{{ $l->rotuloTipo() }}</td>
        <td class="num">{{ Formato::quantidade($l->g2_setup) }}</td>
        <td class="num">{{ Formato::quantidade($l->g2_tempad) }}</td>
        <td class="num">{{ Formato::quantidade($l->g2_lotepad) }}</td>
        <td class="num">{{ Formato::quantidade($l->g2_maoobra) }}</td>
      </tr>
    @empty
      <tr><td colspan="11" class="suave">Nenhum roteiro encontrado. Os roteiros vêm do Protheus: sincronize em Segurança › Banco de dados.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $linhas->links('partials.paginacao') }}
</section>
@endsection
