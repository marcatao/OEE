<?php

namespace App\Http\Controllers\Cadastros;

use App\Http\Controllers\Controller;
use App\Models\Grupo;
use App\Models\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administração > Logins > Grupos. Só administradores. O grupo define quais telas os membros podem abrir.
 */
class GrupoController extends Controller
{
    public function index(): View
    {
        return view('cadastros.grupos.index', [
            'grupos' => Grupo::withCount('usuarios')->orderBy('nome')->get(),
            'catalogo' => config('oee.permissoes'),
        ]);
    }

    public function create(): View
    {
        return $this->formulario(new Grupo(['ativo' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        $grupo = new Grupo;
        $this->salvar($request, $grupo);

        return redirect()->route('cadastros.grupos.index')->with('ok', "Grupo {$grupo->nome} criado.");
    }

    public function edit(Grupo $grupo): View
    {
        return $this->formulario($grupo);
    }

    public function update(Request $request, Grupo $grupo): RedirectResponse
    {
        $this->salvar($request, $grupo);

        return redirect()->route('cadastros.grupos.index')->with('ok', "Grupo {$grupo->nome} atualizado.");
    }

    private function formulario(Grupo $grupo): View
    {
        return view('cadastros.grupos.form', [
            'grupo' => $grupo,
            'catalogo' => config('oee.permissoes'),
            'permissoes' => $grupo->exists ? $grupo->permissoes() : [],
            'usuarios' => Usuario::orderBy('nome')->get(),
            'membros' => $grupo->exists ? $grupo->usuarios()->pluck('usuarios.id')->all() : [],
        ]);
    }

    private function salvar(Request $request, Grupo $grupo): void
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:80', Rule::unique('grupos', 'nome')->ignore($grupo->id)],
            'descricao' => ['nullable', 'string', 'max:300'],
            'ativo' => ['required', 'boolean'],
            'permissoes' => ['array'],
            'permissoes.*' => [Rule::in(array_keys(config('oee.permissoes')))],
            'usuarios' => ['array'],
            'usuarios.*' => ['integer', Rule::exists('usuarios', 'id')],
        ], [
            'nome.required' => 'Informe o nome do grupo.',
            'nome.unique' => 'Já existe um grupo com esse nome.',
            'nome.max' => 'O nome pode ter até 80 caracteres.',
            'descricao.max' => 'A descrição pode ter até 300 caracteres.',
        ]);

        DB::transaction(function () use ($grupo, $dados) {
            $grupo->fill(['nome' => $dados['nome'], 'descricao' => $dados['descricao'] ?? null, 'ativo' => $dados['ativo']])->save();
            $grupo->definirPermissoes($dados['permissoes'] ?? []);
            $grupo->usuarios()->sync($dados['usuarios'] ?? []);
        });
    }
}
