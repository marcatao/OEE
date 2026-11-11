<?php

namespace App\Http\Controllers\Manutencao;

use App\Http\Controllers\Controller;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Services\Manutencao\FluxoOrdemServico;
use App\Support\AcessoOs;
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
            $consulta->where(fn ($q) => $q->where('descricao', 'like', '%'.$f['busca'].'%')
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

        return view('manutencao.ordens.show', [
            'os' => $os,
            'apontamentos' => $os->apontamentos()->with(['mantenedor' => fn ($q) => $q->withoutGlobalScopes(), 'criadoPor'])->orderBy('inicio')->get(),
            'materiais' => $os->materiais()->with('criadoPor')->orderBy('id')->get(),
            'checklist' => $os->checklist()->with('respondidoPor')->orderBy('ordem')->orderBy('id')->get(),
            'historico' => $os->historico()->with('usuario')->orderBy('id')->get(),
            'acoes' => FluxoOrdemServico::acoesPossiveis($os),
            'mantenedores' => AcessoOs::mantenedores(),
            'meuMantenedor' => AcessoOs::mantenedorDo($u),
            'podeAprovar' => AcessoOs::podeAprovar($u),
            'podeExecutar' => AcessoOs::podeExecutar($u),
            'podeAceitar' => $os->status === 'concluida' && ($os->solicitante_id === $u->id || AcessoOs::podeAprovar($u)),
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

        return view('manutencao.ordens.form', [
            'os' => $os,
            'maquinas' => AcessoOs::maquinas(),
            'areas' => AcessoOs::areas(),
            'centros' => AcessoOs::centrosCusto(),
            'mantenedores' => AcessoOs::mantenedores(),
        ]);
    }

    /** Todos os campos da OS são alteráveis por quem aprova (fica no histórico). */
    public function update(Request $request, int $ordem): RedirectResponse
    {
        $os = $this->buscar($request, $ordem);
        $dados = $request->validate([
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
            abort_unless(AcessoOs::podeAprovar($request->user()), 403, 'Só quem aprova pode cancelar ou reabrir uma OS.');
        }
        $this->fluxo->executarAcao($os, $dados['acao'], $request->user(), $dados['observacao'] ?? null);

        return redirect()->route('os.ordens.show', $os)->with('ok', 'OS '.mb_strtolower(OrdemServico::STATUS[$os->status]).'.');
    }

    /** Aceite (assinatura eletrônica) do solicitante, ou recusa do resultado com justificativa. */
    public function aceite(Request $request, int $ordem): RedirectResponse
    {
        $os = $this->buscar($request, $ordem);
        $u = $request->user();
        abort_unless($os->solicitante_id === $u->id || AcessoOs::podeAprovar($u), 403, 'Só o solicitante dá o aceite do serviço.');
        $dados = $request->validate(['resultado' => ['required', Rule::in(['aceito', 'recusado'])], 'observacao' => ['nullable', 'string', 'max:500']]);
        if ($dados['resultado'] === 'recusado') {
            $request->validate(['observacao' => ['required']], ['observacao.required' => 'Explique o que ficou faltando no serviço.']);
            $this->fluxo->recusarServico($os, $u, $dados['observacao']);

            return redirect()->route('os.ordens.show', $os)->with('ok', 'Serviço recusado: a OS voltou para execução.');
        }
        $this->fluxo->aceitar($os, $u, $dados['observacao'] ?? null);

        return redirect()->route('os.ordens.show', $os)->with('ok', 'Aceite registrado: OS encerrada.');
    }

    public function checklist(Request $request, int $ordem): RedirectResponse
    {
        $os = $this->buscar($request, $ordem);
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

    private function buscar(Request $request, int $id): OrdemServico
    {
        return AcessoOs::filtrar(OrdemServico::query(), $request->user())
            ->with(['maquina' => fn ($q) => $q->withoutGlobalScopes(), 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes(),
                'responsavel' => fn ($q) => $q->withoutGlobalScopes(), 'mantenedores.usuario', 'solicitante', 'aprovadoPor', 'solicitacao', 'plano' => fn ($q) => $q->withoutGlobalScopes(), 'checklist'])
            ->findOrFail($id);
    }
}
