<?php

namespace App\Http\Controllers;

use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\PerdaOp;
use App\Services\PerdasOrdemProducao;
use App\Support\Formato;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Aba "Apontamento de perdas" da tela da OP (permissão perdas_op): grava e exclui perdas pela regra única de
 * App\Services\PerdasOrdemProducao (a mesma do aplicativo).
 */
class PerdaOpController extends Controller
{
    public function store(Request $request, OrdemProducao $ordem, PerdasOrdemProducao $perdas): RedirectResponse
    {
        $dados = $request->validate([
            'quantidade' => ['nullable', 'string', 'max:20'],
            'peso' => ['nullable', 'string', 'max:20'],
            'dia' => ['nullable', 'date_format:Y-m-d'],
            'maquina_id' => ['required', 'integer', function ($campo, $valor, $falhar) {
                if ($valor && ! Maquina::whereKey((int) $valor)->exists()) {
                    $falhar('Máquina não encontrada.');
                }
            }],
            'observacao' => ['nullable', 'string', 'max:500'],
        ], ['dia.date_format' => 'Dia inválido.', 'maquina_id.required' => 'Escolha a máquina em que a perda aconteceu.', '*.max' => 'Texto muito longo.']);
        $perda = $perdas->registrar($ordem, $request->user(), $dados);

        return redirect()->to(route('ordens-producao.show', $ordem).'#aba-perdas')
            ->with('ok', 'Perda apontada: '.Formato::quantidade($perda->quantidade).' pç'.($perda->peso ? ' ('.Formato::quantidade($perda->peso).' kg)' : '').'.');
    }

    public function destroy(Request $request, PerdaOp $perda, PerdasOrdemProducao $perdas): RedirectResponse
    {
        $perdas->excluir($perda, $request->user());

        return redirect()->to(route('ordens-producao.show', $perda->ordem_producao_id).'#aba-perdas')
            ->with('ok', 'Perda de '.Formato::quantidade($perda->quantidade).' pç excluída (descontada do total da OP).');
    }
}
