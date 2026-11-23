<?php

namespace App\Services\Aplicativo;

use App\Models\OrdemProducao;
use App\Services\CustoOrdemProducao;
use App\Services\EngenhariaOrdemProducao;
use App\Services\TiposMovimento;

/**
 * "Detalhes da OP" do aplicativo (operador, na tela da máquina): os mesmos dados das abas da OP no portal — OP e
 * custo, kardex (CustoOrdemProducao::kardex), empenhos, estrutura e roteiro (EngenhariaOrdemProducao::daOp) —
 * em JSON simples para o celular. Só leitura.
 */
class DetalhesOpAplicativo
{
    /** Movimentos do kardex mandados ao celular (os mais recentes; os totais do resumo usam todos). */
    public const MAX_KARDEX = 400;

    public function __construct(
        private CustoOrdemProducao $custo,
        private EngenhariaOrdemProducao $engenharia,
        private TiposMovimento $tipos,
    ) {}

    public function detalhes(OrdemProducao $op): array
    {
        $kardex = $this->custo->kardex($op);
        $engenharia = $this->engenharia->daOp($op);
        $produtos = $kardex['produtos']->merge($engenharia['produtosEngenharia']);
        $descricao = fn ($cod) => $produtos->get(rtrim((string) $cod))?->descricao();
        $um = fn ($cod) => ($p = $produtos->get(rtrim((string) $cod))) ? trim((string) $p->b1_um) : null;
        $resumo = $kardex['resumo'];
        $roteiro = $engenharia['roteiro'];
        $texto = fn ($v) => trim((string) $v) !== '' ? trim((string) $v) : null;

        $linhas = array_slice(array_reverse($kardex['linhas']), 0, self::MAX_KARDEX);

        return [
            'op' => [
                'id' => (int) $op->id,
                'numero' => $op->numero,
                'produto' => rtrim((string) $op->c2_produto),
                'descricao' => $descricao($op->c2_produto),
                'status' => $op->status,
                'status_rotulo' => $op->rotuloStatus(),
                'um' => $texto($op->c2_um),
                'quantidade' => (float) $op->c2_quant,
                'produzido' => $resumo->producaoQtd, // apontamentos de produção (PR) do kardex
                'produzido_protheus' => (float) $op->c2_quje,
                'quantidade_real' => (float) $op->quantidade_real, // peças do IoT
                'perda' => (float) $op->c2_perda,
                'emissao' => OrdemProducao::data($op->c2_emissao) ?: null,
                'previsao_inicio' => OrdemProducao::data($op->c2_datpri) ?: null,
                'previsao_fim' => OrdemProducao::data($op->c2_datprf) ?: null,
                'encerrada_em' => trim((string) $op->c2_datrf) !== '' ? OrdemProducao::data($op->c2_datrf) : null,
                'roteiro' => $roteiro['codigo'],
                'revisao' => $texto($op->c2_revisao),
                'armazem' => $texto($op->c2_local),
                'centro_custo' => $texto($op->c2_cc),
                'tipo' => ['F' => 'Firme', 'P' => 'Prevista'][trim((string) $op->c2_tpop)] ?? $texto($op->c2_tpop),
                'observacao' => $texto($op->c2_obs),
            ],
            'custo' => [
                'protheus' => $resumo->custoProtheus(),
                'meta' => $resumo->meta,
                'simulado' => $resumo->custoSimulado(),
                'divergencia' => $resumo->divergencia(), // fração (0,1 = 10%)
                'baixas' => $resumo->consumoValor,
                'notas' => $resumo->notaValor,
                'devolucoes' => $resumo->devolucaoValor,
                'componentes' => array_map(fn ($c) => [
                    'produto' => $c['produto'], 'descricao' => $descricao($c['produto']), 'um' => $texto($c['um']),
                    'quantidade' => $c['quantidade'], 'valor' => $c['valor'],
                    'por_peca' => $resumo->producaoQtd > 0 ? $c['valor'] / $resumo->producaoQtd : null,
                ], array_slice($kardex['componentes'], 0, 50)),
            ],
            'kardex' => [
                'total' => count($kardex['linhas']),
                'movimentos' => array_map(fn ($m) => [
                    'data' => OrdemProducao::data($m->emissao) ?: null,
                    'tipo' => $m->tipo, // producao | consumo | devolucao | nota_fiscal
                    'rotulo' => $this->tipos->rotulo($m->tipo),
                    'descricao' => trim(($m->tipo !== 'nota_fiscal' ? trim((string) $m->cf).' · ' : '').$m->descricao_cf),
                    'produto' => rtrim((string) $m->produto),
                    'produto_descricao' => $descricao($m->produto),
                    'documento' => trim(trim((string) $m->documento).(trim((string) $m->serie) !== '' ? ' série '.trim((string) $m->serie) : '')),
                    'entrada' => in_array($m->tipo, ['producao', 'devolucao'], true),
                    'quantidade' => (float) $m->quantidade,
                    'um' => $texto($m->um),
                    'valor' => (float) $m->custo,
                    'produzido_acumulado' => $m->produzido_acumulado,
                    'custo_peca_acumulado' => $m->unitario_acumulado,
                ], $linhas),
            ],
            'empenhos' => $engenharia['empenhos']->map(fn ($e) => [
                'componente' => rtrim((string) $e->d4_cod),
                'descricao' => $descricao($e->d4_cod),
                'um' => $um($e->d4_cod),
                'trt' => $texto($e->d4_trt),
                'armazem' => $texto($e->d4_local),
                'lote' => $texto($e->d4_lotectl),
                'data' => OrdemProducao::data($e->d4_data) ?: null,
                'empenhado' => (float) $e->d4_qtdeori,
                'baixado' => $e->baixado(),
                'saldo' => (float) $e->d4_quant,
                'atendido' => $e->atendido(), // fração
            ])->values()->all(),
            'estrutura' => $engenharia['estrutura']->map(fn ($g) => [
                'trt' => $texto($g->g1_trt),
                'componente' => rtrim((string) $g->g1_comp),
                'descricao' => $descricao($g->g1_comp),
                'um' => $um($g->g1_comp),
                'por_peca' => (float) $g->por_peca,
                'perda' => (float) $g->g1_perda,
                'fixa' => strtoupper(trim((string) $g->g1_fixvar)) === 'F',
                'necessidade' => (float) $g->necessidade,
                'empenhado' => $g->empenhado === null ? null : (float) $g->empenhado,
                'intermediario' => (bool) $g->tem_estrutura,
            ])->values()->all(),
            'roteiro' => [
                'codigo' => $roteiro['codigo'],
                'operacoes' => $roteiro['operacoes']->map(function ($o) use ($roteiro, $op) {
                    $maquina = $roteiro['maquinas'][rtrim((string) $o->g2_ctrab)] ?? null;
                    $ferramenta = $roteiro['ferramentas'][rtrim((string) $o->g2_ferram)] ?? null;

                    return [
                        'operacao' => trim((string) $o->g2_operac),
                        'descricao' => trim((string) $o->g2_descri),
                        'maquina' => rtrim((string) $o->g2_ctrab) ?: null,
                        'maquina_nome' => $maquina?->nome,
                        'com_esta_op' => $maquina !== null && (int) $maquina->ordem_producao_id === (int) $op->id,
                        'recurso' => trim((string) $o->g2_recurso) ?: null,
                        'ferramenta' => rtrim((string) $o->g2_ferram) ?: null,
                        'ferramenta_descricao' => $ferramenta ? trim((string) $ferramenta->h4_descri) : null,
                        'cavidades' => $ferramenta ? (int) $ferramenta->h4_qtdcav : null,
                        'cavidades_ativas' => $ferramenta ? (int) $ferramenta->h4_qtdcavativas : null,
                        'tipo' => $o->rotuloTipo(),
                        'setup' => (float) $o->g2_setup,
                        'tempo_padrao' => (float) $o->g2_tempad,
                        'lote_padrao' => (float) $o->g2_lotepad,
                        'mao_de_obra' => (float) $o->g2_maoobra,
                    ];
                })->values()->all(),
            ],
        ];
    }
}
