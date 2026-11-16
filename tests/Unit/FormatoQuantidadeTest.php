<?php

namespace Tests\Unit;

use App\Support\Formato;
use PHPUnit\Framework\TestCase;

/** Quantidades do Protheus aparecem com as casas decimais dele (até 6), sem zeros sobrando. */
class FormatoQuantidadeTest extends TestCase
{
    public function test_quantidade_com_as_casas_do_protheus(): void
    {
        $this->assertSame('0,000604', Formato::quantidade(0.000604));
        $this->assertSame('0,0025', Formato::quantidade(0.0025));
        $this->assertSame('2', Formato::quantidade(2.0));
        $this->assertSame('1.234,5', Formato::quantidade(1234.5));
        $this->assertSame('1.000', Formato::quantidade(1000.0));
        $this->assertSame('-0,5', Formato::quantidade(-0.5));
        $this->assertSame('0', Formato::quantidade(-0.0000001));
        $this->assertSame('—', Formato::quantidade(null));
    }
}
