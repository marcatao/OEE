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
 * Segurança > Usuários. Os usuários são criados pelo primeiro login no Entra ID; aqui o Administrador de Tecnologia
 * define os perfis (vários por usuário), a situação e os grupos.
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
            ->when($perfil === 'sem_acesso', fn ($q) => $q->where('perfil', 'sem_acesso'))
            ->when(array_key_exists((string) $perfil, Usuario::perfisDisponiveis()), fn ($q) => $q->where(fn ($w) => $w
                ->whereExists(fn ($e) => $e->from('usuario_perfis')->whereColumn('usuario_perfis.usuario_id', 'usuarios.id')->where('perfil', $perfil))
                // ainda sem perfis gravados: segue o perfil antigo (admin = todos; operador = operador)
                ->orWhere(fn ($legado) => $legado->whereNotExists(fn ($e) => $e->from('usuario_perfis')->whereColumn('usuario_perfis.usuario_id', 'usuarios.id'))
                    ->whereIn('perfil', $perfil === 'operador' ? ['admin', 'operador'] : ['admin']))))
            ->orderByRaw("CASE WHEN perfil = 'sem_acesso' THEN 0 ELSE 1 END") // pendentes primeiro
            ->orderBy('nome')
            ->paginate(25)
            ->withQueryString();

        return view('cadastros.usuarios.index', [
            'usuarios' => $usuarios,
            'perfis' => ['sem_acesso' => 'Sem acesso'] + array_map(fn ($p) => $p['rotulo'], Usuario::perfisDisponiveis()),
            'pendentes' => Usuario::where('perfil', 'sem_acesso')->where('ativo', true)->count(),
        ]);
    }

    public function edit(Usuario $usuario): View
    {
        return view('cadastros.usuarios.edit', [
            'usuario' => $usuario->load('grupos'),
            'perfis' => Usuario::perfisDisponiveis(),
            'grupos' => Grupo::orderBy('nome')->get(),
            'acessos' => $usuario->registrosLogin()->latest('data_hora')->limit(10)->get(),
        ]);
    }

    public function update(Request $request, Usuario $usuario): RedirectResponse
    {
        $dados = $request->validate([
            'perfis' => ['array'],
            'perfis.*' => [Rule::in(array_keys(Usuario::perfisDisponiveis()))],
            'ativo' => ['required', 'boolean'],
            'grupos' => ['array'],
            'grupos.*' => ['integer', Rule::exists('grupos', 'id')],
        ], [
            'perfis.*' => 'Escolha perfis válidos.',
            'grupos.*.*' => 'Escolha grupos válidos.',
        ]);
        $perfis = array_values(array_unique($dados['perfis'] ?? []));

        // O Administrador de Tecnologia não tira o próprio acesso (evita o portal ficar sem quem cuida da Segurança).
        if ($usuario->is($request->user()) && (! in_array('admin_tecnologia', $perfis, true) || ! $dados['ativo'])) {
            return back()->withInput()->with('erro', 'Você não pode tirar o seu próprio perfil de Administrador de Tecnologia nem se desativar.');
        }

        DB::transaction(function () use ($usuario, $dados, $perfis, $request) {
            $usuario->update(['ativo' => $dados['ativo']]);
            $usuario->definirPerfis($perfis, $request->user());
            $usuario->grupos()->sync($dados['grupos'] ?? []);
        });

        return redirect()->route('cadastros.usuarios.index')->with('ok', "Cadastro de {$usuario->nome} atualizado.");
    }
}
