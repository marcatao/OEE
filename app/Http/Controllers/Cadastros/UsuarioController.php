<?php

namespace App\Http\Controllers\Cadastros;

use App\Http\Controllers\Controller;
use App\Models\Grupo;
use App\Models\Usuario;
use App\Services\ImportadorUsuariosEntra;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

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
                ->orWhere('email', 'like', "%{$busca}%")
                ->orWhere('local_escritorio', 'like', "%{$busca}%")
                ->orWhere('departamento', 'like', "%{$busca}%")
                ->orWhere('usuario_protheus', 'like', "%{$busca}%")
                ->orWhere('id_protheus', 'like', "%{$busca}%")))
            ->when(in_array($request->query('situacao'), ['ativo', 'bloqueado'], true), fn ($q) => $q->where('ativo', $request->query('situacao') === 'ativo'))
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

    /** Botão "Importar do Entra ID": cadastra as contas novas já bloqueadas (ImportadorUsuariosEntra). */
    public function importarEntra(ImportadorUsuariosEntra $importador): RedirectResponse
    {
        try {
            $r = $importador->importar();
        } catch (Throwable $e) {
            Log::warning('Importação de usuários do Entra ID falhou', ['erro' => $e->getMessage()]);

            return back()->with('erro', 'Não foi possível importar do Entra ID: '.$e->getMessage());
        }

        return back()->with('ok', "Entra ID: {$r['criados']} conta(s) nova(s) cadastrada(s) bloqueada(s); {$r['existentes']} já existiam; {$r['ignorados']} ignorada(s) (convidados, desabilitadas ou sem e-mail).");
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
            // Opcionais: ligação com o usuário do Protheus (cada um em um só usuário do portal).
            'usuario_protheus' => ['nullable', 'string', 'max:25', Rule::unique('usuarios', 'usuario_protheus')->ignore($usuario->id)],
            'id_protheus' => ['nullable', 'string', 'max:20', Rule::unique('usuarios', 'id_protheus')->ignore($usuario->id)],
        ], [
            'perfis.*' => 'Escolha perfis válidos.',
            'grupos.*.*' => 'Escolha grupos válidos.',
            'usuario_protheus.unique' => 'Este usuário do Protheus já está ligado a outro usuário do portal.',
            'id_protheus.unique' => 'Este ID do Protheus já está ligado a outro usuário do portal.',
        ], [
            'usuario_protheus' => 'usuário Protheus',
            'id_protheus' => 'ID Protheus',
        ]);
        $perfis = array_values(array_unique($dados['perfis'] ?? []));

        // O Administrador de Tecnologia não tira o próprio acesso (evita o portal ficar sem quem cuida da Segurança).
        if ($usuario->is($request->user()) && (! in_array('admin_tecnologia', $perfis, true) || ! $dados['ativo'])) {
            return back()->withInput()->with('erro', 'Você não pode tirar o seu próprio perfil de Administrador de Tecnologia nem se desativar.');
        }

        DB::transaction(function () use ($usuario, $dados, $perfis, $request) {
            $usuario->update([
                'ativo' => $dados['ativo'],
                // Login do Protheus em maiúsculas, como ele grava; vazio = sem ligação.
                'usuario_protheus' => ($v = strtoupper(trim((string) ($dados['usuario_protheus'] ?? '')))) !== '' ? $v : null,
                'id_protheus' => ($v = trim((string) ($dados['id_protheus'] ?? ''))) !== '' ? $v : null,
            ]);
            $usuario->definirPerfis($perfis, $request->user());
            $usuario->grupos()->sync($dados['grupos'] ?? []);
        });

        return redirect()->route('cadastros.usuarios.index')->with('ok', "Cadastro de {$usuario->nome} atualizado.");
    }
}
