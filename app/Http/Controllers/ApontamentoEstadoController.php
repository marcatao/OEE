<?php

namespace App\Http\Controllers;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\ProdutoProtheus;
use App\Providers\AppServiceProvider;
use App\Services\StatusOrdemProducao;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Produção > Apontamento real: registra em que estado (legenda) cada máquina ficou, com horário inicial e final.
 * São os dados da Timeline. Horários digitados no fuso local e gravados em UTC.
 */
class ApontamentoEstadoController extends Controller
{
    public function index(Request $request): View
    {
        $fuso = config('oee.fuso_exibicao');
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('data')) ? $request->query('data') : null;

        return view('apontamentos.index', [
            'maquinas' => Maquina::orderBy('nome')->get(['id', 'codigo', 'nome']),
            'filtroMaquina' => $request->integer('maquina_id') ?: null,
            'filtroData' => $data,
            'apontamentos' => ApontamentoEstado::with(['maquina', 'legenda', 'criadoPor', 'ordemProducao'])
                ->whereHas('maquina') // só das máquinas que o usuário enxerga (unidade: manutenção / tecnologia)
                ->when($request->integer('maquina_id'), fn ($q, $id) => $q->where('maquina_id', $id))
                ->when($data, function ($q, $d) use ($fuso) {
                    $ini = CarbonImmutable::parse($d, $fuso)->startOfDay();
                    $q->where('inicio', '<', $ini->addDay()->utc())
                        ->where(fn ($w) => $w->whereNull('fim')->orWhere('fim', '>', $ini->utc()));
                })
                ->orderByDesc('inicio')->paginate(30)->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        $apontamento = new ApontamentoEstado([
            'maquina_id' => $request->integer('maquina_id') ?: null,
            'inicio' => now()->startOfMinute(),
        ]);

        return $this->formulario($apontamento, $request->query('voltar') === 'timeline' ? 'timeline' : 'lista');
    }

    public function store(Request $request): RedirectResponse
    {
        $apontamento = new ApontamentoEstado;
        $encerrado = $this->salvar($request, $apontamento);

        return $this->voltar($request, $apontamento, 'Apontamento registrado.'.($encerrado ? " O estado anterior ({$encerrado}) foi encerrado nesse horário." : ''));
    }

    public function edit(Request $request, ApontamentoEstado $apontamento): View
    {
        return $this->formulario($apontamento, $request->query('voltar') === 'timeline' ? 'timeline' : 'lista');
    }

    public function update(Request $request, ApontamentoEstado $apontamento): RedirectResponse
    {
        $this->salvar($request, $apontamento);

        return $this->voltar($request, $apontamento, 'Apontamento atualizado.');
    }

    public function destroy(Request $request, ApontamentoEstado $apontamento, StatusOrdemProducao $status): RedirectResponse
    {
        $op = $apontamento->ordemProducao;
        $apontamento->delete();
        $status->atualizar($op);

        return $this->voltar($request, $apontamento, 'Apontamento excluído.');
    }

    private function formulario(ApontamentoEstado $apontamento, string $voltar): View
    {
        // Lista de OPs para escolher: as que ainda podem ser apontadas (não fechadas), mais recentes primeiro.
        $ordens = OrdemProducao::apontaveis()->orderByDesc('c2_emissao')->orderByDesc('numero')->limit(1000)
            ->get(['id', 'numero', 'c2_produto', 'c2_filial', 'status']);

        return view('apontamentos.form', [
            'apontamento' => $apontamento,
            'voltar' => $voltar,
            'maquinas' => Maquina::where(fn ($q) => $q->where('ativo', true)->orWhere('id', $apontamento->maquina_id))
                ->orderBy('nome')->get(['id', 'codigo', 'nome']),
            'legendas' => Legenda::where(fn ($q) => $q->where('ativo', true)->orWhere('id', $apontamento->legenda_id))
                ->orderBy('ordem')->orderBy('codigo')->get(),
            'ordens' => $ordens,
            // Descrição só dos produtos dessas OPs (a SB1 inteira seria pesada demais).
            'descricoes' => ProdutoProtheus::whereIn('b1_cod', $ordens->pluck('c2_produto')->unique()->values())
                ->pluck('b1_desc', 'b1_cod')->map(fn ($d) => rtrim($d)),
        ]);
    }

    /**
     * Valida e grava. Ao registrar um estado novo, encerra no novo horário inicial o estado que estava em aberto
     * (sem horário final) na mesma máquina. Recusa horários que se sobrepõem a outro apontamento.
     *
     * @return string|null código da legenda encerrada automaticamente
     */
    private function salvar(Request $request, ApontamentoEstado $apontamento): ?string
    {
        $dados = $request->validate([
            'maquina_id' => ['required', 'integer', Rule::exists('maquinas', 'id')],
            'legenda_id' => ['required', 'integer', Rule::exists('legendas', 'id')],
            'inicio' => ['required', 'date_format:Y-m-d\TH:i'],
            'fim' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:inicio'],
            'referencia' => ['nullable', 'string', 'max:40'],
            'ordem_producao' => ['nullable', 'string', 'max:20'],
            'observacao' => ['nullable', 'string', 'max:500'],
        ], [
            'maquina_id.required' => 'Escolha a máquina.',
            'legenda_id.required' => 'Escolha a legenda.',
            'inicio.required' => 'Informe o horário inicial.',
            'inicio.date_format' => 'Horário inicial inválido.',
            'fim.date_format' => 'Horário final inválido.',
            'fim.after' => 'O horário final deve ser depois do inicial.',
            '*.max' => 'Texto muito longo.',
        ]);

        // Ordem de produção: digitada ou escolhida pelo número (C2_NUM + C2_ITEM + C2_SEQUEN).
        $numeroOp = trim((string) ($dados['ordem_producao'] ?? ''));
        $legenda = Legenda::find($dados['legenda_id']);
        if ($numeroOp === '' && $legenda?->exige_op) {
            throw ValidationException::withMessages(['ordem_producao' => "A legenda {$legenda->codigo} — {$legenda->descricao} exige a ordem de produção."]);
        }
        $op = null;
        if ($numeroOp !== '') {
            $op = OrdemProducao::where('numero', $numeroOp)->where('d_e_l_e_t_', '<>', '*')->orderBy('status')->first();
            if (! $op) {
                throw ValidationException::withMessages(['ordem_producao' => "Ordem de produção {$numeroOp} não encontrada (sincronize com o Protheus em Banco de dados)."]);
            }
            if ($op->status === 'fechada' && $op->id !== $apontamento->ordem_producao_id) {
                throw ValidationException::withMessages(['ordem_producao' => "A ordem de produção {$numeroOp} está fechada no Protheus."]);
            }
        }
        $opAnterior = $apontamento->ordemProducao;

        $inicio = ApontamentoEstado::deHoraLocal($dados['inicio']);
        $fim = ApontamentoEstado::deHoraLocal($dados['fim'] ?? null);

        $afetadas = [];
        $encerrado = DB::transaction(function () use ($apontamento, $dados, $inicio, $fim, $op, &$afetadas) {
            $encerrado = null;
            if (! $apontamento->exists) {
                $aberto = ApontamentoEstado::with('legenda')->where('maquina_id', $dados['maquina_id'])
                    ->whereNull('fim')->where('inicio', '<', $inicio)->latest('inicio')->first();
                if ($aberto) {
                    $aberto->update(['fim' => $inicio]);
                    $encerrado = $aberto->legenda?->codigo;
                    $afetadas[] = $aberto->ordem_producao_id;
                }
            }

            $conflito = ApontamentoEstado::with('legenda')
                ->where('maquina_id', $dados['maquina_id'])
                ->when($apontamento->exists, fn ($q) => $q->whereKeyNot($apontamento->id))
                ->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>', $inicio))
                ->when($fim, fn ($q) => $q->where('inicio', '<', $fim))
                ->orderBy('inicio')->first();
            if ($conflito) {
                throw ValidationException::withMessages(['inicio' => sprintf(
                    'Esse horário se sobrepõe ao apontamento %s da mesma máquina (%s até %s). Ajuste os horários.',
                    $conflito->legenda?->codigo,
                    AppServiceProvider::dataHora($conflito->inicio),
                    $conflito->fim ? AppServiceProvider::dataHora($conflito->fim) : 'agora',
                )]);
            }

            $apontamento->fill([
                'maquina_id' => $dados['maquina_id'],
                'legenda_id' => $dados['legenda_id'],
                'inicio' => $inicio,
                'fim' => $fim,
                'referencia' => $dados['referencia'] ?? null,
                'ordem_producao_id' => $op?->id,
                'observacao' => $dados['observacao'] ?? null,
            ])->save();

            return $encerrado;
        });

        // Status das OPs envolvidas (a nova, a anterior deste apontamento e a do estado encerrado).
        $status = app(StatusOrdemProducao::class);
        foreach (array_unique(array_filter([$op?->id, $opAnterior?->id, ...$afetadas])) as $id) {
            $status->atualizar(OrdemProducao::find($id));
        }

        return $encerrado;
    }

    private function voltar(Request $request, ApontamentoEstado $apontamento, string $mensagem): RedirectResponse
    {
        if ($request->input('voltar') === 'timeline') {
            $dia = $apontamento->inicio?->copy()->setTimezone(config('oee.fuso_exibicao'))->format('Y-m-d');

            return redirect()->route('timeline', array_filter(['data' => $dia]))->with('ok', $mensagem);
        }

        return redirect()->route('apontamentos.index')->with('ok', $mensagem);
    }
}
