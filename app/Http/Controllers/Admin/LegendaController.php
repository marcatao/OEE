<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Legenda;
use App\Support\Unidades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administração > Legendas: estados da Timeline (sigla, descrição, tipo e COR da barra). Desativadas, nunca excluídas.
 */
class LegendaController extends Controller
{
    public function index(): View
    {
        return view('admin.legendas.index', [
            'legendas' => Legenda::withCount('apontamentos')->orderByDesc('ativo')->orderBy('ordem')->orderBy('codigo')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.legendas.form', ['legenda' => new Legenda([
            'ativo' => true, 'tipo' => 'parada', 'cor' => '#43a047', 'ordem' => (int) Legenda::max('ordem') + 10,
        ])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $legenda = new Legenda;
        $this->salvar($request, $legenda);

        return redirect()->route('admin.legendas.index')->with('ok', "Legenda {$legenda->codigo} cadastrada.");
    }

    public function edit(Legenda $legenda): View
    {
        return view('admin.legendas.form', ['legenda' => $legenda]);
    }

    public function update(Request $request, Legenda $legenda): RedirectResponse
    {
        $this->salvar($request, $legenda);

        return redirect()->route('admin.legendas.index')->with('ok', "Legenda {$legenda->codigo} atualizada.");
    }

    private function salvar(Request $request, Legenda $legenda): void
    {
        $request->merge(['codigo' => mb_strtoupper(trim((string) $request->input('codigo'))), 'cor' => strtolower((string) $request->input('cor'))]);
        $dados = $request->validate([
            'unidade' => Unidades::regras($request->user(), $legenda->exists ? $legenda->unidade : null),
            'codigo' => ['required', 'string', 'max:12', Rule::unique('legendas', 'codigo')->ignore($legenda->id)],
            'descricao' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::in(array_keys(Legenda::TIPOS))],
            'cor' => ['required', 'regex:/^#[0-9a-f]{6}$/'],
            'ordem' => ['nullable', 'integer', 'between:0,9999'],
            'ativo' => ['required', 'boolean'],
        ], [
            'codigo.required' => 'Informe a sigla da legenda.',
            'codigo.unique' => 'Já existe uma legenda com essa sigla.',
            'codigo.max' => 'A sigla pode ter até 12 caracteres.',
            'descricao.required' => 'Informe a descrição.',
            'cor.required' => 'Escolha a cor.',
            'cor.regex' => 'Cor inválida. Use o seletor de cor ou o formato #RRGGBB.',
            '*.max' => 'Texto muito longo.',
        ]);

        $dados['ordem'] = (int) ($dados['ordem'] ?? 0);
        $legenda->fill($dados)->save();
    }
}
