<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VersaoAplicativo;
use App\Services\Aplicativo\VersoesAplicativo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Segurança › Aplicativo (só Administrador de Tecnologia): publica o APK do aplicativo Android. Os celulares
 * perguntam a versão mais nova ao portal, baixam por ele e pedem a instalação. Versão DESATIVADA pode ser excluída:
 * o .apk é apagado do servidor (limpeza das versões velhas) e a linha fica como histórico.
 */
class AplicativoController extends Controller
{
    public function index(VersoesAplicativo $versoes): View
    {
        return view('admin.aplicativo.index', [
            'lista' => VersaoAplicativo::withoutGlobalScopes()->with('criadoPor', 'excluidoPor')->orderByDesc('codigo')->get(),
            'atual' => $versoes->atual(),
            'minima' => $versoes->minimaObrigatoria(),
            'maxMb' => (int) config('oee.app.tamanho_max_apk_mb', 20),
        ]);
    }

    public function store(Request $request, VersoesAplicativo $versoes): RedirectResponse
    {
        $dados = $request->validate([
            'apk' => ['required', 'file', 'max:'.((int) config('oee.app.tamanho_max_apk_mb', 20) * 1024)],
            'obrigatoria' => ['nullable', 'boolean'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ], [
            'apk.required' => 'Escolha o arquivo .apk.',
            'apk.max' => 'O APK pode ter até :max KB.',
        ]);
        if (strtolower($request->file('apk')->getClientOriginalExtension()) !== 'apk') {
            return back()->withErrors(['apk' => 'Envie um arquivo .apk.'])->withInput();
        }
        $versao = $versoes->publicar($request->file('apk'), ! empty($dados['obrigatoria']), $dados['observacao'] ?? null);

        return redirect()->route('admin.aplicativo')->with('ok', "Versão {$versao->nome} (build {$versao->codigo}) publicada. Os celulares vão oferecer a atualização.");
    }

    /** Ativa/desativa e marca/desmarca como obrigatória. */
    public function update(Request $request, int $versao): RedirectResponse
    {
        $registro = VersaoAplicativo::withoutGlobalScopes()->findOrFail($versao);
        if ($registro->excluida()) {
            return back()->with('erro', "A versão {$registro->nome} (build {$registro->codigo}) foi excluída: o arquivo não existe mais. Publique o APK de novo com um build maior.");
        }
        $dados = $request->validate(['ativo' => ['required', 'boolean'], 'obrigatoria' => ['required', 'boolean']]);
        $registro->update(['ativo' => (bool) $dados['ativo'], 'obrigatoria' => (bool) $dados['obrigatoria']]);

        return redirect()->route('admin.aplicativo')->with('ok', "Versão {$registro->nome} (build {$registro->codigo}) atualizada.");
    }

    /** Exclui uma versão DESATIVADA: apaga o .apk do servidor e marca quem excluiu (a linha fica como histórico). */
    public function destroy(Request $request, int $versao): RedirectResponse
    {
        $registro = VersaoAplicativo::withoutGlobalScopes()->findOrFail($versao);
        if ($registro->excluida()) {
            return back()->with('erro', "A versão {$registro->nome} (build {$registro->codigo}) já foi excluída.");
        }
        if ($registro->ativo) {
            return back()->with('erro', "Desative a versão {$registro->nome} (build {$registro->codigo}) antes de excluir.");
        }
        $disco = Storage::disk('local');
        if ($disco->exists($registro->arquivo) && ! $disco->delete($registro->arquivo)) {
            return back()->with('erro', 'Não foi possível apagar o arquivo do servidor (permissão da pasta storage). Nada foi alterado.');
        }
        $registro->forceFill(['excluido_em' => now(), 'excluido_por_id' => $request->user()->id])->save();

        return back()->with('ok', "Versão {$registro->nome} (build {$registro->codigo}) excluída: o arquivo foi apagado do servidor (".number_format($registro->tamanho / 1048576, 1, ',', '.').' MB liberados).');
    }

    public function baixar(int $versao): StreamedResponse
    {
        $registro = VersaoAplicativo::withoutGlobalScopes()->findOrFail($versao);
        abort_if($registro->excluida(), 404);
        abort_unless(Storage::disk('local')->exists($registro->arquivo), 404);

        return Storage::disk('local')->download($registro->arquivo, $registro->nomeArquivo(), ['Content-Type' => 'application/vnd.android.package-archive']);
    }
}
