<?php

namespace Tests\Unit;

use App\Http\Controllers\Manutencao\RelatorioManutencaoController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Exportação CSV: texto digitado que começa como fórmula não vira fórmula no Excel. */
class CsvSeguroTest extends TestCase
{
    public function test_celula_que_parece_formula_ganha_apostrofo(): void
    {
        $f = new ReflectionMethod(RelatorioManutencaoController::class, 'celulaSegura');
        $this->assertSame("'=HYPERLINK(\"x\")", $f->invoke(null, '=HYPERLINK("x")'));
        $this->assertSame("'@SOMA(A1)", $f->invoke(null, '@SOMA(A1)'));
        $this->assertSame('Troca do rolamento', $f->invoke(null, 'Troca do rolamento'));
        $this->assertSame(12, $f->invoke(null, 12));
        $this->assertSame('', $f->invoke(null, ''));
    }
}
