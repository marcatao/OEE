<?php

namespace App\Services\Compras;

use App\Models\Compras\PedidoCompra;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Integração dos pedidos de compra com o Protheus pela TABELA MURO (config/compras.php):
 * - gerar(): grava o pedido no portal e na muro (STATUS = AGUARDANDO_PROTHEUS);
 * - atualizar(): lê na muro o que o Protheus devolveu (número, aprovação, conclusão, valores, erro);
 * - cancelar() / reenviar(): só enquanto o Protheus não pegou o pedido (ou deu erro), com UPDATE condicionado ao status.
 * Na muro o portal só grava estas duas tabelas; as datas de lá ficam no horário local (compras.fuso_muro).
 */
final class PedidoCompraMuro
{
    /** Status da muro (Protheus) => status do portal. PROCESSANDO = o job pegou e está gerando. */
    public const STATUS_MURO = [
        'AGUARDANDO_PROTHEUS' => 'aguardando_protheus',
        'PROCESSANDO' => 'aguardando_protheus',
        'AGUARDANDO_APROVACAO' => 'aguardando_aprovacao',
        'APROVADO' => 'aprovado',
        'CONCLUIDO' => 'concluido',
        'REPROVADO' => 'reprovado',
        'ERRO' => 'erro',
        'CANCELADO' => 'cancelado',
    ];

    public static function conexao(): ConnectionInterface
    {
        return DB::connection(config('compras.conexao'));
    }

    private static function pedidos(): string
    {
        return (string) config('compras.tabela_pedidos');
    }

    private static function itens(): string
    {
        return (string) config('compras.tabela_itens');
    }

    /**
     * Grava o pedido no portal e na muro, na mesma transação do portal (se a muro falhar, nada fica gravado).
     *
     * @param  array{unidade: string, solicitacao_material_id?: int|null, ordem_servico_id?: int|null, centro_custo_origem: string,
     *     centro_custo_destino: string, observacao?: string|null, origem: string}  $cabecalho
     * @param  list<array{produto_codigo: string, produto_descricao: string, um: string, quantidade: float|string, observacao?: string|null, solicitacao_material_item_id?: int|null}>  $itens
     */
    public function gerar(array $cabecalho, array $itens, Usuario $u): PedidoCompra
    {
        return DB::transaction(function () use ($cabecalho, $itens, $u) {
            $pedido = PedidoCompra::create([
                'unidade' => $cabecalho['unidade'],
                'solicitacao_material_id' => $cabecalho['solicitacao_material_id'] ?? null,
                'ordem_servico_id' => $cabecalho['ordem_servico_id'] ?? null,
                'filial' => (string) config('compras.filial', ''),
                'centro_custo_origem' => trim($cabecalho['centro_custo_origem']),
                'centro_custo_destino' => trim($cabecalho['centro_custo_destino']),
                'observacao' => $cabecalho['observacao'] ?? null,
                'status' => 'aguardando_protheus',
            ]);
            foreach (array_values($itens) as $n => $item) {
                $pedido->itens()->create([
                    'solicitacao_material_item_id' => $item['solicitacao_material_item_id'] ?? null,
                    'item' => str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT),
                    'produto_codigo' => trim($item['produto_codigo']),
                    'produto_descricao' => mb_substr((string) $item['produto_descricao'], 0, 120),
                    'um' => mb_substr(trim((string) $item['um']), 0, 4),
                    'quantidade' => $item['quantidade'],
                    'observacao' => isset($item['observacao']) ? mb_substr(trim((string) $item['observacao']), 0, 250) : null,
                ]);
            }
            $this->enviar($pedido->load('itens'), $cabecalho['origem'], $u);

            return $pedido;
        });
    }

    /** INSERT do cabeçalho e dos itens na muro (transação da conexão da muro). */
    private function enviar(PedidoCompra $pedido, string $origem, Usuario $u): void
    {
        $agora = $this->agoraLocal();
        self::conexao()->transaction(function ($muro) use ($pedido, $origem, $u, $agora) {
            $id = $muro->table(self::pedidos())->insertGetId([
                'ID_PORTAL' => $pedido->id,
                'ORIGEM' => mb_substr($origem, 0, 30),
                'FILIAL' => $pedido->filial,
                'CC_ORIGEM' => $pedido->centro_custo_origem,
                'CC_DESTINO' => $pedido->centro_custo_destino,
                'FORNECEDOR' => '', 'LOJA' => '', 'COND_PAGTO' => '',
                'SOLICITANTE' => mb_substr((string) $u->nome, 0, 120),
                'OBSERVACAO' => mb_substr((string) $pedido->observacao, 0, 500),
                'STATUS' => 'AGUARDANDO_PROTHEUS',
                'NUM_PEDIDO' => '',
                'TENTATIVAS' => 0,
                'CRIADO_EM' => $agora,
            ], 'ID');
            $muro->table(self::itens())->insert($pedido->itens->map(fn ($i) => [
                'ID_PEDIDO' => $id,
                'ITEM' => $i->item,
                'ID_ITEM_PORTAL' => $i->id,
                'PRODUTO' => $i->produto_codigo,
                'DESCRICAO' => (string) $i->produto_descricao,
                'UM' => mb_substr((string) $i->um, 0, 2),
                'QUANTIDADE' => $i->quantidade,
                'PRECO' => 0,
                'OBSERVACAO' => (string) $i->observacao,
                'C7_ITEM' => '',
            ])->all());
        });
    }

    /**
     * Lê na muro a situação dos pedidos ainda não finalizados e grava no portal. Devolve quantos mudaram.
     *
     * @param  Collection<int, PedidoCompra>  $pedidos
     */
    public function atualizar(Collection $pedidos): int
    {
        $abertos = $pedidos->reject(fn ($p) => $p->estaFinal())->keyBy('id');
        if ($abertos->isEmpty()) {
            return 0;
        }
        $mudaram = 0;
        foreach ($abertos->chunk(500) as $lote) {
            $cabecalhos = self::conexao()->table(self::pedidos())->whereIn('ID_PORTAL', $lote->keys()->all())->get()->keyBy('ID_PORTAL');
            $linhas = $cabecalhos->isEmpty() ? collect() : self::conexao()->table(self::itens())
                ->whereIn('ID_PEDIDO', $cabecalhos->pluck('ID')->all())->get()->keyBy('ID_ITEM_PORTAL');
            foreach ($lote as $pedido) {
                $muro = $cabecalhos[$pedido->id] ?? null;
                if ($muro !== null && $this->copiar($pedido, $muro, $linhas)) {
                    $mudaram++;
                }
            }
        }

        return $mudaram;
    }

    /** Lê a muro sem derrubar a tela (banco do Protheus fora do ar): devolve false se não deu para consultar. */
    public function atualizarSemFalhar(Collection $pedidos): bool
    {
        try {
            $this->atualizar($pedidos);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Cancela no portal e na muro, só se o Protheus ainda não pegou o pedido (ou deu erro). */
    public function cancelar(PedidoCompra $pedido): void
    {
        $this->trocarStatus($pedido, ['AGUARDANDO_PROTHEUS', 'ERRO'], 'CANCELADO', 'Pedido cancelado no portal.',
            'O Protheus já pegou este pedido: não dá mais para cancelar pelo portal (cancele no Protheus).');
    }

    /** Pedido com erro volta para a fila do Protheus (depois de corrigido o cadastro lá). */
    public function reenviar(PedidoCompra $pedido): void
    {
        $this->trocarStatus($pedido, ['ERRO'], 'AGUARDANDO_PROTHEUS', null, 'Só pedido com erro pode ser reenviado.');
    }

    private function trocarStatus(PedidoCompra $pedido, array $de, string $para, ?string $mensagem, string $recusa): void
    {
        $alterados = self::conexao()->table(self::pedidos())->where('ID_PORTAL', $pedido->id)->whereIn('STATUS', $de)
            ->update(['STATUS' => $para, 'MENSAGEM' => $mensagem, 'ATUALIZADO_EM' => $this->agoraLocal()]);
        if ($alterados === 0) {
            $this->atualizar(collect([$pedido]));
            throw ValidationException::withMessages(['pedido' => $recusa]);
        }
        $pedido->forceFill(['status' => self::STATUS_MURO[$para], 'mensagem' => $mensagem, 'consultado_em' => now()])->save();
    }

    /** Copia o que veio da muro para o pedido e os itens. Devolve true se algo mudou. */
    private function copiar(PedidoCompra $pedido, object $muro, Collection $linhas): bool
    {
        $status = self::STATUS_MURO[strtoupper(trim((string) $muro->STATUS))] ?? $pedido->status;
        $numero = trim((string) ($muro->NUM_PEDIDO ?? ''));
        $pedido->forceFill([
            'status' => $status,
            'numero_pedido' => $numero !== '' ? $numero : null,
            'mensagem' => ($m = trim((string) ($muro->MENSAGEM ?? ''))) !== '' ? mb_substr($m, 0, 1000) : null,
            'integrado_em' => $this->utc($muro->INTEGRADO_EM ?? null),
            'aprovador' => ($a = trim((string) ($muro->APROVADOR ?? ''))) !== '' ? mb_substr($a, 0, 120) : null,
            'aprovado_em' => $this->utc($muro->APROVADO_EM ?? null),
            'concluido_em' => $this->utc($muro->CONCLUIDO_EM ?? null),
            'valor_total' => $muro->VALOR_TOTAL ?? null,
        ]);
        $mudou = $pedido->isDirty();
        $pedido->consultado_em = now();
        $pedido->saveQuietly();

        foreach ($pedido->itens as $item) {
            $linha = $linhas[$item->id] ?? null;
            if ($linha === null) {
                continue;
            }
            $item->forceFill([
                'valor_unitario' => $linha->VALOR_UNITARIO ?? null,
                'valor_total' => $linha->VALOR_TOTAL ?? null,
                'quantidade_entregue' => $linha->QTD_ENTREGUE ?? null,
            ]);
            if ($item->isDirty()) {
                $item->saveQuietly();
                $mudou = true;
            }
        }

        return $mudou;
    }

    private function agoraLocal(): string
    {
        return CarbonImmutable::now(config('compras.fuso_muro'))->format('Y-m-d H:i:s');
    }

    private function utc(mixed $valor): ?CarbonImmutable
    {
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $valor, config('compras.fuso_muro'))->utc();
    }
}
