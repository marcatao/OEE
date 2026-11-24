<?php

namespace App\Http\Controllers\Aplicativo;

use App\Http\Controllers\Controller;
use App\Models\OrdemProducao;
use App\Models\PerdaOp;
use App\Services\PerdasOrdemProducao;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Apontamento de perdas no aplicativo (botão na tela da máquina): listar, incluir e excluir as perdas de uma OP, com a
 * calculadora pelo PU. Mesma regra e mesma permissão (perdas_op) da aba da tela da OP no portal.
 */
class PerdasAplicativoController extends Controller
{
    public function __construct(private PerdasOrdemProducao $perdas) {}

    /** Lista da OP (com as excluídas, marcadas), PU e totais. */
    public function index(int $ordem): JsonResponse
    {
        return response()->json($this->lista($this->op($ordem)));
    }

    /** Inclui uma perda: quantidade e/ou peso (o outro é calculado pelo PU), dia, máquina e observação opcionais. */
    public function store(Request $request, int $ordem): JsonResponse
    {
        $op = $this->op($ordem);
        $dados = $request->validate([
            'quantidade' => ['nullable', 'numeric', 'min:0'],
            'peso' => ['nullable', 'numeric', 'min:0'],
            'dia' => ['nullable', 'date_format:Y-m-d'],
            'maquina_id' => ['nullable', 'integer'],
            'observacao' => ['nullable', 'string', 'max:500'],
        ]);
        $this->perdas->registrar($op, $request->user(), $dados, 'aplicativo');

        return response()->json($this->lista($op), 201);
    }

    public function destroy(Request $request, int $perda): JsonResponse
    {
        $perda = PerdaOp::findOrFail($perda);
        $this->perdas->excluir($perda, $request->user());

        return response()->json($this->lista($this->op((int) $perda->ordem_producao_id)));
    }

    private function op(int $id): OrdemProducao
    {
        $op = OrdemProducao::findOrFail($id);
        abort_if($op->d_e_l_e_t_ === '*', 404);

        return $op;
    }

    private function lista(OrdemProducao $op): array
    {
        $perdas = $this->perdas->daOp($op);

        return ['op' => ['id' => (int) $op->id, 'numero' => $op->numero, 'produto' => rtrim((string) $op->c2_produto),
            'quantidade' => (float) $op->c2_quant]]
            + $this->perdas->resumo($op, $perdas)
            + ['perdas' => $perdas->map(fn (PerdaOp $p) => [
                'id' => (int) $p->id,
                'dia' => $p->dia->format('Y-m-d'),
                'quantidade' => (float) $p->quantidade,
                'peso' => $p->peso === null ? null : (float) $p->peso,
                'peso_unitario' => $p->peso_unitario === null ? null : (float) $p->peso_unitario,
                'texto' => Formato::quantidade($p->quantidade).' pç'.($p->peso !== null ? ' · '.Formato::quantidade($p->peso).' kg' : ''),
                'maquina' => $p->maquina ? trim($p->maquina->codigo.' '.$p->maquina->nome) : null,
                'observacao' => $p->observacao,
                'origem' => $p->origem,
                'criado_por' => $p->criadoPor?->nome,
                'criado_em' => $p->criado_em?->toIso8601String(),
                'excluida' => $p->excluida(),
                'excluido_por' => $p->excluidoPor?->nome,
                'excluido_em' => $p->excluido_em?->toIso8601String(),
            ])->values()->all()];
    }
}
