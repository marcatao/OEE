@extends('layouts.portal')

@section('titulo', 'Ferramentas')

@use('App\Support\Formato')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção</p>
  <h1>Ferramentas</h1>
  <p class="suave">Ferramentas do Protheus (tabela SH4). Código, descrição, quantidade, vida útil e <strong>cavidades</strong> vêm do Protheus
    a cada sincronização. As <strong>cavidades ativas</strong> são do portal: começam iguais às cavidades e podem ser ajustadas aqui
    (nunca mais que as cavidades); a sincronização não muda esse valor.
    @if ($ultimaSincronizacao) Última sincronização: @dataHora($ultimaSincronizacao). @else Ainda não sincronizadas. @endif @include('partials.proxima-sincronizacao', ['chave' => 'ferramentas'])</p>

  <form method="get" class="filtros">
    <label>Buscar <input name="busca" value="{{ $busca }}" placeholder="Código ou descrição da ferramenta"></label>
    <button class="botao secundario" type="submit">Filtrar</button>
    @if ($busca !== '')<a href="{{ route('ferramentas.index') }}">Limpar</a>@endif
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Código</th><th>Unidade</th><th>Descrição</th><th class="num">Cavidades</th><th class="num">Cavidades ativas</th>
      <th class="num">Quantidade</th><th class="num">Vida útil</th><th class="num">Operações</th><th></th></tr></thead>
    <tbody>
    @forelse ($ferramentas as $f)
      <tr>
        <td><code>{{ $f->h4_codigo }}</code>@if (trim($f->h4_filial) !== '')<br><small class="suave">filial {{ $f->h4_filial }}</small>@endif</td>
        <td><span class="etiqueta unidade-{{ $f->unidade }}">{{ $f->rotuloUnidade() }}</span></td>
        <td class="quebra">{{ $f->h4_descri }}</td>
        <td class="num">{{ Formato::inteiro($f->h4_qtdcav) }}</td>
        <td class="num"><strong @class(['texto-alerta' => $f->cavidadesAcima()])>{{ Formato::inteiro($f->h4_qtdcavativas) }}</strong>
          @if ($f->cavidadesAcima())<br><small class="texto-alerta">acima das cavidades: ajuste</small>
          @elseif ($f->h4_qtdcavativas < $f->h4_qtdcav)<br><small class="suave">{{ $f->h4_qtdcav - $f->h4_qtdcavativas }} desativada(s)</small>@endif</td>
        <td class="num">{{ Formato::quantidade($f->h4_quant) }}</td>
        <td class="num">{{ $f->h4_vidauti > 0 ? Formato::quantidade($f->h4_vidauti) : '-' }}</td>
        <td class="num">@if ($usos[$f->codigo()] ?? 0)<a href="{{ route('roteiros.index', ['ferramenta' => $f->codigo()]) }}">{{ $usos[$f->codigo()] }}</a>@else 0 @endif</td>
        <td>@if (auth()->user()->ehAdmin())<a class="botao pequeno" href="{{ route('ferramentas.edit', $f) }}">Editar</a>@endif</td>
      </tr>
    @empty
      <tr><td colspan="9" class="suave">Nenhuma ferramenta encontrada. As ferramentas vêm do Protheus: sincronize em Segurança › Banco de dados.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $ferramentas->links('partials.paginacao') }}
</section>
@endsection
