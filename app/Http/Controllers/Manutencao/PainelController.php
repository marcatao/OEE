<?php

namespace App\Http\Controllers\Manutencao;

use App\Http\Controllers\Controller;
use App\Models\FiltroUsuario;
use App\Models\Usuario;
use App\Services\Manutencao\HorasManutencao;
use App\Services\Manutencao\PainelManutencao;
use App\Support\AcessoOs;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ordens de serviço › Painel: visão geral da área (Manutenção, Tecnologia ou Ferramentaria), com período De/Até (padrão: mês atual). Quem tem as duas escolhe qual ver e pode
 * gravar a sua ÁREA PADRÃO (filtros_usuarios, tela "os_painel"), que abre primeiro.
 */
class PainelController extends Controller
{
    public const TELA = 'os_painel';

    public function index(Request $request, PainelManutencao $painel): View
    {
        $u = $request->user();
        $unidades = AcessoOs::unidadesPainel($u);
        $padrao = self::areaPadrao($u, $unidades);
        $unidade = in_array($request->input('unidade'), $unidades, true) ? $request->input('unidade') : $padrao;
        // Período (De/Até, dias locais). Padrão: o mês atual até hoje. Invertido: troca.
        $de = $this->data((string) $request->input('de'));
        $ate = $this->data((string) $request->input('ate'));
        if ($de && $ate && $de->gt($ate)) {
            [$de, $ate] = [$ate, $de];
        }

        return view('manutencao.painel', [
            'unidade' => $unidade,
            'unidades' => $unidades,
            'padrao' => $padrao,
            'padraoGravado' => self::padraoGravado($u),
        ] + $painel->dados($u, $unidade, $de, $ate));
    }

    private function data(string $valor): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }
        $d = CarbonImmutable::createFromFormat('Y-m-d', $valor, HorasManutencao::fuso());

        return $d && $d->format('Y-m-d') === $valor ? $d->startOfDay() : null;
    }

    /** Grava a área padrão do usuário no painel. */
    public function salvarPadrao(Request $request): RedirectResponse
    {
        $u = $request->user();
        $dados = $request->validate(['unidade' => ['required', Rule::in(AcessoOs::unidadesPainel($u))]]);
        FiltroUsuario::daTela($u, self::TELA)->delete();
        FiltroUsuario::create(['usuario_id' => $u->id, 'tela' => self::TELA, 'nome' => 'Área padrão', 'filtros' => ['unidade' => $dados['unidade']], 'padrao' => true]);

        return redirect()->route('os.painel', ['unidade' => $dados['unidade']])
            ->with('ok', 'Área padrão do painel: '.(config('oee.unidades')[$dados['unidade']] ?? $dados['unidade']).'.');
    }

    private static function padraoGravado(Usuario $u): ?string
    {
        return FiltroUsuario::daTela($u, self::TELA)->where('padrao', true)->first()?->filtros['unidade'] ?? null;
    }

    /** A gravada pelo usuário (se ainda for uma das dele); senão a primeira. */
    public static function areaPadrao(Usuario $u, array $unidades): string
    {
        $gravada = self::padraoGravado($u);

        return in_array($gravada, $unidades, true) ? $gravada : ($unidades[0] ?? 'manutencao');
    }
}
