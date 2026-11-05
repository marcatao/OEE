<?php

namespace App\Http\Controllers\Cadastros;

use App\Http\Controllers\Controller;
use App\Models\RegistroLogin;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Cadastros > Logins > Histórico de acessos: todas as tentativas de login, gravadas no banco. */
class AcessoController extends Controller
{
    public function __invoke(Request $request): View
    {
        $resultado = $request->query('resultado');

        $logins = RegistroLogin::with('usuario')
            ->when($resultado === 'entrou', fn ($q) => $q->where('sucesso', true))
            ->when($resultado === 'recusado', fn ($q) => $q->where('sucesso', false))
            ->latest('data_hora')
            ->paginate(50)
            ->withQueryString();

        return view('cadastros.acessos', ['logins' => $logins, 'resultado' => $resultado]);
    }
}
