<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\PerfilEntra;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * "Meu perfil": o funcionário ajusta foto, setor, cargo, e-mail de contato e celular. Os IDs são só leitura.
 */
class PerfilController extends Controller
{
    public function show(Request $request): View
    {
        return view('perfil', ['u' => $request->user(), 'podeLerEntra' => $this->tokenEntra($request) !== null]);
    }

    public function update(Request $request, PerfilEntra $perfil): RedirectResponse
    {
        $dados = $request->validate([
            'setor' => ['nullable', 'string', 'max:120'],
            'cargo' => ['nullable', 'string', 'max:120'],
            'email_contato' => ['nullable', 'email', 'max:320'],
            'celular' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s.]{8,40}$/'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'remover_foto' => ['nullable', 'boolean'],
        ], [
            'email_contato.email' => 'Informe um e-mail válido.',
            'celular.regex' => 'Informe o celular só com números, espaços, parênteses, + ou -. Ex.: (11) 99999-9999.',
            'foto.image' => 'A foto precisa ser uma imagem (JPG, PNG ou WEBP).',
            'foto.mimes' => 'A foto precisa ser JPG, PNG ou WEBP.',
            'foto.max' => 'A foto pode ter no máximo 3 MB.',
            '*.max' => 'Texto muito longo.',
        ]);

        /** @var Usuario $u */
        $u = $request->user();
        $u->fill([
            'setor' => $dados['setor'] ?? null,
            'cargo' => $dados['cargo'] ?? null,
            'email_contato' => $dados['email_contato'] ?? null,
            'celular' => $dados['celular'] ?? null,
        ]);

        if ($request->hasFile('foto')) {
            $arquivo = $request->file('foto');
            $perfil->guardarFoto($u, $arquivo->get(), $arquivo->extension() === 'jpeg' ? 'jpg' : $arquivo->extension());
        } elseif ($request->boolean('remover_foto')) {
            $perfil->removerFoto($u);
        }

        $u->save();

        return redirect()->route('perfil')->with('ok', 'Perfil atualizado.');
    }

    /** Troca os dados do perfil pelos que estão no Microsoft Entra ID (usa o token do login atual). */
    public function sincronizar(Request $request, PerfilEntra $perfil): RedirectResponse
    {
        $token = $this->tokenEntra($request);
        if ($token === null) {
            return redirect()->route('perfil')->with('erro', 'Para ler os dados do Entra ID, saia e entre novamente no portal.');
        }

        $alterados = $perfil->sincronizar($request->user(), $token, sobrescrever: true);

        return redirect()->route('perfil')->with('ok', $alterados
            ? 'Dados atualizados com o Microsoft Entra ID.'
            : 'Nada mudou: o perfil já está igual ao Microsoft Entra ID (ou o Entra ID não tem esses dados).');
    }

    /** Foto de perfil (para qualquer usuário logado, ex.: listas de usuários). */
    public function foto(Usuario $usuario): Response
    {
        abort_unless($usuario->foto && Storage::disk('local')->exists($usuario->foto), 404);

        return Storage::disk('local')->response($usuario->foto, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function tokenEntra(Request $request): ?string
    {
        if ((int) $request->session()->get('entra_token_expira', 0) <= time() + 30) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $request->session()->get('entra_token'));
        } catch (Throwable) {
            return null;
        }
    }
}
