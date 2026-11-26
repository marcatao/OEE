<?php

namespace Tests\Unit;

use App\Services\Faturamento\EtapasPedidoVenda;
use PHPUnit\Framework\TestCase;

/**
 * Regra das etapas dos pedidos de venda (EtapasPedidoVenda): liberação → etapa, item e pedido na etapa mais atrasada,
 * parcial, resíduo, faturado, valores e datas.
 */
class EtapasPedidoVendaTest extends TestCase
{
    /** Regras do WMS de exemplo (como as que o usuário vai cadastrar na tela). */
    private const REGRAS = [
        'separacao' => [['campo' => 'C9_STSERV', 'operador' => 'igual', 'valores' => ['1', '2']]],
        'conferencia' => [['campo' => 'C9_STSERV', 'operador' => 'igual', 'valores' => ['3']]],
        'logistica' => [['campo' => 'C9_CARGA', 'operador' => 'preenchido', 'valores' => []]],
        'aguardando_faturamento' => [['campo' => 'C9_XSTATUS', 'operador' => 'comeca', 'valores' => ['AF']]],
    ];

    private static function item(string $item, float $qtd, float $entregue = 0, array $mais = []): array
    {
        return $mais + ['C6_ITEM' => $item, 'C6_QTDVEN' => $qtd, 'C6_QTDENT' => $entregue, 'C6_PRCVEN' => 10, 'C6_VALOR' => $qtd * 10,
            'C6_ENTREG' => '20261210', 'C6_DATFAT' => '', 'C6_BLQ' => ''];
    }

    private static function lib(string $item, float $qtd, array $mais = []): array
    {
        return $mais + ['C9_ITEM' => $item, 'C9_QTDLIB' => $qtd, 'C9_DATALIB' => '20261120', 'C9_BLEST' => '', 'C9_BLCRED' => '',
            'C9_NFISCAL' => ''];
    }

    public function test_operadores_das_regras(): void
    {
        $this->assertTrue(EtapasPedidoVenda::regraVale(['operador' => 'igual', 'valores' => ['a', '2']], ' A '));
        $this->assertFalse(EtapasPedidoVenda::regraVale(['operador' => 'igual', 'valores' => ['1']], '2'));
        $this->assertTrue(EtapasPedidoVenda::regraVale(['operador' => 'diferente', 'valores' => ['1']], '2'));
        $this->assertFalse(EtapasPedidoVenda::regraVale(['operador' => 'diferente', 'valores' => []], '2'));
        $this->assertTrue(EtapasPedidoVenda::regraVale(['operador' => 'comeca', 'valores' => ['SEP']], 'SEPARANDO'));
        $this->assertFalse(EtapasPedidoVenda::regraVale(['operador' => 'comeca', 'valores' => ['SEP']], ''));
        $this->assertTrue(EtapasPedidoVenda::regraVale(['operador' => 'preenchido', 'valores' => []], '000123'));
        $this->assertFalse(EtapasPedidoVenda::regraVale(['operador' => 'preenchido', 'valores' => []], '   '));
        $this->assertTrue(EtapasPedidoVenda::regraVale(['operador' => 'vazio', 'valores' => []], null));
    }

    public function test_etapa_da_liberacao(): void
    {
        $regras = self::REGRAS;
        // Bloqueio de crédito ou estoque = carteira; "10" (faturado) não é bloqueio.
        $this->assertSame('carteira', EtapasPedidoVenda::etapaDaLiberacao(self::lib('01', 1, ['C9_BLCRED' => '01']), $regras));
        $this->assertSame('carteira', EtapasPedidoVenda::etapaDaLiberacao(self::lib('01', 1, ['C9_BLEST' => '02']), $regras));
        $this->assertSame('expedicao', EtapasPedidoVenda::etapaDaLiberacao(self::lib('01', 1, ['C9_BLEST' => '10']), $regras));
        // Sem regra que valha = em expedição; com regra = a etapa dela.
        $this->assertSame('expedicao', EtapasPedidoVenda::etapaDaLiberacao(self::lib('01', 1), $regras));
        $this->assertSame('separacao', EtapasPedidoVenda::etapaDaLiberacao(self::lib('01', 1, ['C9_STSERV' => '2']), $regras));
        $this->assertSame('conferencia', EtapasPedidoVenda::etapaDaLiberacao(self::lib('01', 1, ['C9_STSERV' => '3']), $regras));
        // Mais de uma regra vale: ganha a mais adiantada (aguardando faturamento > logística > conferência).
        $this->assertSame('logistica', EtapasPedidoVenda::etapaDaLiberacao(self::lib('01', 1, ['C9_STSERV' => '3', 'C9_CARGA' => '000045']), $regras));
        $this->assertSame('aguardando_faturamento', EtapasPedidoVenda::etapaDaLiberacao(self::lib('01', 1, ['C9_CARGA' => '1', 'C9_XSTATUS' => 'AF1']), $regras));
        // Sem regras cadastradas: todo liberado fica em expedição.
        $this->assertSame('expedicao', EtapasPedidoVenda::etapaDaLiberacao(self::lib('01', 1, ['C9_STSERV' => '3']), []));
    }

    public function test_pedido_fica_na_etapa_do_item_mais_atrasado(): void
    {
        $r = EtapasPedidoVenda::calcular(
            [self::item('01', 10), self::item('02', 5, 0, ['C6_ENTREG' => '20261205'])],
            [self::lib('01', 10, ['C9_STSERV' => '3']), self::lib('02', 5, ['C9_STSERV' => '1'])], self::REGRAS);
        $this->assertSame('separacao', $r['etapa']);
        $this->assertFalse($r['parcial']);
        $this->assertSame(150.0, $r['valor_saldo']);
        $this->assertSame('20261205', $r['entrega_prevista']);
        $this->assertSame('20261120', $r['ultima_liberacao']);

        // Item sem liberação (ou liberado a menos que o saldo) = carteira.
        $this->assertSame('carteira', EtapasPedidoVenda::calcular([self::item('01', 10), self::item('02', 5)], [self::lib('01', 10)], [])['etapa']);
        $this->assertSame('carteira', EtapasPedidoVenda::calcular([self::item('01', 10)], [self::lib('01', 4)], [])['etapa']);
        // Liberação já faturada não conta como liberada.
        $this->assertSame('carteira', EtapasPedidoVenda::calcular([self::item('01', 10)], [self::lib('01', 10, ['C9_NFISCAL' => '000001'])], [])['etapa']);
    }

    public function test_parcial_residuo_e_faturado(): void
    {
        // Parte entregue + saldo liberado = em expedição, parcialmente faturado.
        $r = EtapasPedidoVenda::calcular([self::item('01', 10, 4, ['C6_DATFAT' => '20261115'])], [self::lib('01', 6)], []);
        $this->assertSame('expedicao', $r['etapa']);
        $this->assertTrue($r['parcial']);
        $this->assertSame(60.0, $r['valor_saldo']);
        $this->assertSame(40.0, $r['valor_faturado']);

        // Tudo entregue = faturado, no dia do último faturamento; saldo eliminado por resíduo não segura o pedido.
        $r = EtapasPedidoVenda::calcular([self::item('01', 10, 10, ['C6_DATFAT' => '20261118']),
            self::item('02', 5, 2, ['C6_BLQ' => 'R', 'C6_DATFAT' => '20261110'])], [], []);
        $this->assertSame('faturado', $r['etapa']);
        $this->assertFalse($r['parcial']);
        $this->assertSame('20261118', $r['faturado_em']);
        $this->assertSame(120.0, $r['valor_faturado']);

        // Eliminado por resíduo sem nada entregue = encerrado (fora da tela).
        $this->assertNull(EtapasPedidoVenda::calcular([self::item('01', 10, 0, ['C6_BLQ' => 'R'])], [], [])['etapa']);
    }

    public function test_ordem_das_etapas(): void
    {
        $this->assertSame(['carteira', 'expedicao', 'separacao', 'conferencia', 'logistica', 'aguardando_faturamento', 'faturado'],
            array_keys(EtapasPedidoVenda::ETAPAS));
        $this->assertLessThan(EtapasPedidoVenda::ordem('separacao'), EtapasPedidoVenda::ordem('expedicao'));
    }
}
