<?php

namespace App\Http\Controllers;

use App\Models\Assistente\Conversa;
use App\Models\Assistente\Pergunta;
use App\Services\Assistente\AssistenteAlumbra;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * "Pergunte para Alumbra" (box da página inicial). Respostas em JSON; a tela grava a pergunta (perguntar) e chama
 * etapa até a resposta ficar pronta. Cada usuário só vê e continua as próprias conversas.
 */
class AssistenteController extends Controller
{
    public function __construct(private AssistenteAlumbra $assistente) {}

    /** Última conversa do usuário (para reabrir o box depois de recarregar a página). */
    public function conversa(Request $request): JsonResponse
    {
        $conversa = Conversa::where('usuario_id', $request->user()->id)
            ->where('atualizado_em', '>=', now()->subHours(12))
            ->latest('atualizado_em')->first();

        return response()->json([
            'configurado' => $this->assistente->configurado(),
            'restantes' => $this->assistente->restantesHoje($request->user()),
            'conversa_id' => $conversa?->id,
            'itens' => $conversa ? $this->assistente->paraTela($conversa) : [],
        ]);
    }

    public function perguntar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'pergunta' => 'required|string|max:2000',
            'conversa_id' => 'nullable|integer',
        ], [
            'pergunta.required' => 'Escreva a sua pergunta.',
            'pergunta.max' => 'A pergunta pode ter até 2.000 caracteres.',
        ]);
        $usuario = $request->user();

        if (! $this->assistente->configurado()) {
            return response()->json(['mensagem' => 'O assistente ainda não foi configurado (falta a chave da API da Claude).'], 503);
        }
        // Conta e grava sob trava por usuário: perguntas em paralelo não passam do limite diário.
        $pergunta = Cache::lock("assistente-pergunta-{$usuario->id}", 30)->block(10, function () use ($usuario, $dados) {
            return $this->assistente->restantesHoje($usuario) === 0 ? null
                : $this->assistente->perguntar($usuario, $dados['pergunta'], $dados['conversa_id'] ?? null);
        });
        if (! $pergunta) {
            return response()->json(['mensagem' => 'Você atingiu o limite de perguntas de hoje. Amanhã tem mais.'], 429);
        }

        return response()->json([
            'pergunta_id' => $pergunta->id,
            'conversa_id' => $pergunta->conversa_id,
            'restantes' => $this->assistente->restantesHoje($usuario),
        ]);
    }

    public function etapa(Request $request, int $pergunta): JsonResponse
    {
        $pergunta = Pergunta::where('usuario_id', $request->user()->id)->findOrFail($pergunta);

        // Uma etapa por vez na mesma pergunta (duas abas, clique duplo).
        $trava = Cache::lock("assistente-pergunta-{$pergunta->id}", (int) config('assistente.timeout', 60) + 30);
        if (! $trava->get()) {
            return response()->json(['situacao' => 'continuar', 'aguardar' => true, 'acoes' => []]);
        }
        try {
            @set_time_limit((int) config('assistente.timeout', 60) + 60);

            return response()->json($this->assistente->etapa($request->user(), $pergunta->fresh()));
        } finally {
            $trava->release();
        }
    }
}
