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
 * Cadastros > Logins > Usuários. Os usuários são criados pelo primeiro login no Entra ID;
 * aqui o administrador define perfil, situação e grupos.
 */
class UsuarioController extends Controller
{
    public function index(Request $request): View
    {
        $busca = trim((string) $request->query('busca'));
        $perfil = $request->query('perfil');

        $usuarios = Usuario::with('grupos')
            ->when($busca !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('nome', 'like', "%{$busca}%")
                ->orWhere('email', 'like', "%{$busca}%")))
            ->when(array_key_exists((string) $perfil, Usuario::PERFIS), fn ($q) => $q->where('perfil', $perfil))
            ->orderByRaw("CASE WHEN perfil = 'sem_acesso' THEN 0 ELSE 1 END") // pendentes primeiro
            ->orderBy('nome')
            ->paginate(25)
            ->withQueryString();

        return view('cadastros.usuarios.index', [
            'usuarios' => $usuarios,
            'perfis' => Usuario::PERFIS,
            'pendentes' => Usuario::where('perfil', 'sem_acesso')->where('ativo', true)->count(),
        ]);
    }

    public function edit(Usuario $usuario): View
    {
        return view('cadastros.usuarios.edit', [
            'usuario' => $usuario->load('grupos'),
            'perfis' => Usuario::PERFIS,
            'grupos' => Grupo::orderBy('nome')->get(),
            'acessos' => $usuario->registrosLogin()->latest('data_hora')->limit(10)->get(),
        ]);
    }

    public function update(Request $request, Usuario $usuario): RedirectResponse
    {
        $dados = $request->validate([
            'perfil' => ['required', Rule::in(array_keys(Usuario::PERFIS))],
            'ativo' => ['required', 'boolean'],
            'grupos' => ['array'],
            'grupos.*' => ['integer', Rule::exists('grupos', 'id')],
        ], [
            'perfil.*' => 'Escolha um perfil válido.',
            'grupos.*.*' => 'Escolha grupos válidos.',
        ]);

        // Um administrador não tira o próprio acesso (evita o portal ficar sem administrador por engano).
        if ($usuario->is($request->user()) && ($dados['perfil'] !== 'admin' || ! $dados['ativo'])) {
            return back()->withInput()->with('erro', 'Você não pode tirar o seu próprio acesso de administrador.');
        }

        DB::transaction(function () use ($usuario, $dados) {
            $usuario->update(['perfil' => $dados['perfil'], 'ativo' => $dados['ativo']]);
            $usuario->grupos()->sync($dados['grupos'] ?? []);
        });

        return redirect()->route('cadastros.usuarios.index')->with('ok', "Cadastro de {$usuario->nome} atualizado.");
    }
}
