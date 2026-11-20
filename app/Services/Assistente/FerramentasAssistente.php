<?php

namespace App\Services\Assistente;

use App\Models\ApontamentoEstado;
use App\Models\EmpenhoOp;
use App\Models\Ferramenta;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\ProdutoProtheus;
use App\Models\Usuario;
use App\Services\CustoOrdemProducao;
use App\Services\IndicadoresOee;
use App\Services\IndicadoresPeriodo;
use App\Services\ResumoCustoOp;
use App\Support\AcessoOs;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Consultas (ferramentas) que a Claude pode usar no "Pergunte para Alumbra". Todas são SÓ LEITURA e rodam como o
 * usuário logado: cada uma exige a permissão da tela correspondente (Usuario::pode) e as consultas passam pelos mesmos
 * filtros das telas (escopo de unidade dos cadastros, AcessoOs para SS/OS). A Claude só recebe as ferramentas que o
 * usuário pode usar, e executar() confere a permissão de novo (nunca confia no pedido).
 */
class FerramentasAssistente
{
    public function __construct(
        private IndicadoresOee $indicadores,
        private CustoOrdemProducao $custos,
    ) {}

    /**
     * Definições no formato da API da Claude (só as que o usuário pode usar).
     *
     * @return list<array{name: string, description: string, input_schema: array}>
     */
    public function definicoes(Usuario $usuario): array
    {
        $lista = [];
        foreach ($this->catalogo() as $nome => $f) {
            if ($this->permitida($usuario, $f['permissoes'])) {
                $lista[] = ['name' => $nome, 'description' => $f['descricao'], 'input_schema' => $f['esquema']];
            }
        }

        return $lista;
    }

    /** Nomes das ferramentas que o usuário pode usar. @return list<string> */
    public function disponiveis(Usuario $usuario): array
    {
        return array_column($this->definicoes($usuario), 'name');
    }

    /**
     * Executa uma ferramenta pedida pela Claude e devolve o resultado (JSON). Erros de uso viram ErroFerramenta (a
     * Claude recebe a mensagem e pode corrigir o pedido).
     */
    public function executar(Usuario $usuario, string $nome, array $entrada): array
    {
        $f = $this->catalogo()[$nome] ?? null;
        if ($f === null) {
            throw new ErroFerramenta("Consulta desconhecida: {$nome}.");
        }
        if (! $this->permitida($usuario, $f['permissoes'])) {
            throw new ErroFerramenta('O usuário não tem permissão para esta consulta.');
        }

        return $this->{$f['metodo']}($usuario, $entrada);
    }

    /** Rótulo amigável para mostrar na tela enquanto a consulta roda. */
    public function rotulo(string $nome): string
    {
        return $this->catalogo()[$nome]['rotulo'] ?? 'Consultando o portal';
    }

    private function permitida(Usuario $usuario, array $permissoes): bool
    {
        foreach ($permissoes as $p) {
            if ($usuario->pode($p)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ catálogo

    /** @return array<string, array{metodo: string, rotulo: string, permissoes: list<string>, descricao: string, esquema: array}> */
    private function catalogo(): array
    {
        $data = fn (string $d) => ['type' => 'string', 'description' => $d.' (AAAA-MM-DD, fuso local). Padrão: hoje.'];
        $periodo = ['de' => $data('Primeiro dia'), 'ate' => $data('Último dia (inclusive)')];
        $idsMaquinas = ['type' => 'array', 'items' => ['type' => 'integer'],
            'description' => 'Ids das máquinas (de listar_maquinas). Vazio = todas as que o usuário vê.'];
        $limite = ['type' => 'integer', 'description' => 'Máximo de linhas (padrão 50, máx. '.$this->maxLinhas().').'];
        $objeto = fn (array $props) => ['type' => 'object', 'properties' => $props ?: new \stdClass, 'additionalProperties' => false];
        $os = ['os_solicitar', 'os_aprovar', 'solicitacoes_manutencao', 'os_almoxarifado', 'os_painel', 'os_relatorios'];

        return [
            'listar_maquinas' => [
                'metodo' => 'listarMaquinas', 'rotulo' => 'Consultando as máquinas',
                'permissoes' => ['dashboard_maquina', 'timeline', 'apontamentos'],
                'descricao' => 'Lista as máquinas que o usuário vê: id, código, nome, unidade, planta, linha, se está ativa, '
                    .'situação atual (produzindo/parada, desde quando e legenda) e a OP atual. Use para achar o id de uma '
                    .'máquina pelo nome ou código.',
                'esquema' => $objeto([
                    'busca' => ['type' => 'string', 'description' => 'Parte do código, nome, linha ou planta.'],
                    'situacao' => ['type' => 'string', 'enum' => ['produzindo', 'parada'], 'description' => 'Filtra pela situação atual.'],
                    'incluir_inativas' => ['type' => 'boolean', 'description' => 'Inclui máquinas inativas (padrão: não).'],
                ]),
            ],
            'indicadores_oee' => [
                'metodo' => 'indicadoresOee', 'rotulo' => 'Calculando o OEE',
                'permissoes' => ['dashboard_maquina'],
                'descricao' => 'OEE, disponibilidade, performance e qualidade (em %), tempos (min), peças e Pareto de paradas '
                    .'num período, por máquina e no total (somando tempos e peças, nunca média de porcentagens). Mesma regra '
                    .'do Dashboard por máquina. Para evolução, chame por dia/semana.',
                'esquema' => $objeto($periodo + ['maquina_ids' => $idsMaquinas]),
            ],
            'apontamentos_reais' => [
                'metodo' => 'apontamentosReais', 'rotulo' => 'Lendo os apontamentos reais',
                'permissoes' => ['apontamentos', 'timeline'],
                'descricao' => 'Apontamentos reais (estados das máquinas da Timeline: produzindo, paradas, setup...) num '
                    .'período, cortados no período (em aberto contam até agora). "agrupar": lista (cada apontamento), '
                    .'legenda, maquina_legenda, maquina, dia ou op — devolve minutos e quantidade por grupo.',
                'esquema' => $objeto($periodo + [
                    'maquina_ids' => $idsMaquinas,
                    'agrupar' => ['type' => 'string', 'enum' => ['lista', 'legenda', 'maquina_legenda', 'maquina', 'dia', 'op'],
                        'description' => 'Padrão: maquina_legenda.'],
                    'tipo_legenda' => ['type' => 'string', 'enum' => ['produzindo', 'parada', 'planejada', 'desligada'],
                        'description' => 'Só apontamentos de legendas deste tipo.'],
                    'limite' => $limite,
                ]),
            ],
            'ordens_producao' => [
                'metodo' => 'ordensProducao', 'rotulo' => 'Consultando as ordens de produção',
                'permissoes' => ['ordens_producao'],
                'descricao' => 'Ordens de produção (cópia da SC2 do Protheus): número, produto e descrição, quantidade, produzido, '
                    .'previsão, status (aberta, andamento, parada, fechada). Com incluir_custo, custo Protheus, custo simulado (OEE: baixas rateadas nas produções) × custo '
                    .'meta (SB9) e divergência. Devolve também o total por status do filtro.',
                'esquema' => $objeto([
                    'numero' => ['type' => 'string', 'description' => 'Número da OP (ou o começo dele).'],
                    'produto' => ['type' => 'string', 'description' => 'Código do produto ou parte da descrição.'],
                    'status' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(OrdemProducao::STATUS)],
                        'description' => 'Padrão: aberta, andamento e parada.'],
                    'previsao_de' => ['type' => 'string', 'description' => 'Previsão de término a partir de (AAAA-MM-DD).'],
                    'previsao_ate' => ['type' => 'string', 'description' => 'Previsão de término até (AAAA-MM-DD).'],
                    'incluir_custo' => ['type' => 'boolean', 'description' => 'Inclui o custo (mais lento).'],
                    'limite' => $limite,
                ]),
            ],
            'detalhe_op' => [
                'metodo' => 'detalheOp', 'rotulo' => 'Abrindo a ordem de produção',
                'permissoes' => ['ordens_producao'],
                'descricao' => 'Detalhe de UMA OP: dados, custo (produção, baixas, devoluções, notas, custo Protheus, custo simulado OEE × '
                    .'meta), componentes que mais custaram, máquinas que estão com a OP e minutos apontados por legenda.',
                'esquema' => ['type' => 'object', 'properties' => ['numero' => ['type' => 'string', 'description' => 'Número da OP.']],
                    'required' => ['numero'], 'additionalProperties' => false],
            ],
            'empenhos_op' => [
                'metodo' => 'empenhosOp', 'rotulo' => 'Consultando os empenhos',
                'permissoes' => ['empenhos_op'],
                'descricao' => 'Empenhos (SD4: materiais reservados para as OPs): componente, quantidade original e saldo.',
                'esquema' => $objeto([
                    'op' => ['type' => 'string', 'description' => 'Número da OP.'],
                    'componente' => ['type' => 'string', 'description' => 'Código do componente.'],
                    'so_com_saldo' => ['type' => 'boolean', 'description' => 'Só empenhos com saldo (padrão: sim).'],
                    'limite' => $limite,
                ]),
            ],
            'engenharia_produto' => [
                'metodo' => 'engenhariaProduto', 'rotulo' => 'Consultando estrutura e roteiro',
                'permissoes' => ['estruturas', 'roteiros'],
                'descricao' => 'Estrutura (SG1: componentes e quantidades) e roteiro (SG2: operações, máquina/centro de '
                    .'trabalho, ferramenta, tempos) de um produto, conforme as permissões do usuário.',
                'esquema' => ['type' => 'object', 'properties' => ['produto' => ['type' => 'string', 'description' => 'Código do produto.']],
                    'required' => ['produto'], 'additionalProperties' => false],
            ],
            'ferramentas_moldes' => [
                'metodo' => 'ferramentasMoldes', 'rotulo' => 'Consultando as ferramentas',
                'permissoes' => ['ferramentas'],
                'descricao' => 'Ferramentas/moldes (SH4): código, descrição, cavidades e cavidades ativas, vida útil.',
                'esquema' => $objeto(['busca' => ['type' => 'string', 'description' => 'Parte do código ou da descrição.'], 'limite' => $limite]),
            ],
            'ordens_servico' => [
                'metodo' => 'ordensServico', 'rotulo' => 'Consultando as ordens de serviço',
                'permissoes' => $os,
                'descricao' => 'Ordens de serviço (OS) ou solicitações de serviço (SS) de manutenção que o usuário pode ver. '
                    .'Filtros por status, período de abertura e máquina. "agrupar": lista, status, tipo, prioridade, maquina, '
                    .'area ou unidade (devolve quantidade e, nas OS, minutos apontados).',
                'esquema' => $objeto([
                    'registro' => ['type' => 'string', 'enum' => ['os', 'ss'], 'description' => 'OS (padrão) ou SS.'],
                    'status' => ['type' => 'array', 'items' => ['type' => 'string'],
                        'description' => 'OS: '.implode(', ', array_keys(OrdemServico::STATUS)).'. SS: '.implode(', ', array_keys(SolicitacaoServico::STATUS)).'.'],
                    'de' => ['type' => 'string', 'description' => 'Abertas a partir de (AAAA-MM-DD).'],
                    'ate' => ['type' => 'string', 'description' => 'Abertas até (AAAA-MM-DD, inclusive).'],
                    'maquina_ids' => $idsMaquinas,
                    'agrupar' => ['type' => 'string', 'enum' => ['lista', 'status', 'tipo', 'prioridade', 'maquina', 'area', 'unidade']],
                    'limite' => $limite,
                ]),
            ],
        ];
    }

    // ------------------------------------------------------------------ ferramentas

    private function listarMaquinas(Usuario $u, array $e): array
    {
        $busca = trim((string) ($e['busca'] ?? ''));
        $maquinas = Maquina::with(['linha.planta', 'situacaoLegenda', 'ordemProducao'])
            ->when(empty($e['incluir_inativas']), fn ($q) => $q->where('ativo', true))
            ->when(in_array($e['situacao'] ?? null, ['produzindo', 'parada'], true), fn ($q) => $q->where('situacao', $e['situacao']))
            ->orderBy('nome')->get();
        if ($busca !== '') {
            $maquinas = $maquinas->filter(fn (Maquina $m) => str_contains(mb_strtolower($m->codigo.' '.$m->nome.' '.$m->linha?->nome.' '.$m->linha?->planta?->nome), mb_strtolower($busca)));
        }

        return $this->limitar($maquinas->values()->map(fn (Maquina $m) => [
            'id' => $m->id,
            'codigo' => $m->codigo,
            'nome' => $m->nome,
            'unidade' => $m->rotuloUnidade(),
            'planta' => $m->linha?->planta?->nome,
            'linha' => $m->linha?->nome,
            'ativa' => (bool) $m->ativo,
            'situacao' => $m->situacao ? $m->rotuloSituacao() : null,
            'situacao_desde' => $this->dataHora($m->situacao_desde),
            'legenda_atual' => $m->situacaoLegenda?->descricao,
            'op_atual' => $m->ordemProducao?->numero,
            'centro_custo' => $m->centro_custo,
            'ciclo_ideal_s' => $m->ciclo_ideal_s,
        ])->all(), 'maquinas');
    }

    private function indicadoresOee(Usuario $u, array $e): array
    {
        [$inicio, $fim, $rotulo] = $this->periodo($e['de'] ?? null, $e['ate'] ?? null);
        $maquinas = $this->maquinasVisiveis($e['maquina_ids'] ?? []);
        $por = $maquinas->isEmpty() ? [] : $this->indicadores->porMaquina($inicio, $fim, $maquinas->modelKeys());

        $linhas = [];
        foreach ($por as $id => $ind) {
            $m = $maquinas->firstWhere('id', $id);
            $linhas[] = ['maquina_id' => $id, 'maquina' => $m ? "{$m->codigo} - {$m->nome}" : (string) $id] + $this->numerosOee($ind, false);
        }
        usort($linhas, fn ($a, $b) => ($b['oee_pct'] ?? -1) <=> ($a['oee_pct'] ?? -1));
        $geral = IndicadoresPeriodo::somar($por);

        return [
            'periodo' => $rotulo,
            'maquinas_consideradas' => $maquinas->count(),
            'total' => $this->numerosOee($geral, true),
            'por_maquina' => $linhas,
            'observacao' => $linhas === [] ? 'Sem apontamentos de produção no período para essas máquinas.' : null,
        ];
    }

    private function apontamentosReais(Usuario $u, array $e): array
    {
        [$inicio, $fim, $rotulo] = $this->periodo($e['de'] ?? null, $e['ate'] ?? null);
        $agrupar = $e['agrupar'] ?? 'maquina_legenda';
        $ids = array_filter(array_map('intval', $e['maquina_ids'] ?? []));
        $agora = CarbonImmutable::now();
        $fimEfetivo = $fim->lessThan($agora) ? $fim : $agora;

        $apontamentos = ApontamentoEstado::with(['maquina:id,codigo,nome', 'legenda:id,codigo,descricao,tipo', 'ordemProducao:id,numero'])
            ->whereHas('maquina') // só das máquinas que o usuário vê (unidade)
            ->when($ids, fn ($q) => $q->whereIn('maquina_id', $ids))
            ->when(isset($e['tipo_legenda']), fn ($q) => $q->whereHas('legenda', fn ($l) => $l->where('tipo', $e['tipo_legenda'])))
            ->tocando($inicio, $fim)
            ->orderBy('inicio')
            ->get();

        // Cortado no período; só o em aberto conta até agora (o encerrado vale até o fim dele).
        $minutos = function (ApontamentoEstado $a) use ($inicio, $fim, $fimEfetivo): float {
            $ini = $a->inicio->greaterThan($inicio) ? $a->inicio : $inicio;
            $fimA = $a->fim === null ? $fimEfetivo : ($a->fim->lessThan($fim) ? $a->fim : $fim);

            return max(0, ($fimA->getTimestamp() - $ini->getTimestamp()) / 60);
        };
        $maquina = fn (ApontamentoEstado $a) => "{$a->maquina?->codigo} - {$a->maquina?->nome}";
        $legenda = fn (ApontamentoEstado $a) => $a->legenda?->descricao ?? 'Sem legenda';

        if ($agrupar === 'lista') {
            return ['periodo' => $rotulo, 'total' => $apontamentos->count()] + $this->limitar($apontamentos->map(fn ($a) => [
                'maquina' => $maquina($a),
                'legenda' => $legenda($a),
                'tipo' => $a->legenda?->rotuloTipo(),
                'inicio' => $this->dataHora($a->inicio),
                'fim' => $a->fim ? $this->dataHora($a->fim) : 'em aberto',
                'minutos_no_periodo' => round($minutos($a), 1),
                'op' => $a->ordemProducao?->numero,
                'observacao' => $a->observacao,
            ])->all(), 'apontamentos', $e['limite'] ?? null);
        }

        $chave = match ($agrupar) {
            'legenda' => fn ($a) => [$legenda($a)],
            'maquina' => fn ($a) => [$maquina($a)],
            'dia' => fn ($a) => [$a->inicio->copy()->setTimezone($this->fuso())->format('d/m/Y')],
            'op' => fn ($a) => [$a->ordemProducao?->numero ?? 'Sem OP'],
            default => fn ($a) => [$maquina($a), $legenda($a)],
        };
        $nomes = match ($agrupar) {
            'legenda' => ['legenda'], 'maquina' => ['maquina'], 'dia' => ['dia'], 'op' => ['op'], default => ['maquina', 'legenda'],
        };
        $grupos = [];
        foreach ($apontamentos as $a) {
            $k = $chave($a);
            $id = implode('|', $k);
            $grupos[$id] ??= array_combine($nomes, $k) + ['tipo' => $agrupar === 'legenda' || $agrupar === 'maquina_legenda' ? $a->legenda?->rotuloTipo() : null, 'minutos' => 0.0, 'apontamentos' => 0];
            $grupos[$id]['minutos'] += $minutos($a);
            $grupos[$id]['apontamentos']++;
        }
        $grupos = array_values(array_map(function ($g) {
            $g['minutos'] = round($g['minutos'], 1);
            $g['horas'] = round($g['minutos'] / 60, 2);

            return array_filter($g, fn ($v) => $v !== null);
        }, $grupos));
        if ($agrupar !== 'dia') {
            usort($grupos, fn ($a, $b) => $b['minutos'] <=> $a['minutos']);
        }

        return ['periodo' => $rotulo, 'agrupado_por' => $agrupar, 'total_apontamentos' => $apontamentos->count()]
            + $this->limitar($grupos, 'grupos', $e['limite'] ?? null);
    }

    private function ordensProducao(Usuario $u, array $e): array
    {
        $status = array_values(array_intersect($e['status'] ?? ['aberta', 'andamento', 'parada'], array_keys(OrdemProducao::STATUS)));
        $consulta = OrdemProducao::query()
            ->where('d_e_l_e_t_', '<>', '*')
            ->when($status, fn ($q) => $q->whereIn('status', $status))
            ->when(trim((string) ($e['numero'] ?? '')) !== '', fn ($q) => $q->where('numero', 'like', trim($e['numero']).'%'))
            ->when($this->dataProtheus($e['previsao_de'] ?? null), fn ($q, $d) => $q->where('c2_datprf', '>=', $d))
            ->when($this->dataProtheus($e['previsao_ate'] ?? null), fn ($q, $d) => $q->where('c2_datprf', '<=', $d));
        $produto = trim((string) ($e['produto'] ?? ''));
        if ($produto !== '') {
            $consulta->where(fn ($q) => $q->where('c2_produto', 'like', $produto.'%')
                ->orWhereIn('c2_produto', ProdutoProtheus::where('b1_desc', 'like', '%'.$produto.'%')->select('b1_cod')));
        }

        $porStatus = (clone $consulta)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            ->mapWithKeys(fn ($t, $s) => [OrdemProducao::STATUS[$s] ?? $s => (int) $t])->all();
        $limite = $this->limite($e['limite'] ?? null);
        $ops = $consulta->orderByRaw("CASE status WHEN 'parada' THEN 0 WHEN 'andamento' THEN 1 WHEN 'aberta' THEN 2 ELSE 3 END")
            ->orderBy('c2_datprf')->orderBy('numero')->limit($limite + 1)->get();
        $produtos = $this->custos->produtos($ops->pluck('c2_produto'));
        $resumos = ! empty($e['incluir_custo']) ? $this->custos->resumos($ops->take($limite)) : [];

        $linhas = $ops->take($limite)->map(function (OrdemProducao $op) use ($produtos, $resumos) {
            $linha = $this->dadosOp($op, $produtos[rtrim($op->c2_produto)] ?? null);
            if (isset($resumos[$op->id])) {
                $linha += $this->numerosCusto($resumos[$op->id]);
            }

            return $linha;
        })->all();

        return ['total_por_status' => $porStatus, 'ops' => $linhas, 'mostrando' => count($linhas),
            'aviso' => $ops->count() > $limite ? "Há mais OPs que o limite de {$limite}; refine o filtro ou peça um resumo." : null];
    }

    private function detalheOp(Usuario $u, array $e): array
    {
        $numero = trim((string) ($e['numero'] ?? ''));
        $op = OrdemProducao::where('numero', $numero)->orderByRaw("CASE WHEN d_e_l_e_t_ = '*' THEN 1 ELSE 0 END")->first()
            ?? OrdemProducao::where('numero', 'like', $numero.'%')->orderBy('numero')->first();
        if ($op === null) {
            throw new ErroFerramenta("OP {$numero} não encontrada.");
        }

        $kardex = $this->custos->kardex($op);
        $produtos = $kardex['produtos'];
        $resumo = $kardex['resumo'];
        $apontado = ApontamentoEstado::with('legenda:id,descricao')->whereHas('maquina')->where('ordem_producao_id', $op->id)->get()
            ->groupBy(fn ($a) => $a->legenda?->descricao ?? 'Sem legenda')
            ->map(fn ($g) => round($g->sum(fn ($a) => (($a->fim ?? now())->getTimestamp() - $a->inicio->getTimestamp()) / 60), 1))
            ->sortDesc()->all();

        $dados = [
            'op' => $this->dadosOp($op, $produtos[rtrim($op->c2_produto)] ?? null) + [
                'excluida_no_protheus' => $op->d_e_l_e_t_ === '*',
                'centro_custo' => $op->c2_cc,
                'observacao' => $op->c2_obs,
            ],
            'custo' => $this->numerosCusto($resumo) + [
                'producao_qtd' => $resumo->producaoQtd,
                'baixas_valor' => round($resumo->consumoValor, 2),
                'devolucoes_valor' => round($resumo->devolucaoValor, 2),
                'notas_fiscais_valor' => round($resumo->notaValor, 2),
                'movimentos' => $resumo->movimentos,
                'movimentos_pelo_custo_medio' => $resumo->pelaMedia,
                'movimentos_sem_custo' => $resumo->semCusto,
            ],
            'componentes_mais_caros' => array_map(fn ($c) => [
                'produto' => $c['produto'],
                'descricao' => ($produtos[$c['produto']] ?? null)?->descricao(),
                'quantidade' => round($c['quantidade'], 4),
                'um' => $c['um'],
                'valor' => round($c['valor'], 2),
            ], array_slice($kardex['componentes'], 0, 15)),
            'maquinas_com_a_op' => Maquina::where('ordem_producao_id', $op->id)->get(['codigo', 'nome', 'situacao'])
                ->map(fn ($m) => "{$m->codigo} - {$m->nome} ({$m->rotuloSituacao()})")->all(),
            'minutos_apontados_por_legenda' => $apontado,
        ];
        if ($u->pode('empenhos_op')) {
            $dados['empenhos'] = $this->empenhosOp($u, ['op' => $op->numero, 'so_com_saldo' => false, 'limite' => 30])['empenhos'];
        }

        return $dados;
    }

    private function empenhosOp(Usuario $u, array $e): array
    {
        $op = trim((string) ($e['op'] ?? ''));
        $comp = trim((string) ($e['componente'] ?? ''));
        if ($op === '' && $comp === '') {
            throw new ErroFerramenta('Informe a OP ou o componente.');
        }
        $empenhos = EmpenhoOp::query()->where('d_e_l_e_t_', '<>', '*')
            ->when($op !== '', fn ($q) => $q->where('op_numero', 'like', $op.'%'))
            ->when($comp !== '', fn ($q) => $q->where('d4_cod', 'like', $comp.'%'))
            ->when($e['so_com_saldo'] ?? true, fn ($q) => $q->where('d4_quant', '>', 0))
            ->orderBy('op_numero')->orderBy('d4_cod')
            ->limit($this->limite($e['limite'] ?? null))->get();
        $produtos = $this->custos->produtos($empenhos->pluck('d4_cod'));

        return ['empenhos' => $empenhos->map(fn ($m) => [
            'op' => $m->op_numero,
            'componente' => rtrim($m->d4_cod),
            'descricao' => ($produtos[rtrim($m->d4_cod)] ?? null)?->descricao(),
            'armazem' => $m->d4_local,
            'quantidade_original' => (float) $m->d4_qtdeori,
            'saldo' => (float) $m->d4_quant,
            'data' => OrdemProducao::data($m->d4_data),
        ])->all()];
    }

    private function engenhariaProduto(Usuario $u, array $e): array
    {
        $produto = trim((string) ($e['produto'] ?? ''));
        if ($produto === '') {
            throw new ErroFerramenta('Informe o código do produto.');
        }
        $descricoes = $this->custos->produtos([$produto]);
        $dados = ['produto' => $produto, 'descricao' => ($descricoes[$produto] ?? null)?->descricao()];
        $hoje = now($this->fuso())->format('Ymd');

        if ($u->pode('estruturas')) {
            $itens = DB::table('estruturas_produto')->where('d_e_l_e_t_', '<>', '*')->where('g1_cod', $produto)
                ->where(fn ($q) => $q->whereNull('g1_fim')->orWhere('g1_fim', '')->orWhere('g1_fim', '>=', $hoje))
                ->orderBy('g1_comp')->limit($this->maxLinhas())->get();
            $nomes = $this->custos->produtos($itens->pluck('g1_comp'));
            $dados['estrutura'] = $itens->map(fn ($i) => [
                'componente' => rtrim($i->g1_comp),
                'descricao' => ($nomes[rtrim($i->g1_comp)] ?? null)?->descricao(),
                'quantidade' => (float) $i->g1_quant,
                'perda_pct' => (float) $i->g1_perda,
                'revisao' => trim("{$i->g1_revini}-{$i->g1_revfim}", '-'),
            ])->all();
        }
        if ($u->pode('roteiros')) {
            $dados['roteiro'] = DB::table('roteiros_operacao')->where('d_e_l_e_t_', '<>', '*')->where('g2_produto', $produto)
                ->orderBy('g2_codigo')->orderBy('g2_operac')->limit($this->maxLinhas())->get()
                ->map(fn ($o) => [
                    'roteiro' => $o->g2_codigo,
                    'operacao' => $o->g2_operac,
                    'descricao' => $o->g2_descri,
                    'centro_trabalho_maquina' => $o->g2_ctrab,
                    'recurso' => $o->g2_recurso,
                    'ferramenta' => $o->g2_ferram,
                    'setup' => (float) $o->g2_setup,
                    'tempo_padrao' => (float) $o->g2_tempad,
                    'lote_padrao' => (float) $o->g2_lotepad,
                ])->all();
        }

        return $dados;
    }

    private function ferramentasMoldes(Usuario $u, array $e): array
    {
        $busca = trim((string) ($e['busca'] ?? ''));

        return ['ferramentas' => Ferramenta::query()->where('d_e_l_e_t_', '<>', '*')
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('h4_codigo', 'like', $busca.'%')->orWhere('h4_descri', 'like', '%'.$busca.'%')))
            ->orderBy('h4_codigo')->limit($this->limite($e['limite'] ?? null))->get()
            ->map(fn ($f) => [
                'codigo' => rtrim($f->h4_codigo),
                'descricao' => $f->h4_descri,
                'cavidades' => (int) $f->h4_qtdcav,
                'cavidades_ativas' => (int) $f->h4_qtdcavativas,
                'quantidade' => (float) $f->h4_quant,
                'vida_util' => (float) $f->h4_vidauti,
                'unidade' => $f->rotuloUnidade(),
            ])->all()];
    }

    private function ordensServico(Usuario $u, array $e): array
    {
        $ss = ($e['registro'] ?? 'os') === 'ss';
        $modelo = $ss ? SolicitacaoServico::class : OrdemServico::class;
        $statusValidos = array_keys($modelo::STATUS);
        $status = array_values(array_intersect($e['status'] ?? [], $statusValidos));
        $ids = array_filter(array_map('intval', $e['maquina_ids'] ?? []));
        $de = $this->dia($e['de'] ?? null);
        $ate = $this->dia($e['ate'] ?? null);

        $consulta = AcessoOs::filtrar($modelo::query(), $u)
            ->when($status, fn ($q) => $q->whereIn('status', $status))
            ->when($ids, fn ($q) => $q->whereIn('maquina_id', $ids))
            ->when($de, fn ($q) => $q->where('criado_em', '>=', $de->utc()))
            ->when($ate, fn ($q) => $q->where('criado_em', '<', $ate->addDay()->utc()));
        $agrupar = $e['agrupar'] ?? 'lista';

        if ($agrupar !== 'lista') {
            $registros = $consulta->with(['maquina:id,codigo,nome', 'areaExecutante:id,nome'])->get();
            $chave = fn ($r) => match ($agrupar) {
                'status' => $r->rotuloStatus(),
                'tipo' => $r->rotuloTipo(),
                'prioridade' => $r->rotuloPrioridade(),
                'maquina' => $r->maquina ? "{$r->maquina->codigo} - {$r->maquina->nome}" : 'Sem máquina',
                'area' => $r->areaExecutante?->nome ?? 'Sem área',
                default => config('oee.unidades')[$r->unidade] ?? $r->unidade,
            };
            $grupos = $registros->groupBy($chave)->map(fn ($g, $k) => array_filter([
                $agrupar => $k,
                'quantidade' => $g->count(),
                'horas_apontadas' => $ss ? null : round($g->sum('total_minutos') / 60, 2),
            ], fn ($v) => $v !== null))->sortByDesc('quantidade')->values()->all();

            return ['registro' => $ss ? 'SS' : 'OS', 'total' => $registros->count(), 'agrupado_por' => $agrupar, 'grupos' => $grupos];
        }

        $limite = $this->limite($e['limite'] ?? null);
        $registros = $consulta->with(['maquina:id,codigo,nome', 'areaExecutante:id,nome', 'solicitante:id,nome'])
            ->orderByDesc('criado_em')->limit($limite)->get();

        return ['registro' => $ss ? 'SS' : 'OS', 'mostrando' => $registros->count(), 'itens' => $registros->map(fn ($r) => array_filter([
            'numero' => $r->numeroFormatado(),
            'titulo' => $r->titulo,
            'status' => $r->rotuloStatus(),
            'tipo' => $r->rotuloTipo(),
            'prioridade' => $r->rotuloPrioridade(),
            'unidade' => config('oee.unidades')[$r->unidade] ?? $r->unidade,
            'maquina' => $r->maquina ? "{$r->maquina->codigo} - {$r->maquina->nome}" : null,
            'maquina_parada' => (bool) $r->maquina_parada,
            'area_executante' => $r->areaExecutante?->nome,
            'solicitante' => $r->solicitante?->nome,
            'aberta_em' => $this->dataHora($r->criado_em),
            'concluida_em' => $ss ? null : $this->dataHora($r->concluida_em),
            'horas_apontadas' => $ss ? null : round(((int) $r->total_minutos) / 60, 2),
        ], fn ($v) => $v !== null && $v !== ''))->all()];
    }

    // ------------------------------------------------------------------ apoio

    /** @return array<string, mixed> */
    private function numerosOee(IndicadoresPeriodo $i, bool $comPareto): array
    {
        $pct = fn (?float $v) => $v === null ? null : round($v * 100, 1);
        $dados = [
            'oee_pct' => $pct($i->oee()),
            'disponibilidade_pct' => $pct($i->disponibilidade()),
            'performance_pct' => $pct($i->performance()),
            'qualidade_pct' => $pct($i->qualidade()),
            'tempo_programado_min' => round($i->tempoProgramadoMin, 1),
            'paradas_planejadas_min' => round($i->paradasPlanejadasMin, 1),
            'paradas_nao_planejadas_min' => round($i->paradasNaoPlanejadasMin, 1),
            'tempo_operando_min' => round($i->tempoOperandoMin(), 1),
            'total_produzido' => $i->totalProduzido,
            'pecas_boas' => $i->pecasBoas,
            'refugo' => $i->refugo(),
            'apontamentos' => $i->apontamentos,
        ];
        if ($comPareto) {
            $dados['pareto_paradas'] = array_map(fn ($p) => [
                'motivo' => $p['motivo'], 'planejada' => $p['planejada'], 'minutos' => round($p['minutos'], 1), 'ocorrencias' => $p['ocorrencias'],
            ], array_slice($i->pareto, 0, 15));
        }

        return $dados;
    }

    private function dadosOp(OrdemProducao $op, ?ProdutoProtheus $produto): array
    {
        return [
            'numero' => $op->numero,
            'filial' => rtrim((string) $op->c2_filial),
            'status' => $op->rotuloStatus(),
            'produto' => rtrim((string) $op->c2_produto),
            'descricao' => $produto?->descricao(),
            'um' => $op->c2_um ?: $produto?->b1_um,
            'quantidade' => (float) $op->c2_quant,
            'produzido' => (float) $op->c2_quje,
            'produzido_pct' => $op->c2_quant > 0 ? round($op->c2_quje / $op->c2_quant * 100, 1) : null,
            'perda' => (float) $op->c2_perda,
            'emissao' => OrdemProducao::data($op->c2_emissao),
            'previsao_inicio' => OrdemProducao::data($op->c2_datpri),
            'previsao_fim' => OrdemProducao::data($op->c2_datprf),
            'encerrada_em' => trim((string) $op->c2_datrf) !== '' ? OrdemProducao::data($op->c2_datrf) : null,
        ];
    }

    private function numerosCusto(ResumoCustoOp $r): array
    {
        $div = $r->divergencia();

        return [
            'custo_unitario_protheus' => $r->custoProtheus() !== null ? round($r->custoProtheus(), 4) : null,
            'custo_unitario_simulado_oee' => $r->custoSimulado() !== null ? round($r->custoSimulado(), 4) : null,
            'custo_unitario_consumos_liquidos' => $r->custoUnitario() !== null ? round($r->custoUnitario(), 4) : null,
            'custo_meta_unitario' => $r->meta !== null ? round($r->meta, 4) : null,
            'divergencia_pct' => $div !== null ? round($div * 100, 1) : null,
            'custo_liquido_total' => round($r->custoLiquido(), 2),
        ];
    }

    /** Máquinas visíveis ao usuário (escopo de unidade), opcionalmente só as pedidas. */
    private function maquinasVisiveis(array $ids)
    {
        $ids = array_filter(array_map('intval', $ids));

        return Maquina::query()->when($ids, fn ($q) => $q->whereIn('id', $ids))->get(['id', 'codigo', 'nome']);
    }

    /**
     * Período em dias locais → [início UTC, fim UTC (exclusivo), rótulo]. Até 400 dias.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function periodo(?string $de, ?string $ate): array
    {
        $hoje = CarbonImmutable::now($this->fuso())->startOfDay();
        $ini = $this->dia($de) ?? $hoje;
        $fim = $this->dia($ate) ?? ($de ? $ini : $hoje);
        if ($fim->lessThan($ini)) {
            [$ini, $fim] = [$fim, $ini];
        }
        if ($ini->diffInDays($fim) > 400) {
            throw new ErroFerramenta('Período longo demais (máximo de 400 dias).');
        }

        return [$ini->utc(), $fim->addDay()->utc(), $ini->format('d/m/Y').' a '.$fim->format('d/m/Y')];
    }

    private function dia(?string $valor): ?CarbonImmutable
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            throw new ErroFerramenta("Data inválida: {$valor} (use AAAA-MM-DD).");
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $valor, $this->fuso());
    }

    private function dataProtheus(?string $valor): ?string
    {
        return $this->dia($valor)?->format('Ymd');
    }

    private function dataHora(?CarbonInterface $valor): ?string
    {
        return $valor?->copy()->setTimezone($this->fuso())->format('d/m/Y H:i');
    }

    private function fuso(): string
    {
        return (string) config('oee.fuso_exibicao', 'America/Sao_Paulo');
    }

    private function maxLinhas(): int
    {
        return max(10, (int) config('assistente.max_linhas', 300));
    }

    private function limite(mixed $pedido): int
    {
        $n = (int) ($pedido ?: 50);

        return max(1, min($n, $this->maxLinhas()));
    }

    /** Corta listas longas e avisa quantas ficaram de fora. */
    private function limitar(array $itens, string $chave, mixed $limite = null): array
    {
        $max = $limite === null ? $this->maxLinhas() : $this->limite($limite);
        $total = count($itens);

        return array_filter([
            $chave => array_slice($itens, 0, $max),
            'total' => $total,
            'aviso' => $total > $max ? "Mostrando {$max} de {$total}; filtre ou agrupe para ver o resto." : null,
        ], fn ($v) => $v !== null);
    }
}
