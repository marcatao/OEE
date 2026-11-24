<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Legenda;
use App\Models\Manutencao\AreaExecutante;
use App\Support\AcessoOs;
use App\Support\Unidades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Administração > Legendas: estados da Timeline (sigla, descrição, tipo e COR da barra). Desativadas, nunca excluídas.
 */
class LegendaController extends Controller
{
    public function index(): View
    {
        return view('admin.legendas.index', [
            'legendas' => Legenda::with('abreSsArea')->withCount('apontamentos')->orderByDesc('ativo')->orderBy('ordem')->orderBy('codigo')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.legendas.form', ['legenda' => new Legenda([
            'ativo' => true, 'tipo' => 'parada', 'cor' => '#43a047', 'ordem' => (int) Legenda::max('ordem') + 10,
        ])] + $this->dadosAbreSs());
    }

    public function store(Request $request): RedirectResponse
    {
        $legenda = new Legenda;
        $this->salvar($request, $legenda);

        return redirect()->route('admin.legendas.index')->with('ok', "Legenda {$legenda->codigo} cadastrada.");
    }

    public function edit(Legenda $legenda): View
    {
        return view('admin.legendas.form', ['legenda' => $legenda] + $this->dadosAbreSs());
    }

    public function update(Request $request, Legenda $legenda): RedirectResponse
    {
        $this->salvar($request, $legenda);

        return redirect()->route('admin.legendas.index')->with('ok', "Legenda {$legenda->codigo} atualizada.");
    }

    private function salvar(Request $request, Legenda $legenda): void
    {
        $request->merge(['codigo' => mb_strtoupper(trim((string) $request->input('codigo'))), 'cor' => strtolower((string) $request->input('cor'))]);
        $request->mergeIfMissing(['exige_op' => (int) $legenda->exige_op, 'exibir_dashboards' => (int) ($legenda->exibir_dashboards ?? true)]);
        $dados = $request->validate([
            'unidade' => Unidades::regras($request->user(), $legenda->exists ? $legenda->unidade : null),
            'codigo' => ['required', 'string', 'max:12', Rule::unique('legendas', 'codigo')->ignore($legenda->id)],
            'descricao' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::in(array_keys(Legenda::TIPOS))],
            'cor' => ['required', 'regex:/^#[0-9a-f]{6}$/'],
            'ordem' => ['nullable', 'integer', 'between:0,9999'],
            'ativo' => ['required', 'boolean'],
            'exige_op' => ['required', 'boolean'],
            'exibir_dashboards' => ['required', 'boolean'],
            'abre_ss_unidade' => ['nullable', Rule::in(AcessoOs::unidadesHabilitadas($request->user()))],
            'abre_ss_area_executante_id' => ['nullable', 'integer', 'required_with:abre_ss_unidade'],
        ], [
            'abre_ss_area_executante_id.required_with' => 'Para abrir SS, escolha também a área executante.',
            'abre_ss_unidade.in' => 'Escolha uma unidade liberada para você.',
            'codigo.required' => 'Informe a sigla da legenda.',
            'codigo.unique' => 'Já existe uma legenda com essa sigla.',
            'codigo.max' => 'A sigla pode ter até 12 caracteres.',
            'descricao.required' => 'Informe a descrição.',
            'cor.required' => 'Escolha a cor.',
            'cor.regex' => 'Cor inválida. Use o seletor de cor ou o formato #RRGGBB.',
            '*.max' => 'Texto muito longo.',
        ]);

        $dados['ordem'] = (int) ($dados['ordem'] ?? 0);
        // Abre SS: unidade + área executante dela (ou de "Todas"); sem unidade, a legenda não abre SS.
        if (empty($dados['abre_ss_unidade'])) {
            $dados['abre_ss_unidade'] = null;
            $dados['abre_ss_area_executante_id'] = null;
        } else {
            $area = AreaExecutante::withoutGlobalScopes()->where('ativo', true)->find($dados['abre_ss_area_executante_id']);
            if (! $area || ! in_array($area->unidade, [$dados['abre_ss_unidade'], 'ambos'], true)) {
                throw ValidationException::withMessages(['abre_ss_area_executante_id' => 'Escolha uma área executante ativa da unidade escolhida.']);
            }
        }
        $legenda->fill($dados)->save();
    }

    /** Unidades (as do login) e áreas executantes ativas para o campo "Abre SS". */
    private function dadosAbreSs(): array
    {
        return ['unidadesSs' => AcessoOs::unidadesHabilitadas(request()->user()), 'areasSs' => AcessoOs::areas()];
    }
}
