{{-- Resultado da tela Falta de produtos (trocado sem recarregar a página: ?parcial=1). Variáveis: $faltas (paginadas),
     $producao, $produtos, $painel, $meses, $filtro. --}}
@php($tempo = fn ($m) => \App\Services\FaltaProdutos\FaltaProdutos::tempo($m))
@php($qtd = fn ($v) => \App\Support\Formato::quantidade((float) $v))
@php($rotulosProducao = \App\Services\FaltaProdutos\FaltaProdutos::PRODUCAO)
@php($status = \App\Models\FaltaProduto::STATUS)
@php($linkProducao = fn (?string $s) => route('falta-produtos.index', $filtro->parametros(['producao' => $s ? [$s] : null])))

@if ($filtro->aviso)<p class="aviso" role="status">{{ $filtro->aviso }}</p>@endif

{{-- ===== Indicadores (sobre as faltas do filtro) ===== --}}
<section class="cartao">
  <h2 style="margin-top:0">Indicadores <small class="suave">{{ $filtro->rotuloPeriodo() }} · faltas do filtro</small></h2>
  <div class="falta-cartoes">
    <div class="tempo-cartao"><span class="tempo-titulo">Faltas</span><strong>{{ $painel['faltas'] }}</strong>
      <small>{{ $painel['produtos'] }} {{ $painel['produtos'] === 1 ? 'produto' : 'produtos' }} diferentes</small></div>
    <div class="tempo-cartao"><span class="tempo-titulo">Tempo médio de atendimento</span><strong>{{ $tempo($painel['atendimento_medio']) }}</strong>
      <small>da solicitação ao atendimento · {{ $painel['atendidas'] }} {{ $painel['atendidas'] === 1 ? 'atendida' : 'atendidas' }}@if ($painel['atendimento_maior'] !== null) · maior {{ $tempo($painel['atendimento_maior']) }}@endif</small></div>
    <div class="tempo-cartao"><span class="tempo-titulo">Em aberto</span><strong>{{ $painel['em_aberto'] }}</strong>
      <small>saldo de {{ $qtd($painel['saldo']) }} peças @if ($painel['aberta_mais_antiga'] !== null) · mais antiga há {{ $tempo($painel['aberta_mais_antiga']) }}@endif</small></div>
    <div class="tempo-cartao"><span class="tempo-titulo">Pedidos esperando</span><strong>{{ \App\Support\Formato::moeda($painel['valor_pedidos']) }}</strong>
      <small>valor dos pedidos das faltas em aberto</small></div>
  </div>

  <h3>Faltas em aberto pela situação da produção <small class="suave">clique para filtrar</small></h3>
  <div class="falta-cartoes">
    @foreach ($rotulosProducao as $chave => $rotulo)
      <a class="tempo-cartao @if ($filtro->producao === [$chave]) ativo @endif" href="{{ $linkProducao($filtro->producao === [$chave] ? null : $chave) }}"
         data-parcial data-producao="{{ $filtro->producao === [$chave] ? '' : $chave }}">
        <span class="falta-producao {{ $chave }}">{{ $rotulo }}</span>
        <strong>{{ $painel['por_situacao'][$chave] ?? 0 }}</strong>
        <small>{{ ['em_producao' => 'OP sendo produzida agora (apontamento real)', 'direcionada' => 'OP é a atual de uma máquina, que não está produzindo',
          'com_op' => 'OP aberta, ainda sem máquina', 'sem_op' => 'ninguém abriu OP para o produto'][$chave] }}</small>
      </a>
    @endforeach
  </div>
</section>

{{-- ===== Mês a mês: faltantes × atendidas ===== --}}
<section class="cartao">
    <h2 style="margin-top:0">Faltantes × atendidas mês a mês</h2>
    <p class="suave"><small>Faltantes = solicitadas no mês (sem as canceladas); atendidas = concluídas no mês. Usa o período e a busca do filtro,
      mas não a legenda{{ $filtro->de || $filtro->ate ? '' : '; sem período, os últimos 12 meses' }}.</small></p>
    @php($maximo = max(1, collect($meses)->flatMap(fn ($m) => [$m['faltantes'], $m['atendidas']])->max()))
    @php($passo = max(1, (int) ceil($maximo / 4)))
    @php($topo = $passo * 4)
    @php($n = max(1, count($meses)))
    @php($larg = 960) @php($alt = 250) @php($esq = 34) @php($base = 216) @php($util = $larg - $esq - 8)
    @php($grupo = $util / $n) @php($barra = min(22, max(5, ($grupo - 8) / 2)))
    @php($y = fn ($v) => $base - ($v / $topo) * ($base - 12))
    <div class="grafico-legenda" aria-hidden="true">
      <span><i class="leg-barra leg-faltantes"></i>Faltantes</span>
      <span><i class="leg-barra leg-atendidas"></i>Atendidas</span>
    </div>
    <svg class="grafico-tempos" viewBox="0 0 {{ $larg }} {{ $alt }}" role="img" aria-label="Produtos faltantes e atendidos por mês. A tabela abaixo tem os mesmos números.">
      @for ($i = 0; $i <= 4; $i++)
        @php($yy = $y($i * $passo))
        <line class="g-grade" x1="{{ $esq }}" x2="{{ $larg - 8 }}" y1="{{ $yy }}" y2="{{ $yy }}"></line>
        <text class="g-eixo" x="{{ $esq - 6 }}" y="{{ $yy + 4 }}" text-anchor="end">{{ $i * $passo }}</text>
      @endfor
      @foreach ($meses as $i => $m)
        @php($cx = $esq + $grupo * $i + $grupo / 2)
        @foreach (['faltantes' => -1, 'atendidas' => 1] as $chave => $lado)
          @php($x = $cx + ($lado < 0 ? -$barra - 1 : 1))
          @if ($m[$chave] > 0)
            <rect class="g-{{ $chave }}" x="{{ $x }}" y="{{ $y($m[$chave]) }}" width="{{ $barra }}" height="{{ max(0, $base - $y($m[$chave])) }}" rx="2">
              <title>{{ $m['rotulo'] }} · {{ $chave === 'faltantes' ? 'Faltantes' : 'Atendidas' }}: {{ $m[$chave] }}</title>
            </rect>
          @endif
        @endforeach
        @if ($n <= 14 || $i % 2 === 0)
          <text class="g-eixo" x="{{ $cx }}" y="{{ $base + 18 }}" text-anchor="middle">{{ $m['rotulo'] }}</text>
        @endif
      @endforeach
      <line class="g-base" x1="{{ $esq }}" x2="{{ $larg - 8 }}" y1="{{ $base }}" y2="{{ $base }}"></line>
    </svg>
    <details class="grafico-tabela">
      <summary>Ver em tabela</summary>
      <div class="rolagem">
      <table class="tabela">
        <thead><tr><th>Mês</th><th class="num">Faltantes</th><th class="num">Atendidas</th></tr></thead>
        <tbody>
        @foreach ($meses as $m)
          <tr><td>{{ $m['rotulo'] }}</td><td class="num">{{ $m['faltantes'] }}</td><td class="num">{{ $m['atendidas'] }}</td></tr>
        @endforeach
        </tbody>
      </table>
      </div>
    </details>
  </section>

<div class="falta-paineis">
  {{-- ===== Produtos que mais faltam ===== --}}
  <section class="cartao">
    <h2 style="margin-top:0">Produtos que mais faltam</h2>
    @php($maiorRanking = max(1, collect($painel['ranking'])->max('faltas') ?? 1))
    @if ($painel['ranking'])
      <ul class="falta-barras">
        @foreach ($painel['ranking'] as $r)
          @php($p = $produtos[$r['produto']] ?? null)
          <li title="{{ $r['produto'] }} {{ $p?->descricao() }} · {{ $r['faltas'] }} faltas · {{ $qtd($r['quantidade']) }} peças solicitadas">
            <span class="rotulo"><code>{{ $r['produto'] }}</code> <small class="suave">{{ $p?->descricao() }}</small></span>
            <span class="trilha"><i style="width:{{ round($r['faltas'] / $maiorRanking * 100) }}%"></i></span>
            <strong>{{ $r['faltas'] }}</strong>
          </li>
        @endforeach
      </ul>
      <p class="suave"><small>Quantidade de faltas no filtro (as 10 primeiras).</small></p>
    @else
      <p class="suave">Nenhuma falta no filtro.</p>
    @endif
  </section>

  {{-- ===== Idade das faltas em aberto ===== --}}
  <section class="cartao">
    <h2 style="margin-top:0">Há quanto tempo estão em aberto</h2>
    @php($maiorIdade = max(1, max($painel['idades'] ?: [0])))
    <ul class="falta-barras">
      @foreach ($painel['idades'] as $rotulo => $quantas)
        <li><span class="rotulo">{{ $rotulo }}</span><span class="trilha"><i style="width:{{ round($quantas / $maiorIdade * 100) }}%"></i></span><strong>{{ $quantas }}</strong></li>
      @endforeach
    </ul>
    <p class="suave"><small>Faltas pendentes ou atendidas parcialmente do filtro, pela data da solicitação.</small></p>

    <h3 style="margin-top:1rem">Por legenda</h3>
    <ul class="falta-barras">
      @php($maiorStatus = max(1, max($painel['por_status'] ?: [0])))
      @foreach ($status as $codigo => $s)
        <li><span class="rotulo falta-status" title="{{ $s['rotulo'] }}"><i class="falta-bola falta-{{ $s['cor'] }}" aria-hidden="true"></i>{{ $s['curto'] }}</span>
          <span class="trilha"><i style="width:{{ round(($painel['por_status'][$codigo] ?? 0) / $maiorStatus * 100) }}%"></i></span>
          <strong>{{ $painel['por_status'][$codigo] ?? 0 }}</strong></li>
      @endforeach
    </ul>
  </section>
</div>

{{-- ===== Lista ===== --}}
<section class="cartao">
  <h2 style="margin-top:0">Faltas <small class="suave">{{ $faltas->total() }} no filtro</small></h2>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Legenda</th><th>Documento</th><th>Produto</th><th class="num">Solicitada</th><th class="num">Atendida</th><th class="num">Falta</th>
      <th>Produção agora</th><th>Solicitação</th><th>Atendimento</th><th>Pedido</th></tr></thead>
    <tbody>
    @forelse ($faltas as $f)
      @php($s = $status[$f->status()])
      @php($p = $produtos[$f->produto()] ?? null)
      @php($prod = $producao[$f->produto()] ?? null)
      <tr>
        <td><span class="falta-status"><i class="falta-bola falta-{{ $s['cor'] }}" aria-hidden="true"></i>{{ $s['curto'] }}</span></td>
        <td><code>{{ $f->zb5_doc ?: '-' }}</code>@if (trim($f->zb5_filial) !== '')<br><small class="suave">filial {{ $f->zb5_filial }}</small>@endif</td>
        <td class="quebra"><code>{{ $f->produto() }}</code><br><small>{{ $p?->descricao() ?? 'produto não sincronizado' }}</small></td>
        <td class="num">{{ $qtd($f->zb5_qtdsol) }}</td>
        <td class="num">{{ $f->zb5_qtdate > 0 ? $qtd($f->zb5_qtdate) : '-' }}</td>
        <td class="num">{{ $f->emAberto() ? $qtd($f->saldo()) : '-' }}</td>
        <td class="quebra">
          <span class="falta-producao {{ $f->situacao_producao }}">{{ $rotulosProducao[$f->situacao_producao] ?? 'Encerrada' }}</span>
          @if ($f->emAberto() && $prod)
            @foreach ($prod['produzindo'] as $item)
              <span class="falta-detalhe">Produzindo na <strong>{{ $item['maquina']->codigo }}</strong> — OP <a href="{{ route('ordens-producao.show', $item['op']) }}">{{ $item['op']->numero }}</a></span>
            @endforeach
            @foreach ($prod['direcionadas'] as $item)
              @continue($prod['produzindo']->contains(fn ($x) => $x['maquina']->id === $item['maquina']->id))
              <span class="falta-detalhe">OP <a href="{{ route('ordens-producao.show', $item['op']) }}">{{ $item['op']->numero }}</a> na {{ $item['maquina']->codigo }}
                ({{ $item['maquina']->rotuloSituacao() }})</span>
            @endforeach
            @if ($prod['direcionadas']->isEmpty() && $prod['produzindo']->isEmpty())
              @foreach ($prod['ops']->take(3) as $op)
                <span class="falta-detalhe">OP <a href="{{ route('ordens-producao.show', $op) }}">{{ $op->numero }}</a> ·
                  {{ $qtd(max(0, $op->c2_quant - $op->c2_quje)) }} a produzir @if (trim($op->c2_datprf) !== '') · prev. {{ \App\Models\OrdemProducao::data($op->c2_datprf) }}@endif</span>
              @endforeach
              @if ($prod['ops']->count() > 3)<span class="falta-detalhe">+ {{ $prod['ops']->count() - 3 }} OPs</span>@endif
            @endif
          @endif
        </td>
        <td>@if ($f->solicitado_em)@dataHora($f->solicitado_em)@else - @endif
          @if (trim($f->zb5_solici) !== '')<br><small class="suave">{{ $f->zb5_solici }}</small>@endif
          @if ($f->emAberto() && $f->minutosEmAberto() !== null)<br><small class="suave">há {{ $tempo($f->minutosEmAberto()) }}</small>@endif</td>
        <td>@if ($f->atendido_em)@dataHora($f->atendido_em)
            <br><small class="suave">em {{ $tempo($f->minutosAtendimento()) }}@if (trim($f->zb5_atendi) !== '') · {{ $f->zb5_atendi }}@endif</small>
          @else - @endif</td>
        <td>{{ trim($f->zb5_pedido) ?: '-' }}@if ($f->zb5_vlped > 0)<br><small class="suave">{{ \App\Support\Formato::moeda($f->zb5_vlped) }}</small>@endif</td>
      </tr>
    @empty
      <tr><td colspan="10" class="suave">Nenhuma falta de produto no filtro. As faltas vêm do Protheus (ZB5): sincronize em Segurança › Banco de dados.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  @if ($faltas->hasPages())
    <nav class="paginacao" aria-label="Paginação">
      @if ($faltas->onFirstPage())<span>‹ Anterior</span>@else<a href="{{ $faltas->previousPageUrl() }}" data-parcial>‹ Anterior</a>@endif
      <span>Página {{ $faltas->currentPage() }} de {{ $faltas->lastPage() }}</span>
      @if ($faltas->hasMorePages())<a href="{{ $faltas->nextPageUrl() }}" data-parcial>Próxima ›</a>@else<span>Próxima ›</span>@endif
    </nav>
  @endif
</section>
