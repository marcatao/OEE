<?php

namespace App\Http\Controllers\Faturamento;

use App\Http\Controllers\Controller;
use App\Models\Faturamento\TipoOperacaoPedido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Faturamento › Etapas dos pedidos › Tipos de operação sincronizados: inclui e exclui (desativa) os tipos de operação
 * (C6_OPER) dos pedidos de venda que a procedure lê do Protheus. Vale na próxima sincronização "Pedidos de venda".
 */
class TipoOperacaoPedidoController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'max:3'],
            'descricao' => ['nullable', 'string', 'max:80'],
        ], ['codigo.required' => 'Informe o código do tipo de operação (ex.: 1 ou 01).']);

        $codigo = TipoOperacaoPedido::normalizar($dados['codigo']);
        if ($codigo === null) {
            return back()->withInput()->withErrors(['codigo' => 'O código do tipo de operação é um número de 1 a 99 (ex.: 1 ou 01).'], 'tipos');
        }
        $descricao = mb_strtoupper(trim((string) ($dados['descricao'] ?? '')));

        $tipo = TipoOperacaoPedido::where('codigo', $codigo)->first();
        if ($tipo?->ativo) {
            return back()->withInput()->withErrors(['codigo' => "O tipo {$tipo->codigoProtheus()} já está na lista."], 'tipos');
        }
        if ($tipo) {
            $tipo->update(['ativo' => true] + ($descricao !== '' ? ['descricao' => $descricao] : []));
        } else {
            $tipo = TipoOperacaoPedido::create(['codigo' => $codigo, 'descricao' => $descricao, 'ativo' => true]);
        }

        return redirect()->to(route('faturamento.etapas.index').'#tipos-operacao')
            ->with('ok', "Tipo de operação {$tipo->codigoProtheus()} incluído. Vale na próxima sincronização dos pedidos de venda.");
    }

    /** "Excluir" da lista = desativar (o registro fica, para voltar com "Incluir"). */
    public function destroy(TipoOperacaoPedido $tipo): RedirectResponse
    {
        $tipo->update(['ativo' => false]);

        return redirect()->to(route('faturamento.etapas.index').'#tipos-operacao')
            ->with('ok', "Tipo de operação {$tipo->codigoProtheus()} excluído. Na próxima sincronização, os pedidos desse tipo dos últimos 6 meses saem do portal.");
    }
}
