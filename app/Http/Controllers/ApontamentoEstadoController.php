<?php

namespace App\Http\Controllers;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Providers\AppServiceProvider;
use App\Services\CustoOrdemProducao;
use App\Services\HorarioApontamento;
use App\Services\Manutencao\SsAutomatica;
use App\Services\MotivoParada;
use App\Services\SituacaoMaquina;
use App\Services\StatusOrdemProducao;
use App\Services\TrocaOpMaquina;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
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
            'apontamentos' => ApontamentoEstado::with(['maquina', 'legenda', 'criadoPor', 'atualizadoPor', 'iotDispositivo', 'ordemProducao'])
                ->whereHas('maquina') // só das máquinas que o usuário enxerga (unidades dos perfis dele)
                ->when($request->integer('maquina_id'), fn ($q, $id) => $q->where('maquina_id', $id))
                ->when($data, function ($q, $d) use ($fuso) {
                    $ini = CarbonImmutable::parse($d, $fuso)->startOfDay();
                    $q->tocando($ini->utc(), $ini->addDay()->utc());
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
        // Máquina com OP ativa: o apontamento novo já vem com ela.
        if ($apontamento->maquina_id && ($maquina = Maquina::with('ordemProducao')->find($apontamento->maquina_id))?->ordemProducao) {
            $apontamento->ordem_producao_id = $maquina->ordem_producao_id;
            $apontamento->setRelation('ordemProducao', $maquina->ordemProducao);
        }

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
        $this->conferirMaquina($apontamento);

        return $this->formulario($apontamento, $request->query('voltar') === 'timeline' ? 'timeline' : 'lista');
    }

    public function update(Request $request, ApontamentoEstado $apontamento): RedirectResponse
    {
        $this->conferirMaquina($apontamento);
        $this->salvar($request, $apontamento);

        return $this->voltar($request, $apontamento, 'Apontamento atualizado.');
    }

    public function destroy(Request $request, ApontamentoEstado $apontamento, StatusOrdemProducao $status, SituacaoMaquina $situacao): RedirectResponse
    {
        $this->conferirMaquina($apontamento);
        // Apontamento que abriu SS/OS automática fica: a SS guarda a origem (exclusão daria erro de chave estrangeira).
        $ss = SolicitacaoServico::withoutGlobalScopes()->where('apontamento_estado_id', $apontamento->id)->first();
        $temOs = OrdemServico::withoutGlobalScopes()->where('apontamento_estado_id', $apontamento->id)->exists();
        if ($ss || $temOs) {
            return back()->with('erro', 'Este apontamento abriu '.($ss ? 'a SS '.$ss->numeroFormatado() : 'uma OS').' automática e não pode ser excluído. '
                .'Corrija o horário ou a legenda editando o apontamento.');
        }
        $op = $apontamento->ordemProducao;
        $eraOMaisRecente = (int) ApontamentoEstado::where('maquina_id', $apontamento->maquina_id)
            ->orderByDesc('inicio')->orderByDesc('id')->value('id') === (int) $apontamento->id; // (int): SQL Server devolve texto
        $apontamento->delete();
        HorarioApontamento::preencherBuracos((int) $apontamento->maquina_id, $apontamento->inicio); // o do meio vira 98
        $status->atualizar($op);
        if ($eraOMaisRecente) {
            $situacao->recalcular($apontamento->maquina_id); // a máquina volta a seguir o apontamento anterior
        }

        return $this->voltar($request, $apontamento, 'Apontamento excluído.');
    }

    /** Horário inicial para a máquina no dia (a tela preenche e trava o campo). JSON. */
    public function proximoInicio(Request $request, HorarioApontamento $regra): JsonResponse
    {
        $dados = $request->validate(['maquina_id' => ['required', 'integer'], 'dia' => ['required', 'date_format:Y-m-d'],
            'apontamento' => ['nullable', 'integer']]);
        abort_unless(Maquina::whereKey((int) $dados['maquina_id'])->exists(), 404); // máquina de outra unidade
        $editando = isset($dados['apontamento']) ? ApontamentoEstado::find($dados['apontamento']) : null;

        return response()->json($regra->proximoInicio((int) $dados['maquina_id'], $dados['dia'], $editando));
    }

    private function formulario(ApontamentoEstado $apontamento, string $voltar): View
    {
        $maquinas = Maquina::with('ordemProducao')->where(fn ($q) => $q->where('ativo', true)->orWhere('id', $apontamento->maquina_id))
            ->orderBy('nome')->get(['id', 'codigo', 'nome', 'ordem_producao_id']);

        $fuso = HorarioApontamento::fuso();
        $dia = $apontamento->inicio?->copy()->setTimezone($fuso)->format('Y-m-d') ?? now($fuso)->format('Y-m-d');

        return view('apontamentos.form', [
            'apontamento' => $apontamento,
            'voltar' => $voltar,
            'dia' => $dia,
            'horaInicio' => $apontamento->inicio?->copy()->setTimezone($fuso)->format('H:i:s'),
            'horaFim' => $apontamento->fim?->copy()->setTimezone($fuso)->format('H:i:s'),
            'proximo' => $apontamento->maquina_id ? app(HorarioApontamento::class)->proximoInicio((int) $apontamento->maquina_id, $dia, $apontamento) : null,
            'maquinas' => $maquinas,
            'legendas' => Legenda::where(fn ($q) => $q->where('ativo', true)->orWhere('id', $apontamento->legenda_id))
                ->orderBy('ordem')->orderBy('codigo')->get(),
            // Descrição do produto das OPs atuais das máquinas (texto da OP ao trocar a máquina no formulário).
            'produtosOp' => app(CustoOrdemProducao::class)->produtos($maquinas->pluck('ordemProducao')->filter()->map(fn ($op) => $op->c2_produto)),
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
        // Compatibilidade: "inicio"/"fim" no formato antigo (data e hora juntos) viram dia + horários.
        if (! $request->filled('dia') && $request->filled('inicio')) {
            $ini = preg_split('/[T ]/', (string) $request->input('inicio'));
            $fimAntigo = $request->filled('fim') ? preg_split('/[T ]/', (string) $request->input('fim')) : null;
            if ($fimAntigo && ($fimAntigo[0] ?? '') !== ($ini[0] ?? '')) {
                throw ValidationException::withMessages(['hora_fim' => 'O apontamento não pode passar de um dia: início e fim no mesmo dia.']);
            }
            $request->merge(['dia' => $ini[0] ?? null, 'hora_inicio' => $ini[1] ?? null, 'hora_fim' => $fimAntigo[1] ?? null]);
        }

        $hora = 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
        $dados = $request->validate([
            // Só máquinas e legendas que o usuário enxerga (unidades dos perfis dele); a legenda atual do apontamento vale.
            'maquina_id' => ['required', 'integer', function ($campo, $valor, $falhar) {
                if (! Maquina::whereKey((int) $valor)->exists()) {
                    $falhar('Máquina não encontrada.');
                }
            }],
            'legenda_id' => ['required', 'integer', function ($campo, $valor, $falhar) use ($apontamento) {
                if ((int) $valor !== (int) $apontamento->legenda_id && ! Legenda::whereKey((int) $valor)->exists()) {
                    $falhar('Legenda não encontrada.');
                }
            }],
            'dia' => ['required', 'date_format:Y-m-d'],
            'hora_inicio' => ['required', $hora],
            'hora_fim' => ['nullable', $hora],
            'referencia' => ['nullable', 'string', 'max:40'],
            'ordem_producao' => ['nullable', 'string', 'max:20'],
            'observacao' => ['nullable', 'string', 'max:500'],
        ], [
            'maquina_id.required' => 'Escolha a máquina.',
            'legenda_id.required' => 'Escolha a legenda.',
            'dia.required' => 'Informe o dia.',
            'dia.date_format' => 'Dia inválido.',
            'hora_inicio.required' => 'Informe o horário inicial.',
            'hora_inicio.regex' => 'Horário inicial inválido.',
            'hora_fim.regex' => 'Horário final inválido.',
            '*.max' => 'Texto muito longo.',
        ]);

        // Horários no dia escolhido (o apontamento não passa de um dia). Regra do encadeamento: se a máquina já tem
        // apontamento nesse dia, o início é 1 segundo depois do fim do último (ver HorarioApontamento).
        $inicio = HorarioApontamento::montar($dados['dia'], $dados['hora_inicio']);
        $fim = filled($dados['hora_fim'] ?? null) ? HorarioApontamento::montar($dados['dia'], $dados['hora_fim']) : null;
        if ($fim && $fim->lessThanOrEqualTo($inicio)) {
            throw ValidationException::withMessages(['hora_fim' => 'O horário final deve ser depois do inicial (no mesmo dia).']);
        }
        $regra = app(HorarioApontamento::class);
        $proximo = $regra->proximoInicio((int) $dados['maquina_id'], $dados['dia'], $apontamento);
        if ($proximo['completo']) {
            throw ValidationException::withMessages(['hora_inicio' => $proximo['mensagem']]);
        }
        if ($proximo['travado'] && HorarioApontamento::montar($dados['dia'], $proximo['hora'])->notEqualTo($inicio)) {
            throw ValidationException::withMessages(['hora_inicio' => 'O horário inicial deve ser '.$proximo['hora'].'. '.$proximo['mensagem']]);
        }
        $anterior = $regra->anteriorNoDia((int) $dados['maquina_id'], $dados['dia'], $apontamento);
        if ($anterior && $anterior->fim === null && $inicio->lessThanOrEqualTo($anterior->inicio)) {
            throw ValidationException::withMessages(['hora_inicio' => $proximo['mensagem']]);
        }

        // Ordem de produção: digitada ou escolhida pelo número (C2_NUM + C2_ITEM + C2_SEQUEN).
        $numeroOp = trim((string) ($dados['ordem_producao'] ?? ''));
        $legenda = Legenda::withoutGlobalScopes()->find($dados['legenda_id']);
        if ($numeroOp === '' && $legenda?->exige_op) {
            throw ValidationException::withMessages(['ordem_producao' => "A legenda {$legenda->codigo} — {$legenda->descricao} exige a ordem de produção."]);
        }
        $op = null;
        if ($numeroOp !== '') {
            $op = OrdemProducao::where('numero', $numeroOp)->where('d_e_l_e_t_', '<>', '*')->orderBy('status')->first();
            if (! $op) {
                throw ValidationException::withMessages(['ordem_producao' => "Ordem de produção {$numeroOp} não encontrada (sincronize com o Protheus em Banco de dados)."]);
            }
            if ($op->status === 'fechada' && (int) $op->id !== (int) $apontamento->ordem_producao_id) {
                throw ValidationException::withMessages(['ordem_producao' => "A ordem de produção {$numeroOp} está fechada no Protheus."]);
            }
        }
        $opAnterior = $apontamento->ordemProducao;
        $inicioAntes = $apontamento->inicio; // edição: o buraco pode ficar perto do horário antigo

        $afetadas = [];
        $encerrado = DB::transaction(function () use ($apontamento, $dados, $inicio, $fim, $op, &$afetadas) {
            $encerrado = null;
            if (! $apontamento->exists) {
                $aberto = ApontamentoEstado::with('legenda')->where('maquina_id', $dados['maquina_id'])
                    ->whereNull('fim')->where('inicio', '<', $inicio)->latest('inicio')->first();
                if ($aberto) {
                    // Encerra 1 segundo antes do novo, sem passar do dia dele (23:59:59); os dias seguintes ganham
                    // continuações (a madrugada não fica sem apontamento).
                    HorarioApontamento::encerrarAberto($aberto, $inicio);
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

        // Encurtou/mudou o horário: o buraco que sobrou vira 98 "Parada sem motivo" para ser editado.
        foreach (array_filter([$inicioAntes, $apontamento->inicio]) as $perto) {
            HorarioApontamento::preencherBuracos((int) $apontamento->maquina_id, $perto);
        }

        // Máquina: situação (pelo tipo da legenda) e OP atual, se este for o apontamento mais recente dela.
        app(SituacaoMaquina::class)->aplicar($apontamento);

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

            return redirect()->route('timeline', array_filter(['data' => $dia]))->with('ok', $mensagem)->with('ss_automatica', SsAutomatica::mensagem($apontamento->id));
        }

        return redirect()->route('apontamentos.index')->with('ok', $mensagem)->with('ss_automatica', SsAutomatica::mensagem($apontamento->id));
    }

    /**
     * Timeline › "Informar OP": grava a OP num apontamento cuja legenda exige OP e que ficou sem. A OP precisa estar
     * aberta (não fechada no Protheus). A máquina passa a ter essa OP (ver SituacaoMaquina::informarOp). Resposta JSON.
     */
    public function informarOp(Request $request, ApontamentoEstado $apontamento, StatusOrdemProducao $status, SituacaoMaquina $situacao): JsonResponse
    {
        $dados = $request->validate(['ordem_producao' => ['required', 'string', 'max:20']],
            ['ordem_producao.required' => 'Escolha a ordem de produção.']);
        abort_unless(Maquina::whereKey($apontamento->maquina_id)->exists(), 404); // máquina de outra unidade
        $op = OrdemProducao::apontaveis()->where('numero', trim($dados['ordem_producao']))->orderBy('status')->first();
        if (! $op) {
            throw ValidationException::withMessages(['ordem_producao' => 'Ordem de produção não encontrada ou fechada no Protheus.']);
        }
        $anterior = $apontamento->ordem_producao_id;
        $apontamento->update(['ordem_producao_id' => $op->id]);
        $situacao->informarOp($apontamento);
        foreach (array_unique(array_filter([$op->id, $anterior])) as $id) {
            $status->atualizar(OrdemProducao::find($id));
        }

        return response()->json(['ok' => true, 'mensagem' => "OP {$op->numero} informada no apontamento."]);
    }

    /**
     * Timeline › "Trocar OP": a máquina passa para a OP escolhida (só OPs abertas) e o apontamento em andamento segue o
     * modo — "atual" (o apontamento inteiro passa para a OP nova) ou "novo" (encerra agora com a OP anterior e começa
     * outro na mesma legenda com a OP nova). Regra em App\Services\TrocaOpMaquina (a mesma do aplicativo). JSON.
     */
    public function trocarOp(Request $request, int $maquina, TrocaOpMaquina $troca): JsonResponse
    {
        $dados = $request->validate([
            'ordem_producao' => ['required', 'string', 'max:20'],
            'modo' => ['required', Rule::in([TrocaOpMaquina::ATUAL, TrocaOpMaquina::NOVO])],
        ], ['ordem_producao.required' => 'Escolha a ordem de produção.', 'modo.required' => 'Escolha como trocar a OP.']);
        $registro = Maquina::findOrFail($maquina); // máquina de outra unidade: 404
        try {
            $op = $troca->trocar($registro, $dados['ordem_producao'], $dados['modo'], 'portal');
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['ordem_producao' => $e->validator->errors()->first()]);
        }

        return response()->json(['ok' => true, 'mensagem' => "{$registro->nome}: OP {$op->numero}."]);
    }

    /**
     * Timeline › "Informar motivo": a parada sem motivo (98) daquele apontamento passa para a legenda de parada
     * escolhida. Regra em App\Services\MotivoParada (a mesma do aplicativo). JSON.
     */
    public function informarMotivo(Request $request, ApontamentoEstado $apontamento, MotivoParada $motivo): JsonResponse
    {
        $dados = $request->validate(['legenda_id' => ['required', 'integer']], ['legenda_id.required' => 'Escolha o motivo da parada.']);
        abort_unless(Maquina::whereKey($apontamento->maquina_id)->exists(), 404); // máquina de outra unidade
        $motivo->informar($apontamento->load('legenda', 'ordemProducao'), (int) $dados['legenda_id']);

        // SS automática da legenda escolhida: a tela mostra se abriu ou por que não abriu.
        return response()->json(['ok' => true, 'mensagem' => SsAutomatica::mensagem($apontamento->id)]);
    }

    /** Apontamento de máquina que o usuário não enxerga (outra unidade): 404, como se não existisse. */
    private function conferirMaquina(ApontamentoEstado $apontamento): void
    {
        abort_unless(Maquina::whereKey($apontamento->maquina_id)->exists(), 404);
    }
}
