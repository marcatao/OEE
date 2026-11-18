<?php

namespace App\Http\Controllers\Aplicativo;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Manutencao\SolicitacaoController;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Services\Aplicativo\OrdensServicoAplicativo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** API da função "Ordens de serviço" do aplicativo. Regras em App\Services\Aplicativo\OrdensServicoAplicativo. */
class OrdensServicoAplicativoController extends Controller
{
    public function __construct(private OrdensServicoAplicativo $os) {}

    public function resumo(Request $request): JsonResponse
    {
        return response()->json($this->os->resumo($request->user()));
    }

    public function solicitacoes(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json([
            'pendentes' => $this->os->pendentesComRecusa($u),
            'minhas' => $this->os->minhasSolicitacoes($u)->map(fn ($ss) => $this->os->ssPublica($ss))->values(),
        ]);
    }

    public function formulario(Request $request): JsonResponse
    {
        return response()->json($this->os->formulario($request->user()));
    }

    /** Abrir SS: as mesmas validações e regras da tela do portal (SolicitacaoController::store). */
    public function abrir(Request $request): JsonResponse
    {
        app(SolicitacaoController::class)->store($request);
        $ss = SolicitacaoServico::where('solicitante_id', $request->user()->id)->latest('id')->firstOrFail();

        return response()->json(['ok' => true, 'mensagem' => "Solicitação {$ss->numeroFormatado()} enviada para a manutenção.",
            'solicitacao' => $this->os->ssPublica($ss)]);
    }

    public function assumir(Request $request, int $solicitacao): JsonResponse
    {
        $os = $this->os->assumir($request->user(), $solicitacao);

        return response()->json(['ok' => true, 'mensagem' => "Você assumiu: emitida a {$os->numeroFormatado()}.",
            'ordem' => $this->os->detalhe($request->user(), $this->os->buscarOs($request->user(), $os->id))]);
    }

    /** Consulta leve a cada toque do alarme: SS que ainda devem tocar para o usuário. */
    public function alerta(Request $request): JsonResponse
    {
        return response()->json(['pendentes' => $this->os->alerta($request->user())]);
    }

    /** Recusar a SS: só ela para de tocar para este mantenedor; o histórico registra "SS não aceita por …". */
    public function recusar(Request $request, int $solicitacao): JsonResponse
    {
        $this->os->recusar($request->user(), $solicitacao);

        return response()->json(['ok' => true, 'pendentes' => $this->os->alerta($request->user())]);
    }

    public function rejeitar(Request $request, int $solicitacao): JsonResponse
    {
        $dados = $request->validate(['motivo' => ['required', 'string', 'max:500']], ['motivo.required' => 'Escreva o motivo da rejeição.']);
        $this->os->rejeitar($request->user(), $solicitacao, $dados['motivo']);

        return response()->json(['ok' => true]);
    }

    public function ordens(Request $request): JsonResponse
    {
        return response()->json(['ordens' => $this->os->listaOrdens($request->user())]);
    }

    public function avaliacoes(Request $request): JsonResponse
    {
        return response()->json(['ordens' => $this->os->listaAvaliacoes($request->user())]);
    }

    public function show(Request $request, int $ordem): JsonResponse
    {
        return response()->json($this->os->detalhe($request->user(), $this->os->buscarOs($request->user(), $ordem)));
    }

    public function iniciar(Request $request, int $ordem): JsonResponse
    {
        $this->os->iniciar($request->user(), $ordem);

        return $this->show($request, $ordem);
    }

    public function encerrar(Request $request, int $apontamento): JsonResponse
    {
        $dados = $request->validate([
            'descricao' => ['required', 'string', 'max:1000'],
            'concluir_os' => ['nullable', 'boolean'],
            'maquina_parada' => ['nullable', 'boolean'],
            'fim_utc' => ['nullable', 'string', 'max:40'],
        ], ['descricao.required' => 'Descreva o serviço feito neste apontamento.']);
        $os = $this->os->encerrar($request->user(), $apontamento, $dados['descricao'], (bool) ($dados['concluir_os'] ?? false),
            array_key_exists('maquina_parada', $dados) && $dados['maquina_parada'] !== null ? (bool) $dados['maquina_parada'] : null,
            $dados['fim_utc'] ?? null);

        return $this->show($request, $os->id);
    }

    public function acao(Request $request, int $ordem): JsonResponse
    {
        $dados = $request->validate(['acao' => ['required', 'string'], 'observacao' => ['nullable', 'string', 'max:2000']]);
        $this->os->acao($request->user(), $ordem, $dados['acao'], $dados['observacao'] ?? null);

        return $this->show($request, $ordem);
    }

    public function avaliar(Request $request, int $ordem): JsonResponse
    {
        $dados = $request->validate([
            'avaliacao' => ['required', Rule::in(array_keys(OrdemServico::AVALIACOES))],
            'observacao' => ['nullable', 'string', 'max:500'],
            'assinatura' => ['required', 'string'],
            'nome' => ['nullable', 'string', 'max:120'],
        ], ['avaliacao.required' => 'Escolha Excelente, Boa ou Ruim.', 'assinatura.required' => 'Peça para o solicitante assinar no quadro.']);
        $os = $this->os->avaliar($request->user(), $ordem, $dados['avaliacao'], $dados['observacao'] ?? null, $dados['assinatura'], $dados['nome'] ?? null);

        return response()->json(['ok' => true, 'status' => $os->status, 'mensagem' => $os->status === 'encerrada'
            ? 'Avaliação registrada: OS encerrada.' : 'Avaliação Ruim registrada: a OS voltou para a manutenção.']);
    }
}
