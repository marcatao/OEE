<?php

namespace App\Http\Controllers\Manutencao;

use App\Http\Controllers\Controller;
use App\Models\CentroCusto;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Services\Manutencao\FluxoOrdemServico;
use App\Services\Manutencao\HorasManutencao;
use App\Support\AcessoOs;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ordens de serviço › OS: fila da manutenção (por unidade), detalhe com apontamentos de horas, materiais, checklist e
 * histórico; mudanças de status, aceite do solicitante e impressão para assinatura.
 */
class OrdemServicoController extends Controller
{
    /** Status "em aberto" (filtro padrão da fila). */
    public const EM_ABERTO = ['aberta', 'em_execucao', 'aguardando_material', 'concluida'];

    public function __construct(private FluxoOrdemServico $fluxo) {}

    public function index(Request $request): View
    {
        $u = $request->user();
        $f = [
            'status' => (string) $request->input('status', 'em_aberto'),
            'tipo' => (string) $request->input('tipo', ''),
            'maquina' => $request->integer('maquina') ?: null,
            'responsavel' => $request->integer('responsavel') ?: null,
            'busca' => trim((string) $request->input('busca', '')),
        ];
        $consulta = AcessoOs::filtrar(OrdemServico::query(), $u)
            ->with(['maquina' => fn ($q) => $q->withoutGlobalScopes(), 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes(),
                'responsavel' => fn ($q) => $q->withoutGlobalScopes(), 'mantenedores.usuario', 'solicitante']);
        if ($f['status'] === 'em_aberto') {
            $consulta->whereIn('status', self::EM_ABERTO);
        } elseif (isset(OrdemServico::STATUS[$f['status']])) {
            $consulta->where('status', $f['status']);
        }
        if (isset(SolicitacaoServico::TIPOS[$f['tipo']])) {
            $consulta->where('tipo', $f['tipo']);
        }
        if ($f['maquina']) {
            $consulta->where('maquina_id', $f['maquina']);
        }
        if ($f['responsavel']) {
            $consulta->where(fn ($q) => $q->where('responsavel_id', $f['responsavel'])
                ->orWhereHas('mantenedores', fn ($m) => $m->where('os_mantenedores.id', $f['responsavel'])));
        }
        if ($f['busca'] !== '') {
            $numero = (int) preg_replace('/\D/', '', $f['busca']);
            $consulta->where(fn ($q) => $q->where('descricao', 'like', '%'.$f['busca'].'%')->orWhere('titulo', 'like', '%'.$f['busca'].'%')
                ->when($numero > 0, fn ($q) => $q->orWhere('id', $numero)->orWhere('solicitacao_id', $numero)));
        }
        $totais = AcessoOs::filtrar(OrdemServico::query(), $u)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('manutencao.ordens.index', [
            'ordens' => $consulta->orderByRaw("CASE prioridade WHEN 'urgente' THEN 0 WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END")->orderByDesc('id')
                ->paginate(30)->withQueryString(),
            'f' => $f,
            'totais' => $totais,
            'maquinas' => AcessoOs::maquinas(),
            'mantenedores' => AcessoOs::mantenedores(),
        ]);
    }

    public function show(Request $request, int $ordem): View
    {
        $os = $this->buscar($request, $ordem);
        $u = $request->user();

        $historico = $os->historicoCompleto()->with('usuario')->orderByDesc('criado_em')->orderByDesc('id')->get();

        return view('manutencao.ordens.show', [
            'os' => $os,
            'marcos' => $this->marcos($os, $historico),
            // SQL Server ignora os espaços à direita do código do Protheus na comparação.
            'ccDescricao' => $os->centro_custo ? CentroCusto::withoutGlobalScope('unidade')->where('ctt_custo', trim((string) $os->centro_custo))->first()?->descricao() : null,
            'apontamentos' => $os->apontamentos()->with(['mantenedor' => fn ($q) => $q->withoutGlobalScopes(), 'criadoPor'])->orderBy('inicio')->get(),
            'materiais' => $os->materiais()->with('criadoPor')->orderBy('id')->get(),
            'checklist' => $os->checklist()->with('respondidoPor')->orderBy('ordem')->orderBy('id')->get(),
            'historico' => $historico,
            'acoes' => FluxoOrdemServico::acoesPossiveis($os),
            'mantenedores' => AcessoOs::mantenedoresDaArea($os),
            'meuMantenedor' => AcessoOs::mantenedorDo($u),
            'podeAprovar' => AcessoOs::podeAprovar($u, $os),
            'podeExecutar' => AcessoOs::podeExecutar($u, $os),
            'podeCancelar' => AcessoOs::podeCancelar($u, $os),
            'podeAvaliar' => $os->status === 'concluida' && ($os->solicitante_id === $u->id || AcessoOs::ehAdminDa($u, $os->unidade)),
            'interacoes' => $os->interacoes()->with(['usuario', 'anexos'])->orderBy('id')->get(),
            'limiteAnexoKb' => InteracaoController::limiteKb(),
        ]);
    }

    public function imprimir(Request $request, int $ordem): View
    {
        $os = $this->buscar($request, $ordem);

        return view('manutencao.ordens.imprimir', [
            'os' => $os,
            'apontamentos' => $os->apontamentos()->with(['mantenedor' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('inicio')->get(),
            'materiais' => $os->materiais()->where('status', '<>', 'cancelado')->orderBy('id')->get(),
            'checklist' => $os->checklist()->orderBy('ordem')->orderBy('id')->get(),
        ]);
    }

    public function edit(Request $request, int $ordem): View
    {
        $os = $this->buscar($request, $ordem);
        abort_unless(AcessoOs::podeAprovar($request->user(), $os), 403, 'Esta OS não é de uma área executante que você atende.');

        return view('manutencao.ordens.form', [
            'os' => $os,
            'maquinas' => AcessoOs::maquinas(),
            'areas' => AcessoOs::areas(),
            'centros' => AcessoOs::centrosCusto(),
            'mantenedores' => AcessoOs::mantenedoresDaArea($os),
        ]);
    }

    /** Todos os campos da OS são alteráveis por quem aprova (fica no histórico). */
    public function update(Request $request, int $ordem): RedirectResponse
    {
        $os = $this->buscar($request, $ordem);
        abort_unless(AcessoOs::podeAprovar($request->user(), $os), 403, 'Esta OS não é de uma área executante que você atende.');
        $dados = $request->validate([
            'titulo' => ['nullable', 'string', 'max:120'],
            'tipo' => ['required', Rule::in(array_keys(SolicitacaoServico::TIPOS))],
            'maquina_id' => ['nullable', 'integer', Rule::exists('maquinas', 'id')],
            'area_executante_id' => ['required', 'integer', Rule::exists('os_areas_executantes', 'id')],
            'responsavel_id' => ['nullable', 'integer', Rule::exists('os_mantenedores', 'id')],
            'centro_custo' => ['nullable', 'string', 'max:20'],
            'centro_custo_solicitante' => ['nullable', 'string', 'max:120'],
            'prioridade' => ['required', Rule::in(array_keys(SolicitacaoServico::PRIORIDADES))],
            'maquina_parada' => ['nullable', 'boolean'],
            'prevista_para' => ['nullable', 'date'],
            'descricao' => ['required', 'string', 'max:2000'],
            'solucao' => ['nullable', 'string', 'max:2000'],
        ]);
        $dados['maquina_parada'] = (bool) ($dados['maquina_parada'] ?? false);
        $area = AreaExecutante::withoutGlobalScopes()->find($dados['area_executante_id']);
        $maquina = isset($dados['maquina_id']) ? Maquina::withoutGlobalScopes()->find($dados['maquina_id']) : null;
        $dados['unidade'] = OrdemServico::unidadePara($area, $maquina);

        $os->fill($dados);
        $alterados = array_keys($os->getDirty());
        $os->save();
        if ($alterados) {
            $this->fluxo->registrar($os, null, 'alterada', $os->status, $os->status, 'Campos alterados: '.implode(', ', $alterados), $request->user());
        }

        return redirect()->route('os.ordens.show', $os)->with('ok', 'OS atualizada.');
    }

    /** Distribui a OS para um ou mais mantenedores (nenhum = definir depois). */
    public function distribuir(Request $request, int $ordem): RedirectResponse
    {
        $os = $this->buscar($request, $ordem);
        abort_unless(AcessoOs::podeAprovar($request->user(), $os), 403, 'Esta OS não é de uma área executante que você atende.');
        $dados = $request->validate(['mantenedores' => ['nullable', 'array'], 'mantenedores.*' => ['integer', Rule::exists('os_mantenedores', 'id')]]);
        $os->atribuirMantenedores($dados['mantenedores'] ?? []);
        $nomes = $os->mantenedores()->with('usuario')->get()->map->nomeExibicao()->join(', ');
        $this->fluxo->registrar($os, null, 'distribuida', $os->status, $os->status, 'Mantenedores: '.($nomes ?: 'definir depois'), $request->user());

        return back()->with('ok', 'Mantenedores atualizados.');
    }

    public function acao(Request $request, int $ordem): RedirectResponse
    {
        $os = $this->buscar($request, $ordem);
        $dados = $request->validate(['acao' => ['required', Rule::in(array_keys(FluxoOrdemServico::ACOES))], 'observacao' => ['nullable', 'string', 'max:2000']]);
        if (in_array($dados['acao'], ['cancelar', 'reabrir'], true)) {
            abort_unless(AcessoOs::podeCancelar($request->user(), $os), 403, 'Só o autor da solicitação ou o administrador da área pode cancelar ou reabrir.');
        } else {
            abort_unless(AcessoOs::podeExecutar($request->user(), $os), 403, 'Esta OS não é de uma área executante que você atende.');
        }
        $this->fluxo->executarAcao($os, $dados['acao'], $request->user(), $dados['observacao'] ?? null);

        return redirect()->route('os.ordens.show', $os)->with('ok', 'OS '.mb_strtolower(OrdemServico::STATUS[$os->status]).'.');
    }

    /** Avaliação do solicitante (Excelente, Boa ou Ruim — Ruim reabre a OS e pede o motivo). */
    public function avaliar(Request $request, int $ordem): RedirectResponse
    {
        $os = $this->buscar($request, $ordem);
        $u = $request->user();
        abort_unless($os->solicitante_id === $u->id || AcessoOs::ehAdminDa($u, $os->unidade), 403, 'Só o solicitante avalia o serviço.');
        $dados = $request->validate(['avaliacao' => ['required', Rule::in(array_keys(OrdemServico::AVALIACOES))], 'observacao' => ['nullable', 'string', 'max:500']],
            ['avaliacao.required' => 'Escolha Excelente, Boa ou Ruim.']);
        $this->fluxo->avaliar($os, $u, $dados['avaliacao'], $dados['observacao'] ?? null);

        return redirect()->route('os.ordens.show', $os)->with('ok', $dados['avaliacao'] === 'ruim'
            ? 'Avaliação registrada: a OS voltou para a manutenção com o seu motivo.'
            : 'Obrigado! Avaliação registrada e OS encerrada.');
    }

    public function checklist(Request $request, int $ordem): RedirectResponse
    {
        $os = $this->buscar($request, $ordem);
        abort_unless(AcessoOs::podeExecutar($request->user(), $os), 403, 'Esta OS não é de uma área executante que você atende.');
        $dados = $request->validate(['resposta' => ['array'], 'resposta.*' => ['nullable', Rule::in(['ok', 'nok', 'na'])],
            'observacao' => ['array'], 'observacao.*' => ['nullable', 'string', 'max:300']]);
        foreach ($os->checklist as $item) {
            $resposta = $dados['resposta'][$item->id] ?? null;
            $obs = $dados['observacao'][$item->id] ?? null;
            if ($resposta !== $item->resposta || $obs !== $item->observacao) {
                $item->forceFill(['resposta' => $resposta, 'observacao' => $obs, 'respondido_por_id' => $request->user()->id, 'respondido_em' => now()])->save();
            }
        }

        return back()->with('ok', 'Checklist salvo.');
    }

    /**
     * Linha do tempo da OS: abertura da SS → aprovação → início do atendimento → conclusão → avaliação, com quem fez e o
     * tempo desde a etapa anterior (lida do histórico, que guarda tudo; as datas da OS completam as antigas).
     *
     * @return list<array{rotulo: string, quando: ?CarbonInterface, quem: ?string, detalhe: ?string}>
     */
    private function marcos(OrdemServico $os, $historico): array
    {
        $cronologico = $historico->sortBy(fn ($h) => [$h->criado_em?->getTimestamp(), $h->id])->values();
        $primeiro = fn (array $acoes) => $cronologico->first(fn ($h) => in_array($h->acao, $acoes, true));
        $ultimo = fn (array $acoes) => $cronologico->last(fn ($h) => in_array($h->acao, $acoes, true));

        $abertura = $primeiro(['aberta', 'gerada_preventiva']);
        $aprovacao = $primeiro(['aprovada']);
        $inicio = $primeiro(['iniciar']);
        $conclusao = $ultimo(['concluir']);
        $avaliacao = $ultimo(['avaliada', 'avaliada_ruim', 'aceite']);
        $preventiva = $os->plano_preventiva_id !== null;

        $marcos = [
            ['rotulo' => $preventiva ? 'OS gerada (PAMP)' : 'SS aberta', 'quando' => $abertura?->criado_em ?? $os->solicitacao?->criado_em ?? $os->criado_em,
                'quem' => $abertura?->usuario?->nome ?? $os->solicitante?->nome, 'detalhe' => null],
            ['rotulo' => $preventiva ? 'Programada para' : 'Aprovada', 'quando' => $preventiva ? $os->prevista_para : ($aprovacao?->criado_em ?? $os->solicitacao?->analisado_em),
                'quem' => $preventiva ? null : ($aprovacao?->usuario?->nome ?? $os->aprovadoPor?->nome), 'detalhe' => null],
            ['rotulo' => 'Em atendimento', 'quando' => $inicio?->criado_em ?? $os->iniciada_em, 'quem' => $inicio?->usuario?->nome, 'detalhe' => null],
            ['rotulo' => 'Concluída', 'quando' => $conclusao?->criado_em ?? $os->concluida_em, 'quem' => $conclusao?->usuario?->nome,
                'detalhe' => $os->total_minutos > 0 ? HorasManutencao::hhmm($os->total_minutos).' h apontadas' : null],
            ['rotulo' => 'Avaliada', 'quando' => $os->status === 'encerrada' ? ($avaliacao?->criado_em ?? $os->aceite_em) : null,
                'quem' => $os->status === 'encerrada' ? ($avaliacao?->usuario?->nome ?? $os->aceitePor?->nome) : null,
                'detalhe' => $os->status === 'encerrada' && $os->avaliacao ? OrdemServico::AVALIACOES[$os->avaliacao] : null],
        ];
        // Tempo desde a etapa anterior concluída.
        $anterior = null;
        foreach ($marcos as &$m) {
            $m['duracao'] = $m['quando'] && $anterior && ! $preventiva ? self::duracao($anterior, $m['quando']) : null;
            if ($m['quando']) {
                $anterior = $m['quando'];
            }
        }

        return $marcos;
    }

    /** "2 d 3 h", "4 h 10 min", "25 min". */
    public static function duracao($de, $ate): string
    {
        $min = max(0, intdiv($ate->getTimestamp() - $de->getTimestamp(), 60));
        if ($min >= 1440) {
            return intdiv($min, 1440).' d '.intdiv($min % 1440, 60).' h';
        }

        return $min >= 60 ? intdiv($min, 60).' h '.($min % 60).' min' : $min.' min';
    }

    private function buscar(Request $request, int $id): OrdemServico
    {
        return AcessoOs::filtrar(OrdemServico::query(), $request->user())
            ->with(['maquina' => fn ($q) => $q->withoutGlobalScopes(), 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes(),
                'responsavel' => fn ($q) => $q->withoutGlobalScopes(), 'mantenedores.usuario', 'solicitante', 'aprovadoPor', 'solicitacao', 'plano' => fn ($q) => $q->withoutGlobalScopes(), 'checklist'])
            ->findOrFail($id);
    }
}
