<?php

namespace App\Http\Controllers\InventarioTi;

use App\Http\Controllers\Controller;
use App\Models\InventarioTi\AtribuicaoLicenca;
use App\Models\InventarioTi\ItemTi;
use App\Models\InventarioTi\TermoResponsabilidade;
use App\Services\InventarioTi\TermosResponsabilidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Termo de responsabilidade e "Meus equipamentos": rotas só com login (justificadas em AcessoDiretoRotasTest::SO_LOGIN),
 * porque quem recebe um equipamento pode não ter perfil no portal. Cada termo só abre para o responsável dele ou para
 * quem enxerga o inventário daquela unidade (`TermosResponsabilidade::podeVer`); o resto recebe 404.
 */
class TermoResponsabilidadeController extends Controller
{
    public function __construct(private TermosResponsabilidade $termos) {}

    /** Os itens que estão com o usuário logado, os termos para assinar e os já assinados. */
    public function meus(Request $request): View
    {
        $u = $request->user();

        return view('inventario-ti.termos.meus', [
            'itens' => ItemTi::withoutGlobalScopes()->ativos()->where('responsavel_id', $u->id)->with('tipo')->orderBy('descricao')->orderBy('id')->get(),
            'licencas' => AtribuicaoLicenca::ativas()->where('usuario_id', $u->id)->with('licenca')->get(),
            'termos' => TermoResponsabilidade::where('usuario_id', $u->id)->where('status', '<>', 'cancelado')->with('item')
                ->orderByRaw("CASE WHEN status = 'pendente' THEN 0 ELSE 1 END")->orderByDesc('criado_em')->orderByDesc('id')->get(),
        ]);
    }

    public function show(Request $request, int $termo): View
    {
        $t = $this->buscar($request, $termo);

        return view('inventario-ti.termos.show', [
            't' => $t,
            'podeAssinar' => $this->termos->podeAssinar($t, $request->user()),
            'imprimir' => $request->boolean('imprimir'),
        ]);
    }

    public function assinar(Request $request, int $termo): RedirectResponse
    {
        $t = $this->buscar($request, $termo);
        $request->validate(['assinatura' => ['required', 'string', 'max:900000'], 'nome' => ['nullable', 'string', 'max:120'],
            'aceite' => ['accepted']], ['assinatura.required' => 'Assine no quadro antes de confirmar.',
                'aceite.accepted' => 'Marque que leu e concorda com o termo.']);
        $t = $this->termos->assinar($t, $request->user(), $request->input('assinatura'), $request->input('nome'), $request->ip(), $request->userAgent());

        return redirect()->route('inventario-ti.termos.show', $t)->with('ok', "Termo {$t->numeroFormatado()} assinado. Obrigado!");
    }

    private function buscar(Request $request, int $id): TermoResponsabilidade
    {
        $t = TermoResponsabilidade::with(['item.tipo', 'usuario', 'geradoPor', 'registradoPor'])->findOrFail($id);
        abort_unless($this->termos->podeVer($t, $request->user()), 404);

        return $t;
    }
}
