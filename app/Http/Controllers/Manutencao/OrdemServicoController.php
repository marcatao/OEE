<?php

namespace App\Http\Controllers\Manutencao;

use App\Http\Controllers\Controller;
use App\Models\CentroCusto;
use App\Models\Compras\PedidoCompra;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Services\Compras\PedidoCompraMuro;
use App\Services\Manutencao\CustoOrdemServico;
use App\Services\Manutencao\FluxoOrdemServico;
use App\Services\Manutencao\HorasManutencao;
use App\Services\Manutencao\SsAutomatica;
use App\Support\AcessoOs;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            'unidade' => (string) $request->input('unidade', ''),
            'de' => (string) $request->input('de', ''),
            'ate' => (string) $request->input('ate', ''),
        ];
        // Unidade: só as habilitadas nos perfis do usuário. Datas: emissão da OS, no fuso local.
        $unidades = AcessoOs::unidadesHabilitadas($u);
        if (! in_array($f['unidade'], $unidades, true)) {
            $f['unidade'] = '';
        }
        $de = $this->data($f['de']);
        $ate = $this->data($f['ate']);
        $f['de'] = $de?->format('Y-m-d') ?? '';
        $f['ate'] = $ate?->format('Y-m-d') ?? '';
        // Unidade e período valem também para os totais das abas de situação.
        $base = fn () => AcessoOs::filtrar(OrdemServico::query(), $u)
            ->when($f['unidade'] !== '', fn ($q) => $q->where('ordens_servico.unidade', $f['unidade']))
            ->when($de, fn ($q) => $q->where('ordens_servico.criado_em', '>=', $de->startOfDay()->utc()))
            ->when($ate, fn ($q) => $q->where('ordens_servico.criado_em', '<', $ate->addDay()->startOfDay()->utc()));
        $consulta = $base()
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
        $totais = $base()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('manutencao.ordens.index', [
            'ordens' => $consulta->orderByRaw("CASE prioridade WHEN 'urgente' THEN 0 WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END")->orderByDesc('id')
                ->paginate(30)->withQueryString(),
            'f' => $f,
            'totais' => $totais,
            'maquinas' => AcessoOs::maquinas(),
            'mantenedores' => AcessoOs::mantenedores(),
            'unidades' => $unidades,
        ]);
    }

    /** "AAAA-MM-DD" do filtro => início do dia no fuso local (inválida = sem filtro). */
    private function data(string $valor): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }
        $data = CarbonImmutable::createFromFormat('Y-m-d', $valor, HorasManutencao::fuso());

        return $data && $data->format('Y-m-d') === $valor ? $data->startOfDay() : null;
    }

    public function show(Request $request, int $ordem): View
    {
        $os = $this->buscar($request, $ordem);
        $u = $request->user();

        $historico = $os->historicoCompleto()->with('usuario')->orderByDesc('criado_em')->orderByDesc('id')->get();
        $materiais = $os->materiais()->with('criadoPor')->orderBy('id')->get();
        // Pedidos de compra da OS, com a situação lida agora na tabela muro do Protheus.
        $pedidos = PedidoCompra::with(['itens', 'criadoPor'])->where('ordem_servico_id', $os->id)->orderByDesc('id')->get();
        $muroOk = app(PedidoCompraMuro::class)->atualizarSemFalhar($pedidos);
        $adminDaUnidade = AcessoOs::ehAdminDa($u, $os->unidade);

        return view('manutencao.ordens.show', [
            'os' => $os,
            'marcos' => $this->marcos($os, $historico),
            // SQL Server ignora os espaços à direita do código do Protheus na comparação.
            'ccDescricao' => $os->centro_custo ? CentroCusto::withoutGlobalScope('unidade')->where('ctt_custo', trim((string) $os->centro_custo))->first()?->descricao() : null,
            'apontamentos' => $os->apontamentos()->with(['mantenedor' => fn ($q) => $q->withoutGlobalScopes(), 'criadoPor'])->orderBy('inicio')->get(),
            'materiais' => $materiais,
            'pedidos' => $pedidos,
            'muroOk' => $muroOk,
            'custo' => CustoOrdemServico::resumo($os, $pedidos, $materiais),
            'podeAdministrarPedidos' => $adminDaUnidade,
            'podeGerarPedido' => $adminDaUnidade && $os->estaAberta(),
            'centrosPedido' => $adminDaUnidade && $os->estaAberta() ? AcessoOs::centrosCusto() : collect(),
            'checklist' => $os->checklist()->with('respondidoPor')->orderBy('ordem')->orderBy('id')->get(),
            'historico' => $historico,
            'acoes' => FluxoOrdemServico::acoesPossiveis($os),
            'mantenedores' => AcessoOs::mantenedoresDaArea($os),
            'meuMantenedor' => AcessoOs::mantenedorDo($u),
            'podeAprovar' => AcessoOs::podeAprovar($u, $os),
            'podeExecutar' => AcessoOs::podeExecutar($u, $os),
            'podeCancelar' => AcessoOs::podeCancelar($u, $os),
            'podeAvaliar' => AcessoOs::podeAvaliar($u, $os),
            // Áreas executantes da unidade da OS (e de "Todas") para informar quem atendeu ao concluir.
            'areasConclusao' => $this->areasDaOs($os),
            // Avaliada (encerrada): nota, comentário e a assinatura feita no aplicativo (se houver). Concluída de novo depois
            // de uma avaliação Ruim ainda espera a nova avaliação.
            'avaliada' => $os->status === 'encerrada' && $os->avaliacao !== null,
            'assinatura' => $os->status === 'encerrada' ? $os->assinaturaDaAvaliacao() : null,
            'editavel' => $os->estaAberta(),
            // Interações (mensagens e anexos) e histórico juntos, em ordem cronológica, como uma conversa só.
            'conversa' => $this->conversa($os->interacoes()->with(['usuario', 'anexos'])->orderBy('id')->get(), $historico),
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
            'assinatura' => $os->status === 'encerrada' ? $os->assinaturaDaAvaliacao() : null,
        ]);
    }

    /**
     * Máquina parada / voltou a funcionar: único dado da OS que ainda muda depois de emitida (com a OS aberta), por quem
     * atende a OS. O resto da OS não se altera (os dados foram revisados na aprovação da SS).
     */
    public function maquinaParada(Request $request, int $ordem): RedirectResponse
    {
        $os = $this->buscar($request, $ordem);
        AcessoOs::exigirAberta($os);
        abort_unless(AcessoOs::podeExecutar($request->user(), $os), 403, 'Esta OS não é de uma área executante que você atende.');
        $parada = $request->boolean('maquina_parada');
        if ($parada !== (bool) $os->maquina_parada) {
            $os->forceFill(['maquina_parada' => $parada])->save();
            $this->fluxo->registrar($os, null, 'maquina_parada', $os->status, $os->status, $parada ? 'Máquina parada' : 'Máquina voltou a funcionar', $request->user());
        }

        return back()->with('ok', $parada ? 'OS marcada com máquina parada.' : 'Máquina marcada como funcionando.');
    }

    /** Distribui a OS para um ou mais mantenedores (nenhum = definir depois). */
    public function distribuir(Request $request, int $ordem): RedirectResponse
    {
        $os = $this->buscar($request, $ordem);
        AcessoOs::exigirAberta($os);
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
        // "iniciar" não vem da tela: a OS entra em atendimento no primeiro apontamento de horas (FluxoOrdemServico::iniciarApontamento).
        $dados = $request->validate(['acao' => ['required', Rule::in(array_diff(array_keys(FluxoOrdemServico::ACOES), ['iniciar']))],
            'observacao' => ['nullable', 'string', 'max:2000']]);
        if ($dados['acao'] !== 'reabrir') {
            AcessoOs::exigirAberta($os);
        }
        if (in_array($dados['acao'], ['cancelar', 'reabrir'], true)) {
            abort_unless(AcessoOs::podeCancelar($request->user(), $os), 403, 'Só o autor da solicitação ou o administrador da área pode cancelar ou reabrir.');
        } else {
            abort_unless(AcessoOs::podeExecutar($request->user(), $os), 403, 'Esta OS não é de uma área executante que você atende.');
        }
        if ($dados['acao'] === 'concluir') {
            $this->definirAreaNaConclusao($request, $os);
        }
        $this->fluxo->executarAcao($os, $dados['acao'], $request->user(), $dados['observacao'] ?? null);

        return redirect()->route('os.ordens.show', $os)->with('ok', 'OS '.mb_strtolower(OrdemServico::STATUS[$os->status]).'.');
    }

    /** Avaliação do solicitante (Excelente, Boa ou Ruim — Ruim reabre a OS e pede o motivo). */
    public function avaliar(Request $request, int $ordem): RedirectResponse
    {
        $os = $this->buscar($request, $ordem);
        $u = $request->user();
        abort_unless($os->status !== 'concluida' || AcessoOs::podeAvaliar($u, $os), 403,
            $os->automatica && SsAutomatica::solicitanteEhIot($os->solicitante_id)
                ? 'OS aberta pelo IoT: quem avalia é o administrador da unidade.' : 'Só quem solicitou o serviço pode avaliar a OS.');
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
        AcessoOs::exigirAberta($os);
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
    /** Áreas executantes ativas da unidade da OS (e as de "Todas"), mais a atual da OS. */
    private function areasDaOs(OrdemServico $os): Collection
    {
        $areas = AreaExecutante::withoutGlobalScopes()->where('ativo', true)->whereIn('unidade', [$os->unidade, 'ambos'])->orderBy('nome')->get();
        if ($os->areaExecutante && ! $areas->contains('id', $os->area_executante_id)) {
            $areas->prepend($os->areaExecutante);
        }

        return $areas;
    }

    /**
     * Concluir exige a área executante que atendeu (vem a da OS; pode corrigir para outra da mesma unidade). Mudou:
     * grava na OS e no histórico (os tempos e as metas do painel são por área).
     */
    private function definirAreaNaConclusao(Request $request, OrdemServico $os): void
    {
        $request->mergeIfMissing(['area_executante_id' => $os->area_executante_id]);
        $id = (int) $request->validate(['area_executante_id' => ['required', 'integer']],
            ['area_executante_id.required' => 'Informe a área executante que atendeu para concluir a OS.'])['area_executante_id'];
        $area = $this->areasDaOs($os)->firstWhere('id', $id);
        if (! $area) {
            throw ValidationException::withMessages(['area_executante_id' => 'Escolha uma área executante da unidade da OS para concluir.']);
        }
        if ((int) $os->area_executante_id !== $area->id) {
            $antes = $os->areaExecutante?->nome;
            $os->forceFill(['area_executante_id' => $area->id])->save();
            $this->fluxo->registrar($os, null, 'alterada', $os->status, $os->status, "Área executante na conclusão: {$antes} → {$area->nome}.", $request->user());
        }
    }

    /**
     * Mensagens e eventos do histórico numa lista só, do mais antigo para o mais novo (no mesmo instante, o evento
     * vem antes da mensagem). @return Collection<int, array{tipo: string, item: mixed}>
     */
    private function conversa(Collection $interacoes, Collection $historico): Collection
    {
        return $interacoes->map(fn ($m) => ['tipo' => 'mensagem', 'item' => $m])
            ->concat($historico->map(fn ($h) => ['tipo' => 'evento', 'item' => $h]))
            ->sortBy(fn ($l) => [$l['item']->criado_em?->getTimestamp() ?? 0, $l['tipo'] === 'evento' ? 0 : 1, $l['item']->id])
            ->values();
    }

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
