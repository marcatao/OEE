<?php

namespace App\Services\Compras;

use App\Models\Compras\PedidoCompra;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Integração dos pedidos de compra com o Protheus pelas TABELAS MURO NATIVAS (ZP1 cabeçalho, ZP2 itens; criadas no
 * dicionário pelo compatibilizador U_OEEUPDPC, config/compras.php):
 * - gerar(): grava o pedido no portal e na muro (ZP1_STATUS = AGUARDANDO_PROTHEUS);
 * - atualizar(): lê na muro o que o Protheus devolveu (número, aprovação, conclusão, valores, erro);
 * - cancelar() / reenviar(): só enquanto o Protheus não pegou o pedido (ou deu erro), com UPDATE condicionado ao status.
 * A ligação é pelo nº do pedido do portal (ZP1_IDPORT / ZP2_IDPORT, 10 dígitos). Como em toda tabela do Protheus, o
 * portal grava R_E_C_N_O_ (próximo número) e D_E_L_E_T_ = ' ', e só lê os não excluídos; datas = AAAAMMDD + HH:MM:SS no
 * horário local (compras.fuso_muro). Na muro o portal só grava estas duas tabelas.
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

    /** Nome do campo do cabeçalho (ZP1_xxx). */
    public static function cab(string $campo): string
    {
        return config('compras.alias_pedidos').'_'.$campo;
    }

    /** Nome do campo do item (ZP2_xxx). */
    public static function ite(string $campo): string
    {
        return config('compras.alias_itens').'_'.$campo;
    }

    /** Chave de ligação: nº do portal com 10 dígitos (ZP1_IDPORT, ZP2_IDPORT, ZP2_IDITEM). */
    public static function chave(int $id): string
    {
        return str_pad((string) $id, 10, '0', STR_PAD_LEFT);
    }

    private static function pedidos(): Builder
    {
        return self::conexao()->table(config('compras.tabela_pedidos'))->where('D_E_L_E_T_', '<>', '*');
    }

    private static function itens(): Builder
    {
        return self::conexao()->table(config('compras.tabela_itens'))->where('D_E_L_E_T_', '<>', '*');
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
                'observacao' => isset($cabecalho['observacao']) ? mb_substr(trim((string) $cabecalho['observacao']), 0, 250) : null,
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
                    'observacao' => isset($item['observacao']) ? mb_substr(trim((string) $item['observacao']), 0, 100) : null,
                ]);
            }
            $this->enviar($pedido->load('itens'), $cabecalho['origem'], $u);

            return $pedido;
        });
    }

    /** INSERT do cabeçalho e dos itens na muro (transação da conexão da muro), com o próximo R_E_C_N_O_ de cada tabela. */
    private function enviar(PedidoCompra $pedido, string $origem, Usuario $u): void
    {
        [$data, $hora] = $this->agoraLocal();
        self::conexao()->transaction(function ($muro) use ($pedido, $origem, $u, $data, $hora) {
            $c = fn (string $campo) => self::cab($campo);
            $i = fn (string $campo) => self::ite($campo);
            $muro->table(config('compras.tabela_pedidos'))->insert([
                $c('FILIAL') => ' ',
                $c('IDPORT') => self::chave($pedido->id),
                $c('ORIGEM') => mb_substr($origem, 0, 30),
                $c('FILPED') => $pedido->filial !== '' ? $pedido->filial : ' ',
                $c('CCORIG') => $pedido->centro_custo_origem,
                $c('CCDEST') => $pedido->centro_custo_destino,
                $c('FORNEC') => ' ', $c('LOJA') => ' ', $c('COND') => ' ',
                $c('SOLIC') => mb_substr((string) $u->nome, 0, 60),
                $c('OBS') => (string) $pedido->observacao !== '' ? mb_substr((string) $pedido->observacao, 0, 250) : ' ',
                $c('STATUS') => 'AGUARDANDO_PROTHEUS',
                $c('MSG') => ' ',
                $c('NUMPED') => ' ',
                $c('TENTAT') => 0,
                $c('DTINT') => ' ', $c('HRINT') => ' ', $c('APROV') => ' ', $c('DTAPRO') => ' ', $c('HRAPRO') => ' ',
                $c('DTCONC') => ' ', $c('HRCONC') => ' ', $c('VALOR') => 0,
                $c('DTCRIA') => $data, $c('HRCRIA') => $hora,
                $c('DTATU') => $data, $c('HRATU') => $hora,
                'D_E_L_E_T_' => ' ',
                'R_E_C_N_O_' => $this->proximoRecno($muro, config('compras.tabela_pedidos')),
            ]);
            $recno = $this->proximoRecno($muro, config('compras.tabela_itens'));
            $muro->table(config('compras.tabela_itens'))->insert($pedido->itens->values()->map(fn ($item, $n) => [
                $i('FILIAL') => ' ',
                $i('IDPORT') => self::chave($pedido->id),
                $i('ITEM') => $item->item,
                $i('IDITEM') => self::chave($item->id),
                $i('PROD') => $item->produto_codigo,
                $i('DESC') => (string) $item->produto_descricao !== '' ? mb_substr((string) $item->produto_descricao, 0, 60) : ' ',
                $i('UM') => (string) $item->um !== '' ? mb_substr((string) $item->um, 0, 2) : ' ',
                $i('QUANT') => $item->quantidade,
                $i('PRECO') => 0,
                $i('OBS') => (string) $item->observacao !== '' ? mb_substr((string) $item->observacao, 0, 100) : ' ',
                $i('C7ITEM') => ' ', $i('VUNIT') => 0, $i('VTOTAL') => 0, $i('QTDENT') => 0,
                'D_E_L_E_T_' => ' ',
                'R_E_C_N_O_' => $recno + $n,
            ])->all());
        });
    }

    /** Próximo R_E_C_N_O_ da tabela do Protheus (no SQL Server, travando a leitura até o fim da transação). */
    private function proximoRecno(ConnectionInterface $muro, string $tabela): int
    {
        $trava = $muro->getDriverName() === 'sqlsrv' ? ' WITH (UPDLOCK, HOLDLOCK)' : '';

        return (int) $muro->selectOne("SELECT COALESCE(MAX(R_E_C_N_O_), 0) + 1 AS proximo FROM {$tabela}{$trava}")->proximo;
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
            $chaves = $lote->keys()->map(fn ($id) => self::chave((int) $id))->all();
            $cabecalhos = self::pedidos()->whereIn(self::cab('IDPORT'), $chaves)->get()->keyBy(fn ($r) => (int) $r->{self::cab('IDPORT')});
            $linhas = $cabecalhos->isEmpty() ? collect() : self::itens()->whereIn(self::ite('IDPORT'), $chaves)->get()
                ->keyBy(fn ($r) => (int) $r->{self::ite('IDITEM')});
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
        [$data, $hora] = $this->agoraLocal();
        $alterados = self::pedidos()->where(self::cab('IDPORT'), self::chave($pedido->id))->whereIn(self::cab('STATUS'), $de)
            ->update([self::cab('STATUS') => $para, self::cab('MSG') => $mensagem ?? ' ', self::cab('DTATU') => $data, self::cab('HRATU') => $hora]);
        if ($alterados === 0) {
            $this->atualizar(collect([$pedido]));
            throw ValidationException::withMessages(['pedido' => $recusa]);
        }
        $pedido->forceFill(['status' => self::STATUS_MURO[$para], 'mensagem' => $mensagem, 'consultado_em' => now()])->save();
    }

    /** Copia o que veio da muro para o pedido e os itens. Devolve true se algo mudou. */
    private function copiar(PedidoCompra $pedido, object $muro, Collection $linhas): bool
    {
        $v = fn (object $r, string $campo) => $r->{$campo} ?? null;
        $texto = fn ($valor, int $max) => ($t = trim((string) $valor)) !== '' ? mb_substr($t, 0, $max) : null;
        $status = self::STATUS_MURO[strtoupper(trim((string) $v($muro, self::cab('STATUS'))))] ?? $pedido->status;
        $pedido->forceFill([
            'status' => $status,
            'numero_pedido' => $texto($v($muro, self::cab('NUMPED')), 10),
            'mensagem' => $texto($v($muro, self::cab('MSG')), 1000),
            'integrado_em' => $this->utc($v($muro, self::cab('DTINT')), $v($muro, self::cab('HRINT'))),
            'aprovador' => $texto($v($muro, self::cab('APROV')), 120),
            'aprovado_em' => $this->utc($v($muro, self::cab('DTAPRO')), $v($muro, self::cab('HRAPRO'))),
            'concluido_em' => $this->utc($v($muro, self::cab('DTCONC')), $v($muro, self::cab('HRCONC'))),
            // Valor 0 = ainda sem valor do Protheus.
            'valor_total' => (float) $v($muro, self::cab('VALOR')) > 0 ? $v($muro, self::cab('VALOR')) : null,
        ]);
        $mudou = $pedido->isDirty();
        $pedido->consultado_em = now();
        $pedido->saveQuietly();

        $integrado = $texto($v($muro, self::cab('NUMPED')), 10) !== null;
        foreach ($pedido->itens as $item) {
            $linha = $linhas[$item->id] ?? null;
            if ($linha === null || ! $integrado) {
                continue;
            }
            $item->forceFill([
                'valor_unitario' => $v($linha, self::ite('VUNIT')),
                'valor_total' => $v($linha, self::ite('VTOTAL')),
                'quantidade_entregue' => $v($linha, self::ite('QTDENT')),
            ]);
            if ($item->isDirty()) {
                $item->saveQuietly();
                $mudou = true;
            }
        }

        return $mudou;
    }

    /** @return array{0: string, 1: string} data AAAAMMDD e hora HH:MM:SS locais */
    private function agoraLocal(): array
    {
        $agora = CarbonImmutable::now(config('compras.fuso_muro'));

        return [$agora->format('Ymd'), $agora->format('H:i:s')];
    }

    /** Data (AAAAMMDD) + hora (HH:MM ou HH:MM:SS) local do Protheus => UTC. Data em branco = sem data. */
    private function utc(mixed $data, mixed $hora): ?CarbonImmutable
    {
        $data = trim((string) $data);
        if (! preg_match('/^\d{8}$/', $data)) {
            return null;
        }
        $hora = trim((string) $hora);
        $hora = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hora) ? (strlen($hora) === 5 ? $hora.':00' : $hora) : '00:00:00';

        return CarbonImmutable::createFromFormat('Ymd H:i:s', $data.' '.$hora, config('compras.fuso_muro'))->utc();
    }
}
