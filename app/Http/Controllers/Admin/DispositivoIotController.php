<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DispositivoIot;
use App\Models\Maquina;
use App\Support\Unidades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administração > Dispositivos IoT: sensores, CLPs e gateways que enviam os dados das máquinas.
 */
class DispositivoIotController extends Controller
{
    public function index(Request $request): View
    {
        $busca = trim((string) $request->query('busca'));

        return view('admin.iot.index', [
            'busca' => $busca,
            'dispositivos' => DispositivoIot::with('maquina')
                ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('nome', 'like', "%{$busca}%")->orWhere('codigo', 'like', "%{$busca}%")
                    ->orWhere('endereco', 'like', "%{$busca}%")))
                ->orderByDesc('ativo')->orderBy('nome')->paginate(25)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return $this->formulario(new DispositivoIot(['ativo' => true, 'protocolo' => 'mqtt', 'tipo' => 'sensor']));
    }

    public function store(Request $request): RedirectResponse
    {
        $dispositivo = new DispositivoIot;
        $this->salvar($request, $dispositivo);

        return redirect()->route('admin.iot.index')->with('ok', "Dispositivo {$dispositivo->nome} cadastrado.");
    }

    public function edit(DispositivoIot $dispositivo): View
    {
        return $this->formulario($dispositivo);
    }

    public function update(Request $request, DispositivoIot $dispositivo): RedirectResponse
    {
        $this->salvar($request, $dispositivo);

        return redirect()->route('admin.iot.index')->with('ok', "Dispositivo {$dispositivo->nome} atualizado.");
    }

    private function formulario(DispositivoIot $dispositivo): View
    {
        return view('admin.iot.form', [
            'dispositivo' => $dispositivo,
            'maquinas' => Maquina::where(fn ($q) => $q->where('ativo', true)->orWhere('id', $dispositivo->maquina_id))
                ->orderBy('nome')->get(['id', 'codigo', 'nome']),
        ]);
    }

    private function salvar(Request $request, DispositivoIot $dispositivo): void
    {
        $dados = $request->validate([
            'unidade' => Unidades::regras($request->user(), $dispositivo->exists ? $dispositivo->unidade : null),
            'codigo' => ['required', 'string', 'max:40', Rule::unique('dispositivos_iot', 'codigo')->ignore($dispositivo->id)],
            'nome' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::in(array_keys(DispositivoIot::TIPOS))],
            'protocolo' => ['required', Rule::in(array_keys(DispositivoIot::PROTOCOLOS))],
            'endereco' => ['nullable', 'string', 'max:200'],
            'porta' => ['nullable', 'integer', 'between:1,65535'],
            'topico' => ['nullable', 'string', 'max:250'],
            'maquina_id' => ['nullable', 'integer', Rule::exists('maquinas', 'id')],
            'observacao' => ['nullable', 'string', 'max:500'],
            'ativo' => ['required', 'boolean'],
        ], [
            'codigo.required' => 'Informe o código do dispositivo.',
            'codigo.unique' => 'Já existe um dispositivo com esse código.',
            'nome.required' => 'Informe o nome do dispositivo.',
            'porta.between' => 'A porta deve estar entre 1 e 65535.',
            'porta.integer' => 'A porta deve ser um número.',
            '*.max' => 'Texto muito longo.',
        ]);

        $dispositivo->fill($dados)->save();
    }
}
