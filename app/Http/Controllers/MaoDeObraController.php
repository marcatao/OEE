<?php

namespace App\Http\Controllers;

use App\Models\EstruturaProduto;
use App\Models\MaoDeObra;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\ProdutoProtheus;
use App\Services\MetaProducao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Produção › Mão de obra: produtos do TIPO MO da SB1 (campos do Protheus, só leitura) com o FATOR DE SERVIÇO do portal
 * (0 a 100%, nasce 0), que entra na meta de produção (MetaProducao::calcular). Os administradores editam o fator; a
 * nova meta vale dali para frente (registrada em metas_producao).
 */
class MaoDeObraController extends Controller
{
    public function index(Request $request): View
    {
        MaoDeObra::completar(); // MO novos no Protheus entram com fator 0
        $busca = trim((string) $request->query('busca'));

        $maos = MaoDeObra::query()
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('b1_cod', 'like', "%{$busca}%")
                ->orWhereIn('b1_cod', ProdutoProtheus::where('b1_tipo', 'MO')->where('b1_desc', 'like', "%{$busca}%")->select('b1_cod'))))
            ->orderBy('b1_cod')->paginate(50)->withQueryString();
        $codigos = $maos->getCollection()->map(fn ($m) => rtrim($m->b1_cod))->all();

        return view('producao.mao-de-obra.index', [
            'maos' => $maos,
            'produtos' => ProdutoProtheus::where('d_e_l_e_t_', '<>', '*')->whereIn('b1_cod', $codigos)->get()
                ->keyBy(fn ($p) => rtrim($p->b1_cod)),
            'usos' => EstruturaProduto::validas()->whereIn('g1_comp', $codigos)
                ->groupBy('g1_comp')->select('g1_comp', DB::raw('count(distinct g1_cod) as qtd'))->pluck('qtd', 'g1_comp')
                ->mapWithKeys(fn ($qtd, $c) => [rtrim($c) => (int) $qtd]),
            'busca' => $busca,
        ]);
    }

    public function update(Request $request, MaoDeObra $maoDeObra, MetaProducao $metas): RedirectResponse
    {
        $dados = $request->validate(['fator_servico' => ['required', 'numeric', 'min:0', 'max:100']],
            ['fator_servico.*' => 'Informe o fator de serviço entre 0 e 100%.']);
        $maoDeObra->fill(['fator_servico' => round((float) $dados['fator_servico'], 2)])->save();

        // Registra já a meta nova das OPs atuais das máquinas que usam esse MO (vale dali para frente).
        OrdemProducao::whereIn('id', Maquina::withoutGlobalScopes()->whereNotNull('ordem_producao_id')->select('ordem_producao_id'))
            ->whereIn('c2_produto', EstruturaProduto::validas()->where('g1_comp', rtrim($maoDeObra->b1_cod))->select('g1_cod'))
            ->get()->each(fn ($op) => $metas->registrar($op));

        return back()->with('ok', 'Fator de serviço de '.rtrim($maoDeObra->b1_cod).': '
            .number_format((float) $maoDeObra->fator_servico, 2, ',', '.').'%. A meta nova vale a partir de agora.');
    }
}
