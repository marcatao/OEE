<?php

namespace App\Http\Controllers;

use App\Services\CalculadoraOee;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class PortalController extends Controller
{
    public function inicio(): View
    {
        return view('inicio');
    }

    public function calculadora(Request $request, CalculadoraOee $calculadora): View
    {
        $resultado = null;
        $erro = null;

        if ($request->isMethod('post')) {
            $dados = $request->validate([
                'tempo_planejado' => 'required|numeric|min:0',
                'paradas' => 'required|numeric|min:0',
                'ciclo_ideal' => 'required|numeric|min:0',
                'total_produzido' => 'required|integer|min:0',
                'pecas_boas' => 'required|integer|min:0',
            ], array_fill_keys(['required', 'numeric', 'integer', 'min'], 'Preencha todos os campos com números válidos.'));

            try {
                $resultado = $calculadora->calcular(
                    (float) $dados['tempo_planejado'],
                    (float) $dados['paradas'],
                    (float) $dados['ciclo_ideal'],
                    (int) $dados['total_produzido'],
                    (int) $dados['pecas_boas'],
                );
            } catch (InvalidArgumentException $e) {
                $erro = $e->getMessage();
            }
        }

        return view('calculadora', ['resultado' => $resultado, 'erro' => $erro]);
    }

    public function perfil(): View
    {
        return view('perfil');
    }
}
