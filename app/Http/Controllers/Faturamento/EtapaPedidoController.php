<?php

namespace App\Http\Controllers\Faturamento;

use App\Http\Controllers\Controller;
use App\Models\Faturamento\PedidoVendaLiberacao;
use App\Models\Faturamento\RegraEtapaPedido;
use App\Models\Faturamento\TipoOperacaoPedido;
use App\Services\Faturamento\EtapasPedidoVenda;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Faturamento › Etapas dos pedidos: regras sobre um campo da liberação do pedido (SC9) que a levam às etapas do WMS (separação,
 * conferência, logística, aguardando faturamento). O usuário usa o WMS do Protheus e configura os status aqui
 * (26/11/2026). Ao salvar, recalcula a etapa dos pedidos em aberto. Campo novo numa regra é lido do Protheus na próxima
 * sincronização dos pedidos de venda (procedure). Regras são desativadas, não excluídas.
 */
class EtapaPedidoController extends Controller
{
    /** Liberações em aberto lidas para mostrar os valores encontrados de cada campo extra. */
    private const AMOSTRA = 20000;

    public function index(): View
    {
        $regras = RegraEtapaPedido::with('atualizadoPor:id,nome', 'criadoPor:id,nome')->orderBy('etapa')->orderByDesc('ativo')->orderBy('id')->get();

        return view('faturamento.etapas.index', [
            'regras' => $regras->groupBy('etapa'),
            'etapas' => EtapasPedidoVenda::ETAPAS,
            'configuraveis' => array_reverse(EtapasPedidoVenda::CONFIGURAVEIS),
            'valores' => $this->valoresEncontrados(),
            'opcionais' => EtapasPedidoVenda::CAMPOS_WMS,
            // Tipos de operação (C6_OPER) que a procedure sincroniza: ativos primeiro, pelo número.
            'tiposOperacao' => TipoOperacaoPedido::with('atualizadoPor:id,nome', 'criadoPor:id,nome')->get()
                ->sortBy(fn ($t) => [$t->ativo ? 0 : 1, (int) $t->codigo])->values(),
        ]);
    }

    public function store(Request $request, EtapasPedidoVenda $etapas): RedirectResponse
    {
        $dados = $request->validate([
            'etapa' => ['required', Rule::in(EtapasPedidoVenda::CONFIGURAVEIS)],
            'campo' => ['required', 'string', 'max:10'],
            'operador' => ['required', Rule::in(array_keys(RegraEtapaPedido::OPERADORES))],
            'valores' => ['nullable', 'string', 'max:200'],
            'observacao' => ['nullable', 'string', 'max:200'],
        ], [
            'campo.required' => 'Informe o campo do Protheus (ex.: C9_STSERV).',
        ]);
        $dados['campo'] = strtoupper(trim($dados['campo']));
        if (! preg_match('/^C9_[A-Z0-9_]{1,7}$/', $dados['campo'])) {
            return back()->withInput()->withErrors(['campo' => 'O campo precisa ser da SC9 (começa com C9_, ex.: C9_STSERV).']);
        }
        $dados['valores'] = implode(',', array_map('trim', array_filter(explode(',', (string) ($dados['valores'] ?? '')), fn ($v) => trim($v) !== '')));
        if ($dados['valores'] === '' && ! in_array($dados['operador'], ['preenchido', 'vazio'], true)) {
            return back()->withInput()->withErrors(['valores' => 'Informe um ou mais valores, separados por vírgula.']);
        }

        RegraEtapaPedido::create($dados + ['ativo' => true]);
        $alterados = $etapas->recalcularAbertos();

        return redirect()->route('faturamento.etapas.index')->with('ok', 'Regra criada. '.$this->resumo($alterados));
    }

    /** Desativa ou reativa a regra. */
    public function alternar(RegraEtapaPedido $regra, EtapasPedidoVenda $etapas): RedirectResponse
    {
        $regra->update(['ativo' => ! $regra->ativo]);
        $alterados = $etapas->recalcularAbertos();

        return redirect()->route('faturamento.etapas.index')
            ->with('ok', ($regra->ativo ? 'Regra reativada. ' : 'Regra desativada. ').$this->resumo($alterados));
    }

    private function resumo(int $alterados): string
    {
        return $alterados === 1 ? '1 pedido mudou de etapa ou de data.' : "{$alterados} pedidos mudaram de etapa ou de data.";
    }

    /**
     * Valores de cada campo extra nas liberações em aberto (sem nota), com a quantidade, para ajudar a montar as regras.
     *
     * @return array<string, array<string, int>>
     */
    private function valoresEncontrados(): array
    {
        $valores = [];
        foreach (PedidoVendaLiberacao::where('c9_nfiscal', '')->whereNotNull('extras')
            ->orderByDesc('id')->limit(self::AMOSTRA)->cursor() as $l) {
            foreach ($l->extras() as $campo => $valor) {
                $v = trim((string) $valor) === '' ? '(vazio)' : trim((string) $valor);
                $valores[$campo][$v] = ($valores[$campo][$v] ?? 0) + 1;
            }
        }
        ksort($valores);
        foreach ($valores as &$lista) {
            arsort($lista);
            $lista = array_slice($lista, 0, 15, true);
        }

        return $valores;
    }
}
