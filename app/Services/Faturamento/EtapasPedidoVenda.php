<?php

namespace App\Services\Faturamento;

use App\Models\Faturamento\PedidoVendaEtapa;
use App\Models\Faturamento\RegraEtapaPedido;
use App\Models\PedidoVenda;
use App\Models\Sincronizacao;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Etapa de cada pedido de venda (Faturamento › Pedidos de venda), calculada pelo portal a partir das cópias
 * pedidos_venda (SC5 + SC6, uma linha por item) e pedidos_venda_liberacoes (SC9), gravadas pela procedure
 * dbo.sp_sincronizar_pedidos_venda; o resultado vai para pedidos_venda_etapas (uma linha por pedido). Só lê tabelas do
 * portal (o portal nunca acessa o Protheus direto). Regra única: a tela, o assistente e a sincronização usam esta classe.
 *
 * Etapa de cada LIBERAÇÃO em aberto (SC9 não excluída e sem nota):
 * - bloqueada por crédito ou estoque (C9_BLCRED / C9_BLEST preenchidos, fora o "10" = faturado) = carteira;
 * - senão, a primeira etapa do WMS cuja regra vale (Faturamento › Etapas dos pedidos), da mais adiantada para a menos:
 *   aguardando faturamento, logística, conferência, separação;
 * - nenhuma regra = em expedição (liberada, ainda sem etapa do WMS).
 * Etapa de cada ITEM com saldo (C6_QTDVEN > C6_QTDENT, não eliminado por resíduo = C6_BLQ "R"): a mais ATRASADA das
 * liberações dele; saldo sem liberação (ou maior que o liberado) = carteira.
 * Etapa do PEDIDO (pedido do usuário em 26/11/2026: "etapa mais atrasada"): a mais atrasada dos itens com saldo; sem
 * itens com saldo = faturado (se algo foi entregue) ou encerrado (null: eliminado por resíduo sem faturar, fora da tela).
 * "parcial" = em aberto com parte já faturada.
 *
 * Dias na etapa (etapa_desde): faturado = data do último faturamento (C6_DATFAT); em expedição = data da liberação
 * mais recente (C9_DATALIB); as outras contam de quando o portal viu a mudança (no primeiro cálculo: emissão para a
 * carteira e liberação para as etapas do WMS).
 */
class EtapasPedidoVenda
{
    public const CARTEIRA = 'carteira';

    public const EXPEDICAO = 'expedicao';

    public const FATURADO = 'faturado';

    /** Etapas na ordem do processo (a ordem decide a "mais atrasada"). */
    public const ETAPAS = [
        'carteira' => ['rotulo' => 'Em carteira', 'descricao' => 'sem liberação ou bloqueado por crédito/estoque'],
        'expedicao' => ['rotulo' => 'Em expedição', 'descricao' => 'liberado, ainda sem etapa do WMS'],
        'separacao' => ['rotulo' => 'Em separação', 'descricao' => 'WMS: separando'],
        'conferencia' => ['rotulo' => 'Em conferência', 'descricao' => 'WMS: conferindo / embalando'],
        'logistica' => ['rotulo' => 'Na logística', 'descricao' => 'WMS: carga / embarque'],
        'aguardando_faturamento' => ['rotulo' => 'Aguardando faturamento', 'descricao' => 'pronto, falta emitir a nota'],
        'faturado' => ['rotulo' => 'Faturados', 'descricao' => 'nota emitida (C6_DATFAT no mês)'],
    ];

    /** Etapas do WMS definidas pelas regras, da mais adiantada para a menos (ordem em que as regras são testadas). */
    public const CONFIGURAVEIS = ['aguardando_faturamento', 'logistica', 'conferencia', 'separacao'];

    /**
     * Campos do WMS/expedição que a procedure sp_sincronizar_pedidos_venda copia da SC9010 para "extras" sempre que
     * existirem (além dos usados nas regras ativas). Mudou aqui? Mude também database/procedures/sp_sincronizar_pedidos_venda.sql.
     */
    public const CAMPOS_WMS = ['C9_ORDSEP', 'C9_CARGA', 'C9_SEQCAR', 'C9_SERVIC', 'C9_STSERV', 'C9_ENDPAD'];

    /** Bloqueio da SC9 que significa "já faturado" (não é bloqueio). */
    private const BLOQUEIO_FATURADO = '10';

    private const FOLGA = 0.00001;

    /** @var array<string, list<array{campo: string, operador: string, valores: list<string>}>>|null */
    private ?array $regras = null;

    public static function ordem(?string $etapa): int
    {
        $i = array_search($etapa, array_keys(self::ETAPAS), true);

        return $i === false ? PHP_INT_MAX : $i;
    }

    /** @return array<string, string> etapa => rótulo */
    public static function rotulos(): array
    {
        return array_map(fn ($e) => $e['rotulo'], self::ETAPAS);
    }

    // ------------------------------------------------------------------ regra (pura)

    /**
     * Regras ativas agrupadas por etapa, no formato usado por calcular().
     *
     * @return array<string, list<array{campo: string, operador: string, valores: list<string>}>>
     */
    public function regras(): array
    {
        return $this->regras ??= self::agruparRegras(RegraEtapaPedido::ativas()->orderBy('id')->get());
    }

    /** Esquece as regras lidas (depois de mudar a configuração). */
    public function esquecerRegras(): void
    {
        $this->regras = null;
    }

    /** @param  iterable<RegraEtapaPedido>  $regras */
    public static function agruparRegras(iterable $regras): array
    {
        $grupos = [];
        foreach ($regras as $r) {
            if (! in_array($r->etapa, self::CONFIGURAVEIS, true)) {
                continue;
            }
            $grupos[$r->etapa][] = ['campo' => strtoupper(trim((string) $r->campo)), 'operador' => (string) $r->operador,
                'valores' => $r->listaValores()];
        }

        return $grupos;
    }

    /** A regra vale para este valor do campo? (sem espaços nas pontas; maiúsculas e minúsculas tanto faz) */
    public static function regraVale(array $regra, ?string $valor): bool
    {
        $valor = mb_strtoupper(trim((string) $valor));
        $valores = array_map(fn ($v) => mb_strtoupper(trim((string) $v)), $regra['valores'] ?? []);

        return match ($regra['operador'] ?? 'igual') {
            'preenchido' => $valor !== '',
            'vazio' => $valor === '',
            'diferente' => $valores !== [] && ! in_array($valor, $valores, true),
            'comeca' => $valor !== '' && collect($valores)->contains(fn ($v) => $v !== '' && str_starts_with($valor, $v)),
            default => in_array($valor, $valores, true),
        };
    }

    /**
     * Etapa de UMA liberação em aberto.
     *
     * @param  array<string, mixed>  $liberacao  campos da SC9 em MAIÚSCULAS (C9_BLEST, C9_BLCRED, extras do WMS...)
     */
    public static function etapaDaLiberacao(array $liberacao, array $regras): string
    {
        foreach (['C9_BLCRED', 'C9_BLEST'] as $bloqueio) {
            $b = trim((string) ($liberacao[$bloqueio] ?? ''));
            if ($b !== '' && $b !== self::BLOQUEIO_FATURADO) {
                return self::CARTEIRA;
            }
        }
        foreach (self::CONFIGURAVEIS as $etapa) {
            foreach ($regras[$etapa] ?? [] as $regra) {
                if (self::regraVale($regra, $liberacao[$regra['campo']] ?? null)) {
                    return $etapa;
                }
            }
        }

        return self::EXPEDICAO;
    }

    /**
     * Calcula etapa, valores e datas de um pedido.
     *
     * @param  list<array<string, mixed>>  $itens  SC6 em MAIÚSCULAS (C6_ITEM, C6_QTDVEN, C6_QTDENT, C6_PRCVEN, C6_VALOR,
     *                                             C6_ENTREG, C6_DATFAT, C6_BLQ)
     * @param  list<array<string, mixed>>  $liberacoes  SC9 em MAIÚSCULAS (C9_ITEM, C9_QTDLIB, C9_DATALIB, C9_BLEST,
     *                                                  C9_BLCRED, C9_NFISCAL + extras)
     * @return array{etapa: ?string, parcial: bool, valor_total: float, valor_saldo: float, valor_faturado: float,
     *               entrega_prevista: string, faturado_em: string, ultima_liberacao: string}
     */
    public static function calcular(array $itens, array $liberacoes, array $regras): array
    {
        $porItem = [];
        $ultimaLiberacao = '';
        foreach ($liberacoes as $l) {
            if (trim((string) ($l['C9_NFISCAL'] ?? '')) !== '') {
                continue; // já faturada
            }
            $porItem[trim((string) ($l['C9_ITEM'] ?? ''))][] = $l;
        }

        $total = $saldoValor = $faturado = 0.0;
        $entregas = $todasEntregas = $faturamentos = [];
        $etapa = null;
        $entregou = false;
        foreach ($itens as $i) {
            $qtdVen = (float) ($i['C6_QTDVEN'] ?? 0);
            $qtdEnt = (float) ($i['C6_QTDENT'] ?? 0);
            $preco = (float) ($i['C6_PRCVEN'] ?? 0);
            $total += (float) ($i['C6_VALOR'] ?? 0);
            $faturado += $qtdEnt * $preco;
            if ($qtdEnt > self::FOLGA) {
                $entregou = true;
            }
            $datfat = trim((string) ($i['C6_DATFAT'] ?? ''));
            if (preg_match('/^\d{8}$/', $datfat)) {
                $faturamentos[] = $datfat;
            }
            $entrega = trim((string) ($i['C6_ENTREG'] ?? ''));
            if (preg_match('/^\d{8}$/', $entrega)) {
                $todasEntregas[] = $entrega;
            }

            $saldo = $qtdVen - $qtdEnt;
            $residuo = strtoupper(trim((string) ($i['C6_BLQ'] ?? ''))) === 'R';
            if ($saldo <= self::FOLGA || $residuo) {
                continue; // item encerrado
            }
            $saldoValor += $saldo * $preco;
            if (preg_match('/^\d{8}$/', $entrega)) {
                $entregas[] = $entrega;
            }

            // Etapa do item: a mais atrasada das liberações em aberto; saldo sem liberação = carteira.
            $liberado = 0.0;
            $etapaItem = null;
            foreach ($porItem[trim((string) ($i['C6_ITEM'] ?? ''))] ?? [] as $l) {
                $liberado += (float) ($l['C9_QTDLIB'] ?? 0);
                $e = self::etapaDaLiberacao($l, $regras);
                $etapaItem = $etapaItem === null || self::ordem($e) < self::ordem($etapaItem) ? $e : $etapaItem;
                $datalib = trim((string) ($l['C9_DATALIB'] ?? ''));
                if (preg_match('/^\d{8}$/', $datalib) && $datalib > $ultimaLiberacao) {
                    $ultimaLiberacao = $datalib;
                }
            }
            if ($etapaItem === null || $liberado + self::FOLGA < $saldo) {
                $etapaItem = self::CARTEIRA;
            }
            $etapa = $etapa === null || self::ordem($etapaItem) < self::ordem($etapa) ? $etapaItem : $etapa;
        }

        if ($etapa === null && $entregou) {
            $etapa = self::FATURADO;
        }

        return [
            'etapa' => $etapa,
            'parcial' => $etapa !== null && $etapa !== self::FATURADO && $entregou,
            'valor_total' => round($total, 2),
            'valor_saldo' => round($saldoValor, 2),
            'valor_faturado' => round($faturado, 2),
            'entrega_prevista' => $entregas ? min($entregas) : ($todasEntregas ? max($todasEntregas) : ''),
            'faturado_em' => $faturamentos ? max($faturamentos) : '',
            'ultima_liberacao' => $ultimaLiberacao,
        ];
    }

    /**
     * Desde quando o pedido está na etapa (UTC). Faturado = dia do faturamento; em expedição = dia da última liberação;
     * etapa nova = agora; primeiro cálculo = emissão (carteira) ou liberação (WMS).
     */
    public static function desde(array $calculo, ?string $etapaAnterior, ?CarbonImmutable $desdeAnterior, string $emissao, CarbonImmutable $agora): ?CarbonImmutable
    {
        $etapa = $calculo['etapa'];
        if ($etapa === null) {
            return null;
        }
        $dia = fn (string $d) => preg_match('/^\d{8}$/', $d)
            ? CarbonImmutable::createFromFormat('!Ymd', $d, (string) config('oee.fuso_exibicao', 'America/Sao_Paulo'))->utc() : null;
        if ($etapa === self::FATURADO) {
            return $dia($calculo['faturado_em']) ?? ($etapa === $etapaAnterior ? $desdeAnterior : null) ?? $agora;
        }
        if ($etapa === self::EXPEDICAO && $dia($calculo['ultima_liberacao'])) {
            return $dia($calculo['ultima_liberacao']);
        }
        if ($etapa === $etapaAnterior && $desdeAnterior) {
            return $desdeAnterior;
        }
        if ($etapaAnterior === null && $desdeAnterior === null) {
            $base = $etapa === self::CARTEIRA ? $dia($emissao) : ($dia($calculo['ultima_liberacao']) ?? $dia($emissao));

            return $base ?? $agora;
        }

        return $agora;
    }

    // ------------------------------------------------------------------ gravação

    /**
     * Depois da procedure sp_sincronizar_pedidos_venda (SincronizadorProtheus, item "depois"): recalcula os pedidos que
     * a procedure mexeu (item ou liberação com sincronizado_em desde o início da execução), os que ainda não têm etapa
     * e todos os em aberto. Só lê as tabelas do portal. Devolve o resumo para a mensagem da sincronização.
     */
    public function depoisDaSincronizacao(Sincronizacao $registro): string
    {
        $inicio = $registro->iniciado_em ?? now()->subMinutes(10);
        $chaves = $this->chavesAbertasOuNovas();
        foreach ([['pedidos_venda', 'c5_filial', 'c5_num'], ['pedidos_venda_liberacoes', 'c9_filial', 'c9_pedido']] as [$tabela, $filial, $num]) {
            DB::table($tabela)->where('sincronizado_em', '>=', $inicio)->select([$filial, $num])->distinct()->orderBy($num)
                ->chunk(1000, function ($linhas) use (&$chaves, $filial, $num) {
                    foreach ($linhas as $l) {
                        $chaves[rtrim((string) $l->{$filial}).'|'.rtrim((string) $l->{$num})] = true;
                    }
                });
        }
        $alterados = count($this->recalcularChaves(array_keys($chaves)));

        return "Etapas recalculadas: {$alterados} ".($alterados === 1 ? 'pedido mudou' : 'pedidos mudaram').' de etapa, valor ou data.';
    }

    /** Recalcula os pedidos em aberto e os que ainda não têm etapa (ex.: depois de mudar as regras do WMS). */
    public function recalcularAbertos(): int
    {
        $this->esquecerRegras();

        return count($this->recalcularChaves(array_keys($this->chavesAbertasOuNovas())));
    }

    /** "filial|numero" dos pedidos em aberto (pedidos_venda_etapas) e dos que ainda não têm linha lá. @return array<string, true> */
    private function chavesAbertasOuNovas(): array
    {
        $chaves = [];
        PedidoVendaEtapa::query()->where(fn ($q) => $q->whereNull('etapa')->orWhere('etapa', '<>', self::FATURADO))
            ->select(['id', 'c5_filial', 'c5_num'])->chunkById(1000, function ($pedidos) use (&$chaves) {
                foreach ($pedidos as $p) {
                    $chaves[rtrim((string) $p->c5_filial).'|'.rtrim((string) $p->c5_num)] = true;
                }
            });
        DB::table('pedidos_venda as i')->select(['i.c5_filial', 'i.c5_num'])->distinct()
            ->whereNotExists(fn ($q) => $q->from('pedidos_venda_etapas as e')->whereColumn('e.c5_filial', 'i.c5_filial')->whereColumn('e.c5_num', 'i.c5_num'))
            ->orderBy('i.c5_num')->chunk(1000, function ($linhas) use (&$chaves) {
                foreach ($linhas as $l) {
                    $chaves[rtrim((string) $l->c5_filial).'|'.rtrim((string) $l->c5_num)] = true;
                }
            });

        return $chaves;
    }

    /**
     * Recalcula os pedidos destas chaves (filial|número) e grava em pedidos_venda_etapas o que mudou. Pedido sem itens
     * no portal (apagado pela procedure) fica sem etapa (some da tela). Devolve as chaves alteradas.
     *
     * @param  iterable<string>  $chaves  "filial|numero"
     * @return list<string>
     */
    public function recalcularChaves(iterable $chaves): array
    {
        $porNumero = [];
        foreach ($chaves as $chave) {
            [$filial, $numero] = array_pad(explode('|', (string) $chave, 2), 2, '');
            if (trim($numero) !== '') {
                $porNumero[rtrim($numero)][rtrim($filial)] = true;
            }
        }
        $regras = $this->regras();
        $agora = CarbonImmutable::now();
        $alterados = [];
        foreach (array_chunk(array_map('strval', array_keys($porNumero)), 500) as $numeros) {
            $chave = fn ($filial, $num) => rtrim((string) $filial).'|'.rtrim((string) $num);
            $itens = PedidoVenda::whereIn('c5_num', $numeros)->get()->groupBy(fn ($i) => $chave($i->c5_filial, $i->c5_num));
            $liberacoes = DB::table('pedidos_venda_liberacoes')->whereIn('c9_pedido', $numeros)->get()
                ->groupBy(fn ($l) => $chave($l->c9_filial, $l->c9_pedido));
            $atuais = PedidoVendaEtapa::whereIn('c5_num', $numeros)->get()->keyBy(fn ($e) => $chave($e->c5_filial, $e->c5_num));

            foreach ($numeros as $numero) {
                foreach (array_keys($porNumero[$numero]) as $filial) {
                    $k = $chave($filial, $numero);
                    $linhas = $itens[$k] ?? collect();
                    $atual = $atuais[$k] ?? null;
                    if ($linhas->isEmpty() && ! $atual) {
                        continue;
                    }
                    $novos = $this->linhaEtapa($linhas, $liberacoes[$k] ?? collect(), $regras, $atual, $agora);
                    if ($atual && ! $this->mudou($atual, $novos)) {
                        continue;
                    }
                    PedidoVendaEtapa::updateOrCreate(['c5_filial' => (string) $filial, 'c5_num' => (string) $numero], $novos + ['calculado_em' => $agora]);
                    $alterados[] = $k;
                }
            }
        }

        return $alterados;
    }

    /** Colunas de pedidos_venda_etapas para um pedido. */
    private function linhaEtapa(Collection $itens, Collection $liberacoes, array $regras, ?PedidoVendaEtapa $atual, CarbonImmutable $agora): array
    {
        $calculo = self::calcular(
            $itens->map(fn ($i) => ['C6_ITEM' => $i->c6_item, 'C6_QTDVEN' => $i->c6_qtdven, 'C6_QTDENT' => $i->c6_qtdent,
                'C6_PRCVEN' => $i->c6_prcven, 'C6_VALOR' => $i->c6_valor, 'C6_ENTREG' => $i->c6_entreg, 'C6_DATFAT' => $i->c6_datfat,
                'C6_BLQ' => $i->c6_blq])->values()->all(),
            $liberacoes->map(fn ($l) => array_change_key_case(json_decode((string) ($l->extras ?? ''), true) ?: [], CASE_UPPER)
                + ['C9_ITEM' => $l->c9_item, 'C9_QTDLIB' => $l->c9_qtdlib, 'C9_DATALIB' => $l->c9_datalib, 'C9_BLEST' => $l->c9_blest,
                    'C9_BLCRED' => $l->c9_blcred, 'C9_NFISCAL' => $l->c9_nfiscal])->values()->all(),
            $regras,
        );
        $cab = $itens->first();
        $emissao = (string) ($cab->c5_emissao ?? $atual?->c5_emissao ?? '');
        $desde = self::desde($calculo, $atual?->etapa, $atual?->etapa_desde ? CarbonImmutable::instance($atual->etapa_desde) : null, $emissao, $agora);

        return [
            'c5_cliente' => (string) ($cab->c5_cliente ?? $atual?->c5_cliente ?? ''),
            'c5_lojacli' => (string) ($cab->c5_lojacli ?? $atual?->c5_lojacli ?? ''),
            'cliente_nome' => (string) ($cab->cliente_nome ?? $atual?->cliente_nome ?? ''),
            'c5_emissao' => $emissao,
            'etapa' => $calculo['etapa'],
            'etapa_desde' => $desde?->format('Y-m-d H:i:s'),
            'parcial' => $calculo['parcial'],
            'valor_total' => $calculo['valor_total'],
            'valor_saldo' => $calculo['valor_saldo'],
            'valor_faturado' => $calculo['valor_faturado'],
            'entrega_prevista' => $calculo['entrega_prevista'],
            'faturado_em' => $calculo['faturado_em'],
        ];
    }

    private function mudou(PedidoVendaEtapa $atual, array $novos): bool
    {
        foreach ($novos as $campo => $valor) {
            $antes = $campo === 'etapa_desde' ? $atual->etapa_desde?->format('Y-m-d H:i:s') : $atual->{$campo};
            if (is_float($valor) ? abs((float) $antes - $valor) > 0.004 : (is_bool($valor) ? (bool) $antes !== $valor : trim((string) $antes) !== trim((string) $valor))) {
                return true;
            }
            if ($valor === null && $antes !== null) {
                return true;
            }
        }

        return false;
    }
}
