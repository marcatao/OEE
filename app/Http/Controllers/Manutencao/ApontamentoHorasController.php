<?php

namespace App\Http\Controllers\Manutencao;

use App\Http\Controllers\Controller;
use App\Models\ApontamentoEstado;
use App\Models\Manutencao\ApontamentoHoras;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\OrdemServico;
use App\Services\Manutencao\FluxoOrdemServico;
use App\Support\AcessoOs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Apontamento de horas da OS: o mantenedor inicia e o tempo corre até ele encerrar (com a descrição do serviço). Quem
 * aprova também lança/corrige à mão por qualquer mantenedor ("lançado por" = usuário logado).
 */
class ApontamentoHorasController extends Controller
{
    public function __construct(private FluxoOrdemServico $fluxo) {}

    /** Tela do mantenedor (boa no celular): o apontamento rodando e as OS dele/da fila para começar. */
    public function minhas(Request $request): View
    {
        $u = $request->user();
        $mantenedor = AcessoOs::mantenedorDo($u);
        $rodando = $mantenedor ? ApontamentoHoras::where('mantenedor_id', $mantenedor->id)->whereNull('fim')->with('ordemServico')->first() : null;
        $ordens = AcessoOs::filtrar(OrdemServico::query(), $u)
            ->whereIn('status', ['aberta', 'em_execucao', 'aguardando_material'])
            // As dele (principal ou atribuído) e as ainda sem ninguém.
            ->when($mantenedor, fn ($q) => $q->where(fn ($q) => $q->where('responsavel_id', $mantenedor->id)
                ->orWhereHas('mantenedores', fn ($m) => $m->where('os_mantenedores.id', $mantenedor->id))
                ->orWhere(fn ($q) => $q->whereNull('responsavel_id')->whereDoesntHave('mantenedores'))))
            ->with(['maquina' => fn ($q) => $q->withoutGlobalScopes(), 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByRaw("CASE prioridade WHEN 'urgente' THEN 0 WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END")->orderBy('id')
            ->limit(100)->get();
        $hoje = $mantenedor ? ApontamentoHoras::where('mantenedor_id', $mantenedor->id)->where('inicio', '>=', now(config('oee.fuso_exibicao'))->startOfDay()->utc())
            ->with('ordemServico')->orderByDesc('inicio')->get() : collect();

        return view('manutencao.apontamentos.minhas', compact('mantenedor', 'rodando', 'ordens', 'hoje'));
    }

    public function iniciar(Request $request, int $ordem): RedirectResponse
    {
        $os = $this->buscarOs($request, $ordem);
        AcessoOs::exigirAberta($os);
        $this->exigirArea($request, $os);
        $mantenedor = $this->mantenedor($request, $os);
        $this->fluxo->iniciarApontamento($os, $mantenedor, $request->user());

        return back()->with('ok', "Apontamento iniciado na {$os->numeroFormatado()} para {$mantenedor->nome}.");
    }

    public function encerrar(Request $request, int $apontamento): RedirectResponse
    {
        $ap = $this->buscar($request, $apontamento);
        abort_unless($ap->fim === null, 422, 'Este apontamento já foi encerrado.');
        $dados = $request->validate(['descricao' => ['required', 'string', 'max:1000'], 'concluir_os' => ['nullable', 'boolean']],
            ['descricao.required' => 'Descreva o serviço feito neste apontamento.']);
        $this->fluxo->encerrarApontamento($ap, $dados['descricao']);

        // "Concluir a OS também": encerra os outros apontamentos rodando e conclui com a data deste (o solicitante avalia).
        if ($request->boolean('concluir_os')) {
            $os = $ap->ordemServico()->first();
            abort_unless(AcessoOs::podeExecutar($request->user(), $os), 403, 'Esta OS não é de uma área executante que você atende.');
            AcessoOs::exigirAberta($os);
            $this->fluxo->executarAcao($os, 'concluir', $request->user(), $dados['descricao']);

            return redirect()->route('os.ordens.show', $os)->with('ok', 'Apontamento encerrado e OS concluída: agora é com o solicitante, que vai avaliar.');
        }

        return back()->with('ok', 'Apontamento encerrado. A OS continua aberta.');
    }

    /** Lançamento manual (início e fim informados). */
    public function store(Request $request, int $ordem): RedirectResponse
    {
        $os = $this->buscarOs($request, $ordem);
        AcessoOs::exigirAberta($os);
        abort_unless(AcessoOs::podeAprovar($request->user(), $os), 403, 'Esta OS não é de uma área executante que você atende.');
        $dados = $this->validar($request);
        // Só mantenedor ATIVO e da unidade que o usuário enxerga.
        $mantenedor = Mantenedor::where('ativo', true)->findOrFail($dados['mantenedor_id']);
        $ap = new ApontamentoHoras(['ordem_servico_id' => $os->id, 'mantenedor_id' => $mantenedor->id]);
        $ap->setRelation('mantenedor', $mantenedor);
        DB::transaction(function () use ($ap, $dados, $os, $request) {
            $this->gravar($ap, $dados);
            // Primeiras horas da OS: ela entra em atendimento, como no cronômetro.
            if ($os->status === 'aberta') {
                $this->fluxo->executarAcao($os, 'iniciar', $request->user(), 'Primeiras horas lançadas');
            }
        });
        $this->fluxo->registrar($os, null, 'horas_lancadas', $os->status, $os->status, "{$mantenedor->nome}: ".$ap->inicio->setTimezone(config('oee.fuso_exibicao'))->format('d/m H:i').' a '.$ap->fim->setTimezone(config('oee.fuso_exibicao'))->format('d/m H:i'), $request->user());

        return back()->with('ok', 'Horas lançadas.');
    }

    public function update(Request $request, int $apontamento): RedirectResponse
    {
        $ap = $this->buscar($request, $apontamento);
        $dados = $this->validar($request);
        // Quem não aprova OS da área só corrige o próprio apontamento (não troca o mantenedor).
        if (! AcessoOs::podeAprovar($request->user(), $ap->ordemServico)) {
            $dados['mantenedor_id'] = $ap->mantenedor_id;
        }
        $ap->mantenedor_id = $dados['mantenedor_id'];
        $ap->unsetRelation('mantenedor');
        $this->gravar($ap, $dados);

        return back()->with('ok', 'Apontamento corrigido.');
    }

    public function destroy(Request $request, int $apontamento): RedirectResponse
    {
        $ap = $this->buscar($request, $apontamento);
        abort_unless(AcessoOs::podeAprovar($request->user(), $ap->ordemServico), 403, 'Só quem aprova OS desta área pode excluir um apontamento.');
        $os = $ap->ordemServico;
        $texto = ($ap->mantenedor()->withoutGlobalScopes()->value('nome') ?? '').': '.$ap->inicio->setTimezone(config('oee.fuso_exibicao'))->format('d/m H:i');
        $ap->delete();
        $this->fluxo->recalcularTotal($os->id);
        $this->fluxo->registrar($os, null, 'horas_excluidas', $os->status, $os->status, $texto, $request->user());

        return back()->with('ok', 'Apontamento excluído.');
    }

    private function validar(Request $request): array
    {
        $dados = $request->validate([
            // Só mantenedores que o usuário enxerga (unidade).
            'mantenedor_id' => ['required', 'integer', function ($campo, $valor, $falhar) {
                if (! Mantenedor::whereKey((int) $valor)->exists()) {
                    $falhar('Mantenedor não encontrado.');
                }
            }],
            'inicio' => ['required', 'date'],
            'fim' => ['required', 'date'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'minutos_normais' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'minutos_extras' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], ['inicio.required' => 'Informe o início.', 'fim.required' => 'Informe o fim.']);
        $dados['inicio'] = ApontamentoEstado::deHoraLocal($dados['inicio']);
        $dados['fim'] = ApontamentoEstado::deHoraLocal($dados['fim']);
        if ($dados['fim'] <= $dados['inicio']) {
            throw ValidationException::withMessages(['fim' => 'O fim precisa ser depois do início.']);
        }
        if ($dados['inicio']->diffInHours($dados['fim']) > 24) {
            throw ValidationException::withMessages(['fim' => 'Um apontamento não pode passar de 24 horas. Lance em partes.']);
        }
        // Minutos à mão (normal × extra): a soma tem de bater com a duração (fim − início), para não inflar as extras.
        if (isset($dados['minutos_normais'], $dados['minutos_extras'])) {
            $duracao = (int) round(($dados['fim']->getTimestamp() - $dados['inicio']->getTimestamp()) / 60);
            if (abs((int) $dados['minutos_normais'] + (int) $dados['minutos_extras'] - $duracao) > 1) {
                throw ValidationException::withMessages(['minutos_extras' => "Normais + extras precisam somar a duração do apontamento ({$duracao} min)."]);
            }
        }

        return $dados;
    }

    private function gravar(ApontamentoHoras $ap, array $dados): void
    {
        // Minutos informados à mão valem só se vierem os dois; senão o portal separa normal × extra pela jornada.
        $manual = isset($dados['minutos_normais'], $dados['minutos_extras']);
        $this->fluxo->gravarApontamento($ap, $dados['inicio'], $dados['fim'], $dados['descricao'] ?? null,
            $manual ? (int) $dados['minutos_normais'] : null, $manual ? (int) $dados['minutos_extras'] : null);
    }

    /** O próprio mantenedor (pelo login) ou, para quem aprova, o mantenedor escolhido. */
    private function mantenedor(Request $request, OrdemServico $os): Mantenedor
    {
        if ($request->filled('mantenedor_id') && AcessoOs::podeAprovar($request->user(), $os)) {
            return Mantenedor::withoutGlobalScopes()->where('ativo', true)->findOrFail($request->integer('mantenedor_id'));
        }
        $m = AcessoOs::mantenedorDo($request->user());
        if (! $m) {
            throw ValidationException::withMessages(['mantenedor_id' => 'Seu login não está ligado a um mantenedor. Peça para um administrador ligar em Ordens de serviço › Mantenedores.']);
        }

        return $m;
    }

    private function exigirArea(Request $request, OrdemServico $os): void
    {
        abort_unless(AcessoOs::podeExecutar($request->user(), $os), 403, 'Esta OS não é de uma área executante que você atende.');
    }

    private function buscarOs(Request $request, int $id): OrdemServico
    {
        return AcessoOs::filtrar(OrdemServico::query(), $request->user())->findOrFail($id);
    }

    private function buscar(Request $request, int $id): ApontamentoHoras
    {
        $ap = ApontamentoHoras::findOrFail($id);
        $os = $this->buscarOs($request, $ap->ordem_servico_id);
        AcessoOs::exigirAberta($os); // corrigir, encerrar ou excluir apontamento só com a OS aberta
        $u = $request->user();
        $meu = AcessoOs::mantenedorDo($u);
        abort_unless(AcessoOs::podeAprovar($u, $os) || ($meu && (int) $meu->id === (int) $ap->mantenedor_id), 403, 'Você só pode mexer nos seus apontamentos.');

        return $ap;
    }
}
