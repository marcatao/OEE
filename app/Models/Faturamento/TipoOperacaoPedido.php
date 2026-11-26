<?php

namespace App\Models\Faturamento;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de operação (C6_OPER, tabela DJ da SX5) dos pedidos de venda que o portal sincroniza (Faturamento › Etapas dos
 * pedidos). A procedure dbo.sp_sincronizar_pedidos_venda só lê do Protheus os itens com um tipo ATIVO daqui.
 * Código sem zeros à esquerda ("1" = "01"), de 1 a 99. Configuração da empresa toda (sem unidade). Excluir na tela =
 * desativar (não apaga).
 */
class TipoOperacaoPedido extends Model
{
    use Auditavel;

    protected $table = 'tipos_operacao_pedido';

    protected $fillable = ['codigo', 'descricao', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    /** "01", " 1", "1" → "1"; fora de 1 a 99 ou não numérico → null. */
    public static function normalizar(?string $codigo): ?string
    {
        $codigo = trim((string) $codigo);
        if (! preg_match('/^\d{1,3}$/', $codigo)) {
            return null;
        }
        $n = (int) $codigo;

        return $n >= 1 && $n <= 99 ? (string) $n : null;
    }

    /** Como aparece no Protheus: "01", "10". */
    public function codigoProtheus(): string
    {
        return str_pad((string) $this->codigo, 2, '0', STR_PAD_LEFT);
    }
}
