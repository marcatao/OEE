<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\InfoBanco;
use Illuminate\View\View;

class BancoController extends Controller
{
    public function __invoke(InfoBanco $banco): View
    {
        [$ok, $mensagem] = $banco->testar();

        return view('admin.banco', [
            'conexao' => $banco->descricao(),
            'ok' => $ok,
            'mensagem' => $mensagem,
            'tabelas' => $ok ? $banco->contagens() : [],
        ]);
    }
}
