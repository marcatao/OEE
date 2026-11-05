<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RegistroLogin;
use App\Models\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UsuarioController extends Controller
{
    public function index(): View
    {
        return view('admin.usuarios', [
            'usuarios' => Usuario::orderBy('nome')->get(),
            'logins' => RegistroLogin::latest('data_hora')->limit(50)->get(),
            'perfis' => Usuario::PERFIS,
        ]);
    }

    public function update(Request $request, Usuario $usuario): RedirectResponse
    {
        $dados = $request->validate([
            'perfil' => ['required', Rule::in(Usuario::PERFIS)],
            'ativo' => ['required', 'boolean'],
        ]);

        // Um administrador não tira o próprio acesso (evita ficar sem nenhum admin por engano).
        if ($usuario->is($request->user()) && ($dados['perfil'] !== 'admin' || ! $dados['ativo'])) {
            return back()->with('erro', 'Você não pode tirar o seu próprio acesso de administrador.');
        }

        $usuario->update($dados);

        return back()->with('ok', "Usuário {$usuario->nome} atualizado.");
    }
}
