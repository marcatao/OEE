<?php

namespace App\Http\Controllers\Cadastros;

use App\Http\Controllers\Controller;
use App\Models\RegistroLogin;
use App\Models\Usuario;
use App\Services\Seguranca\PainelAcessos;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Segurança › Histórico de logins (permissão `seguranca_historico`): todas as tentativas de login, gravadas no banco,
 * com filtros por período (dias locais), usuário, e-mail, resultado, motivo e IP.
 */
class AcessoController extends Controller
{
    public function __invoke(Request $request): View
    {
        $f = [
            'de' => (string) $request->query('de'),
            'ate' => (string) $request->query('ate'),
            'usuario' => (int) $request->query('usuario') ?: null,
            'email' => trim((string) $request->query('email')),
            'resultado' => in_array($request->query('resultado'), ['entrou', 'recusado'], true) ? $request->query('resultado') : null,
            'motivo' => trim((string) $request->query('motivo')),
            'ip' => trim((string) $request->query('ip')),
        ];
        [$inicio, $fim] = PainelAcessos::periodo($f['de'], $f['ate']);

        $logins = RegistroLogin::with('usuario')
            ->when($inicio, fn ($q) => $q->where('data_hora', '>=', $inicio))
            ->when($fim, fn ($q) => $q->where('data_hora', '<', $fim))
            ->when($f['usuario'], fn ($q) => $q->where('usuario_id', $f['usuario']))
            ->when($f['email'] !== '', fn ($q) => $q->where('email', 'like', '%'.$f['email'].'%'))
            ->when($f['resultado'] === 'entrou', fn ($q) => $q->where('sucesso', true))
            ->when($f['resultado'] === 'recusado', fn ($q) => $q->where('sucesso', false))
            ->when($f['motivo'] !== '', fn ($q) => $f['motivo'] === '-' ? $q->whereNull('motivo') : $q->where('motivo', $f['motivo']))
            ->when($f['ip'] !== '', fn ($q) => $q->where('ip', 'like', $f['ip'].'%'))
            ->latest('data_hora')
            ->paginate(50)
            ->withQueryString();

        return view('cadastros.acessos', [
            'logins' => $logins,
            'f' => $f,
            'resultado' => $f['resultado'],
            'usuarios' => Usuario::orderBy('nome')->get(['id', 'nome', 'email']),
            'motivos' => RegistroLogin::whereNotNull('motivo')->distinct()->orderBy('motivo')->pluck('motivo'),
        ]);
    }
}
