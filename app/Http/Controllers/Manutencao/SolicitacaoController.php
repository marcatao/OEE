<?php

namespace App\Http\Controllers\Manutencao;

use App\Http\Controllers\Controller;
use App\Models\ApontamentoEstado;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Manutencao\SugestaoDescricao;
use App\Models\Maquina;
use App\Services\Manutencao\FluxoOrdemServico;
use App\Support\AcessoOs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Ordens de serviço › Solicitações (SS): qualquer setor abre; a manutenção analisa (aprova → vira OS, ou rejeita com
 * motivo, que volta ao solicitante).
 */
class SolicitacaoController extends Controller
{
    public function __construct(private FluxoOrdemServico $fluxo) {}

    public function index(Request $request): View
    {
        $u = $request->user();
        $status = $request->input('status', AcessoOs::podeAprovar($u) ? 'pendente' : '');
        $consulta = AcessoOs::filtrar(SolicitacaoServico::query(), $u)
            ->with(['maquina' => fn ($q) => $q->withoutGlobalScopes(), 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes(), 'solicitante', 'ordemServico']);
        if ($status !== '' && isset(SolicitacaoServico::STATUS[$status])) {
            $consulta->where('status', $status);
        }
        if ($request->boolean('minhas')) {
            $consulta->where('solicitante_id', $u->id);
        }
        $totais = AcessoOs::filtrar(SolicitacaoServico::query(), $u)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('manutencao.solicitacoes.index', [
            'solicitacoes' => $consulta->orderByDesc('id')->paginate(30)->withQueryString(),
            'status' => $status,
            'totais' => $totais,
        ]);
    }

    public function create(Request $request): View
    {
        $this->exigirSolicitante($request);
        $ss = new SolicitacaoServico(['tipo' => 'corretiva', 'prioridade' => 'media', 'maquina_parada' => false]);
        // Aberta a partir de uma parada do apontamento real (Timeline): já vem com a máquina e a OP.
        if ($request->filled('apontamento')) {
            $ap = ApontamentoEstado::with('maquina')->find($request->integer('apontamento'));
            if ($ap) {
                $ss->fill(['maquina_id' => $ap->maquina_id, 'apontamento_estado_id' => $ap->id, 'ordem_producao_id' => $ap->ordem_producao_id,
                    'maquina_parada' => $ap->fim === null, 'centro_custo' => $ap->maquina?->centro_custo]);
            }
        } elseif ($request->filled('maquina')) {
            $ss->maquina_id = $request->integer('maquina');
        }

        return view('manutencao.solicitacoes.form', $this->dadosFormulario($ss, $request));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->exigirSolicitante($request);
        $ss = new SolicitacaoServico(['solicitante_id' => $request->user()->id, 'status' => 'pendente',
            'centro_custo_solicitante' => $request->user()->local_escritorio ?: $request->user()->departamento]);
        $this->salvar($request, $ss);
        $this->fluxo->registrar(null, $ss, 'aberta', null, 'pendente', null, $request->user());

        return redirect()->route('os.solicitacoes.show', $ss)->with('ok', "Solicitação {$ss->numeroFormatado()} enviada para a manutenção.");
    }

    public function show(Request $request, int $solicitacao): View
    {
        $ss = $this->buscar($request, $solicitacao);

        return view('manutencao.solicitacoes.show', [
            'ss' => $ss,
            'historico' => $ss->historico()->with('usuario')->orderBy('id')->get(),
            'mantenedores' => AcessoOs::mantenedores(),
            'meuMantenedor' => AcessoOs::mantenedorDo($request->user()),
            'podeAprovar' => AcessoOs::podeAprovar($request->user()),
            'podeCancelar' => AcessoOs::podeCancelar($request->user(), $ss),
        ]);
    }

    public function edit(Request $request, int $solicitacao): View
    {
        $ss = $this->buscar($request, $solicitacao);
        $this->exigirEdicao($request, $ss);

        return view('manutencao.solicitacoes.form', $this->dadosFormulario($ss, $request));
    }

    public function update(Request $request, int $solicitacao): RedirectResponse
    {
        $ss = $this->buscar($request, $solicitacao);
        $this->exigirEdicao($request, $ss);
        $this->salvar($request, $ss);
        $this->fluxo->registrar(null, $ss, 'alterada', $ss->status, $ss->status, null, $request->user());

        return redirect()->route('os.solicitacoes.show', $ss)->with('ok', 'Solicitação atualizada.');
    }

    public function aprovar(Request $request, int $solicitacao): RedirectResponse
    {
        $ss = $this->buscar($request, $solicitacao);
        $dados = $request->validate([
            'mantenedores' => ['nullable', 'array'],
            'mantenedores.*' => ['integer', Rule::exists('os_mantenedores', 'id')],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);
        $os = $this->fluxo->aprovar($ss, $request->user(), $dados['mantenedores'] ?? [], $dados['observacao'] ?? null);

        return redirect()->route('os.ordens.show', $os)->with('ok', "Solicitação aprovada: emitida a {$os->numeroFormatado()}.");
    }

    public function rejeitar(Request $request, int $solicitacao): RedirectResponse
    {
        $ss = $this->buscar($request, $solicitacao);
        $dados = $request->validate(['motivo_rejeicao' => ['required', 'string', 'max:500']],
            ['motivo_rejeicao.required' => 'Escreva o motivo da rejeição: ele vai para o solicitante.']);
        $this->fluxo->rejeitar($ss, $request->user(), $dados['motivo_rejeicao']);

        return redirect()->route('os.solicitacoes.index')->with('ok', "Solicitação {$ss->numeroFormatado()} rejeitada. O solicitante vê o motivo.");
    }

    public function cancelar(Request $request, int $solicitacao): RedirectResponse
    {
        $ss = $this->buscar($request, $solicitacao);
        abort_unless(AcessoOs::podeCancelar($request->user(), $ss), 403, 'Só o autor da solicitação ou o administrador da área pode cancelar.');
        $this->fluxo->cancelarSolicitacao($ss, $request->user(), $request->input('observacao'));

        return redirect()->route('os.solicitacoes.show', $ss)->with('ok', 'Solicitação cancelada.');
    }

    private function buscar(Request $request, int $id): SolicitacaoServico
    {
        return AcessoOs::filtrar(SolicitacaoServico::query(), $request->user())
            ->with(['maquina' => fn ($q) => $q->withoutGlobalScopes(), 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes(), 'solicitante', 'analisadoPor', 'ordemServico'])
            ->findOrFail($id);
    }

    /** Solicitante edita enquanto a SS aguarda análise; quem aprova edita sempre (todos os campos são alteráveis). */
    private function exigirEdicao(Request $request, SolicitacaoServico $ss): void
    {
        $u = $request->user();
        abort_unless(AcessoOs::podeAprovar($u) || ($ss->solicitante_id === $u->id && $ss->status === 'pendente'), 403,
            'Só quem aprova solicitações pode alterar uma SS que já foi analisada.');
    }

    /** Só quem tem o perfil Solicitante (de Manutenção e/ou de Tecnologia) abre SS. */
    private function exigirSolicitante(Request $request): void
    {
        abort_unless(AcessoOs::podeSolicitar($request->user()), 403,
            'Para abrir solicitações você precisa do perfil Solicitante de Manutenção ou de Tecnologia. Fale com um administrador.');
    }

    /** Quem aprova (ao editar) escolhe qualquer área; quem solicita, só as da unidade em que é Solicitante. */
    private function areasPermitidas(Request $request, SolicitacaoServico $ss)
    {
        $u = $request->user();
        if ($ss->exists && $ss->solicitante_id !== $u->id && AcessoOs::podeAprovar($u)) {
            return AcessoOs::areas();
        }

        return AcessoOs::areasParaSolicitar($u);
    }

    private function dadosFormulario(SolicitacaoServico $ss, Request $request): array
    {
        $u = $request->user();
        $autor = $ss->exists ? ($ss->solicitante ?? $u) : $u;
        $areas = $this->areasPermitidas($request, $ss);
        if ($ss->exists && $ss->area_executante_id && ! $areas->contains('id', $ss->area_executante_id) && $ss->areaExecutante) {
            $areas->push($ss->areaExecutante);
        }

        return [
            'ss' => $ss,
            'maquinas' => AcessoOs::maquinas(),
            'areas' => $areas,
            'centros' => AcessoOs::centrosCusto(),
            'ccUsuario' => AcessoOs::centroCustoDo($autor),
            'sugestoes' => SugestaoDescricao::withoutGlobalScope('unidade')->where('ativo', true)->orderBy('texto')->pluck('texto'),
        ];
    }

    private function salvar(Request $request, SolicitacaoServico $ss): void
    {
        $dados = $request->validate([
            'titulo' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::in(array_keys(SolicitacaoServico::TIPOS))],
            'maquina_id' => ['nullable', 'integer', Rule::exists('maquinas', 'id')],
            'area_executante_id' => ['required', 'integer', Rule::exists('os_areas_executantes', 'id')],
            'centro_custo' => ['nullable', 'string', 'max:20'],
            'prioridade' => ['required', Rule::in(array_keys(SolicitacaoServico::PRIORIDADES))],
            'maquina_parada' => ['nullable', 'boolean'],
            'descricao' => ['required', 'string', 'max:2000'],
            'apontamento_estado_id' => ['nullable', 'integer', Rule::exists('apontamentos_estado', 'id')],
            'ordem_producao_id' => ['nullable', 'integer', Rule::exists('ordens_producao', 'id')],
        ], [
            'titulo.required' => 'Escreva um título resumido.',
            'area_executante_id.required' => 'Escolha a área executante (quem vai fazer o serviço).',
            'descricao.required' => 'Descreva o problema ou o serviço.',
        ]);
        $dados['maquina_parada'] = (bool) ($dados['maquina_parada'] ?? false);
        if (! $this->areasPermitidas($request, $ss)->contains('id', (int) $dados['area_executante_id'])
            && ! ($ss->exists && (int) $ss->area_executante_id === (int) $dados['area_executante_id'])) {
            throw ValidationException::withMessages(['area_executante_id' => 'Você não é solicitante da área desta área executante.']);
        }

        $area = AreaExecutante::withoutGlobalScopes()->find($dados['area_executante_id']);
        $maquina = isset($dados['maquina_id']) ? Maquina::withoutGlobalScopes()->find($dados['maquina_id']) : null;

        // Centro de custo: o da máquina (travado); sem máquina ou máquina sem CC, o informado ou o do autor; senão é obrigatório.
        $ccMaquina = trim((string) $maquina?->centro_custo);
        $autor = $ss->exists ? ($ss->solicitante ?? $request->user()) : $request->user();
        $cc = $ccMaquina !== '' ? $ccMaquina : (trim((string) ($dados['centro_custo'] ?? '')) ?: (string) AcessoOs::centroCustoDo($autor));
        if ($cc === '') {
            throw ValidationException::withMessages(['centro_custo' => 'Selecione o centro de custo.']);
        }
        if ($ccMaquina === '' && ! AcessoOs::centroCustoExiste($cc)) {
            throw ValidationException::withMessages(['centro_custo' => 'Escolha um centro de custo da lista.']);
        }
        $dados['centro_custo'] = $cc;
        $dados['unidade'] = OrdemServico::unidadePara($area, $maquina);
        $ehAprovadorEditando = $ss->exists && $ss->solicitante_id !== $request->user()->id && AcessoOs::podeAprovar($request->user());
        if (! $ehAprovadorEditando && ! in_array($dados['unidade'], AcessoOs::unidadesSolicitante($request->user()), true)) {
            throw ValidationException::withMessages(['area_executante_id' => 'Você não é solicitante de '.(config('oee.unidades')[$dados['unidade']] ?? $dados['unidade']).'.']);
        }
        $ss->fill($dados)->save();
    }
}
