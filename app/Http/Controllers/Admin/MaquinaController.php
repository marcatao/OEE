<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Linha;
use App\Models\Maquina;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administração > Máquinas. Só administradores. Máquinas são desativadas, nunca excluídas.
 */
class MaquinaController extends Controller
{
    public function index(Request $request): View
    {
        $busca = trim((string) $request->query('busca'));

        return view('admin.maquinas.index', [
            'busca' => $busca,
            'maquinas' => Maquina::with('linha.planta')->withCount('dispositivos')
                ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('nome', 'like', "%{$busca}%")->orWhere('codigo', 'like', "%{$busca}%")))
                ->orderByDesc('ativo')->orderBy('nome')->paginate(25)->withQueryString(),
            'temLinhas' => Linha::where('ativo', true)->exists(),
        ]);
    }

    public function create(): View
    {
        return $this->formulario(new Maquina(['ativo' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        $maquina = new Maquina;
        $this->salvar($request, $maquina);

        return redirect()->route('admin.maquinas.index')->with('ok', "Máquina {$maquina->nome} cadastrada.");
    }

    public function edit(Maquina $maquina): View
    {
        return $this->formulario($maquina);
    }

    public function update(Request $request, Maquina $maquina): RedirectResponse
    {
        $this->salvar($request, $maquina);

        return redirect()->route('admin.maquinas.index')->with('ok', "Máquina {$maquina->nome} atualizada.");
    }

    private function formulario(Maquina $maquina): View
    {
        return view('admin.maquinas.form', [
            'maquina' => $maquina,
            'linhas' => Linha::with('planta')
                ->where(fn ($q) => $q->where('ativo', true)->orWhere('id', $maquina->linha_id))
                ->get()->sortBy(fn ($l) => $l->planta?->nome.' '.$l->nome)->groupBy(fn ($l) => $l->planta?->nome ?? 'Sem planta'),
        ]);
    }

    private function salvar(Request $request, Maquina $maquina): void
    {
        $dados = $request->validate([
            'linha_id' => ['required', 'integer', Rule::exists('linhas', 'id')],
            'codigo' => ['required', 'string', 'max:30', Rule::unique('maquinas', 'codigo')->ignore($maquina->id)],
            'nome' => ['required', 'string', 'max:120'],
            'fabricante' => ['nullable', 'string', 'max:120'],
            'modelo' => ['nullable', 'string', 'max:120'],
            'ciclo_ideal_s' => ['nullable', 'numeric', 'gt:0', 'max:86400'],
            'ativo' => ['required', 'boolean'],
        ], [
            'linha_id.required' => 'Escolha a linha da máquina.',
            'linha_id.exists' => 'Linha não encontrada.',
            'codigo.required' => 'Informe o código da máquina.',
            'codigo.unique' => 'Já existe uma máquina com esse código.',
            'nome.required' => 'Informe o nome da máquina.',
            'ciclo_ideal_s.gt' => 'O ciclo ideal deve ser maior que zero.',
            'ciclo_ideal_s.numeric' => 'O ciclo ideal deve ser um número (segundos por peça).',
            '*.max' => 'Texto muito longo.',
        ]);

        $maquina->fill($dados)->save();
    }
}
