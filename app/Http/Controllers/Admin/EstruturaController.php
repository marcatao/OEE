<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Linha;
use App\Models\Planta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administração > Plantas e linhas: a estrutura onde as máquinas ficam (Planta > Linha > Máquina).
 */
class EstruturaController extends Controller
{
    private const MENSAGENS = [
        'codigo.required' => 'Informe o código.',
        'nome.required' => 'Informe o nome.',
        'codigo.unique' => 'Esse código já está em uso.',
        'planta_id.required' => 'Escolha a planta da linha.',
        '*.max' => 'Texto muito longo.',
    ];

    public function index(): View
    {
        return view('admin.estrutura', [
            'plantas' => Planta::with(['linhas' => fn ($q) => $q->withCount('maquinas')->orderBy('nome')])->orderBy('nome')->get(),
        ]);
    }

    public function salvarPlanta(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'max:20', Rule::unique('plantas', 'codigo')],
            'nome' => ['required', 'string', 'max:120'],
        ], self::MENSAGENS);
        $planta = Planta::create($dados + ['ativo' => true]);

        return redirect()->route('admin.estrutura')->with('ok', "Planta {$planta->nome} cadastrada.");
    }

    public function atualizarPlanta(Request $request, Planta $planta): RedirectResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'ativo' => ['required', 'boolean'],
        ], self::MENSAGENS);
        $planta->update($dados);

        return redirect()->route('admin.estrutura')->with('ok', "Planta {$planta->nome} atualizada.");
    }

    public function salvarLinha(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'planta_id' => ['required', 'integer', Rule::exists('plantas', 'id')],
            'codigo' => ['required', 'string', 'max:20',
                Rule::unique('linhas', 'codigo')->where(fn ($q) => $q->where('planta_id', $request->integer('planta_id')))],
            'nome' => ['required', 'string', 'max:120'],
        ], self::MENSAGENS);
        $linha = Linha::create($dados + ['ativo' => true]);

        return redirect()->route('admin.estrutura')->with('ok', "Linha {$linha->nome} cadastrada.");
    }

    public function atualizarLinha(Request $request, Linha $linha): RedirectResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'ativo' => ['required', 'boolean'],
        ], self::MENSAGENS);
        $linha->update($dados);

        return redirect()->route('admin.estrutura')->with('ok', "Linha {$linha->nome} atualizada.");
    }
}
