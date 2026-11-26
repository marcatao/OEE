<?php

namespace Tests\Unit;

use App\Services\CiclosIot;
use PHPUnit\Framework\TestCase;

/** Ciclo a ciclo pela IOT001 (gráfico do Dashboard por máquina, pedido do usuário em 26/11/2026). */
class CiclosIotTest extends TestCase
{
    public function test_intervalo_entre_mudancas_ciclo_por_peca_e_paradas(): void
    {
        // Leituras a cada 10 s; o contador sobe 250 por leitura (0,040 s por peça), para 10 min e volta.
        $l = [[990, 100.0], [1000, 350.0], [1010, 600.0], [1020, 850.0], [1030, 850.0], [1630, 1100.0], [1640, 1350.0]];
        $r = CiclosIot::calcular($l, 1000, 2000, 0.5, 300.0);

        $this->assertSame(15.0, $r['limites']['micro']); // 1,5 × passo de 10 s (como o integrador)
        $c = $r['ciclos'];
        $this->assertCount(5, $c);
        $this->assertNull($c[0]['intervalo']); // 990 é só a base: a 1ª mudança não tem a anterior
        $this->assertSame(10, $c[1]['intervalo']);
        $this->assertSame(0.04, $c[1]['por_peca']);
        $this->assertSame('normal', $c[1]['nivel']);
        $this->assertSame(610, $c[3]['intervalo']); // 1020 → 1630
        $this->assertSame('parada', $c[3]['nivel']);
        $this->assertSame(1, $r['resumo']['paradas']);
        $this->assertSame(610, $r['resumo']['maior_parada']);
        $this->assertSame(0.04, $r['resumo']['ciclo_medio']); // sem a parada
        $this->assertSame(1250, $r['resumo']['pecas']);
    }

    public function test_contador_zerado_e_microparada(): void
    {
        $l = [[0, 10.0], [10, 11.0], [20, 12.0], [50, 13.0], [60, 2.0], [70, 3.0]];
        $r = CiclosIot::calcular($l, 0, 100, null, null);
        $niveis = array_column($r['ciclos'], 'nivel');
        $this->assertSame(['normal', 'normal', 'micro', 'normal', 'normal'], $niveis);
        $this->assertSame(2, $r['ciclos'][3]['pecas']); // zerado: conta o valor novo
        $this->assertTrue($r['ciclos'][3]['zerado']);
        $this->assertTrue($r['limites']['macro_padrao']);
        $this->assertNull($r['ciclos'][0]['intervalo']); // 1ª mudança sem a anterior: sem intervalo
    }
}
