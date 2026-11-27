<?php

namespace App\Http\Controllers\InventarioTi;

use App\Http\Controllers\Controller;
use App\Models\InventarioTi\AtribuicaoLicenca;
use App\Models\InventarioTi\ItemTi;
use App\Models\InventarioTi\TermoResponsabilidade;
use App\Models\InventarioTi\TestemunhaTermo;
use App\Services\InventarioTi\TermosResponsabilidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            // Termos em que ele é testemunha.
            'comoTestemunha' => TestemunhaTermo::where('usuario_id', $u->id)->whereHas('termo', fn ($q) => $q->where('status', '<>', 'cancelado'))
                ->with('termo.item', 'termo.usuario')->orderByRaw('CASE WHEN assinado_em IS NULL THEN 0 ELSE 1 END')->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    public function show(Request $request, int $termo): View
    {
        $t = $this->buscar($request, $termo);

        return view('inventario-ti.termos.show', [
            't' => $t,
            'podeAssinar' => $this->termos->podeAssinar($t, $request->user()),
            // Qualificação do comodatário já informada num termo anterior dele (só para preencher o formulário).
            'comodatario' => $t->status === 'pendente' && $t->usuario ? $this->termos->comodatarioAnterior($t->usuario) : [],
            'imprimir' => $request->boolean('imprimir'),
            'podeAnular' => $t->status !== 'cancelado' && TermosResponsabilidade::podeGerar($request->user()),
            'rgTestemunha' => $this->termos->rgAnteriorTestemunha($request->user()),
        ]);
    }

    public function assinar(Request $request, int $termo): RedirectResponse
    {
        $t = $this->buscar($request, $termo);
        abort_unless($this->termos->podeAssinar($t, $request->user()), 403, 'Este termo não está aguardando a sua assinatura.');
        $dados = $request->validate([
            'assinatura' => ['required', 'string', 'max:900000'], 'aceite' => ['accepted'],
            'nacionalidade' => ['required', 'string', 'max:40'], 'cargo' => ['required', 'string', 'max:100'],
            'rg' => ['required', 'string', 'max:30'], 'cpf' => ['required', 'string', 'max:14'], 'endereco' => ['required', 'string', 'max:255'],
        ], [
            'assinatura.required' => 'Assine no quadro antes de confirmar.', 'aceite.accepted' => 'Marque que leu e concorda com o termo.',
            'nacionalidade.required' => 'Informe a nacionalidade.', 'cargo.required' => 'Informe o cargo.', 'rg.required' => 'Informe o RG.',
            'cpf.required' => 'Informe o CPF.', 'endereco.required' => 'Informe o endereço completo (rua, número, bairro, cidade/UF e CEP).',
        ]);
        if (! TermosResponsabilidade::cpfValido($dados['cpf'])) {
            throw ValidationException::withMessages(['cpf' => 'CPF inválido: confira os números.']);
        }
        $comodatario = ['nacionalidade' => $dados['nacionalidade'], 'cargo' => $dados['cargo'], 'rg' => $dados['rg'],
            'cpf' => TermosResponsabilidade::formatarCpf($dados['cpf']), 'endereco' => $dados['endereco']];
        $t = $this->termos->assinar($t, $request->user(), $dados['assinatura'], $request->ip(), $request->userAgent(), $comodatario);

        return redirect()->route('inventario-ti.termos.show', $t)->with('ok', "Termo {$t->numeroFormatado()} assinado. Obrigado!");
    }

    public function assinarTestemunha(Request $request, int $termo, int $ordem): RedirectResponse
    {
        $t = $this->buscar($request, $termo);
        $slot = $t->testemunhas->firstWhere('ordem', $ordem);
        abort_unless($slot && $this->termos->podeAssinarComoTestemunha($t, $slot, $request->user()), 403,
            'Só a própria testemunha, com o login dela, assina aqui.');
        $dados = $request->validate(['assinatura_testemunha' => ['required', 'string', 'max:900000'], 'rg' => ['required', 'string', 'max:30']],
            ['assinatura_testemunha.required' => 'Assine no quadro antes de confirmar.', 'rg.required' => 'Informe o seu RG.']);
        $te = $this->termos->assinarTestemunha($t, $ordem, $request->user(), $dados['assinatura_testemunha'], $dados['rg'], $request->ip());

        return redirect()->route('inventario-ti.termos.show', $t)->with('ok', "Assinatura da testemunha {$te->nome} registrada.");
    }

    /** Administrador de TI anula o termo (dados errados etc.): fica cancelado com o motivo, sem apagar nada. */
    public function anular(Request $request, int $termo): RedirectResponse
    {
        $t = $this->buscar($request, $termo);
        $dados = $request->validate(['motivo' => ['required', 'string', 'max:200']], ['motivo.required' => 'Informe o motivo.']);
        $this->termos->anular($t, $dados['motivo'], $request->user());

        return redirect()->route('inventario-ti.show', $t->item_id)->with('ok', "Termo {$t->numeroFormatado()} anulado. Gere um termo novo, se for preciso assinar de novo.");
    }

    private function buscar(Request $request, int $id): TermoResponsabilidade
    {
        $t = TermoResponsabilidade::with(['item.tipo', 'usuario', 'geradoPor', 'registradoPor', 'testemunhas.usuario', 'testemunhas.registradoPor'])->findOrFail($id);
        abort_unless($this->termos->podeVer($t, $request->user()), 404);

        return $t;
    }
}
