<?php

namespace Tests\Unit;

use App\Services\Manutencao\HorasManutencao;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class HorasManutencaoTest extends TestCase
{
    private function local(string $quando): CarbonImmutable
    {
        return CarbonImmutable::parse($quando, config('oee.fuso_exibicao'))->utc();
    }

    public function test_dentro_da_jornada_e_tudo_normal(): void
    {
        $h = new HorasManutencao('07:00', '17:00', 60, '12345');
        // 2026-11-11 é quarta-feira
        $this->assertSame(['normais' => 90, 'extras' => 0], $h->dividir($this->local('2026-11-11 08:00'), $this->local('2026-11-11 09:30')));
    }

    public function test_depois_da_jornada_vira_extra(): void
    {
        $h = new HorasManutencao('07:00', '17:00', 60, '12345');
        $this->assertSame(['normais' => 60, 'extras' => 90], $h->dividir($this->local('2026-11-11 16:00'), $this->local('2026-11-11 18:30')));
    }

    public function test_fim_de_semana_e_extra(): void
    {
        $h = new HorasManutencao('07:00', '17:00', 60, '12345');
        // 2026-11-14 é sábado
        $this->assertSame(['normais' => 0, 'extras' => 120], $h->dividir($this->local('2026-11-14 08:00'), $this->local('2026-11-14 10:00')));
    }

    public function test_jornada_noturna_atravessa_a_meia_noite(): void
    {
        $h = new HorasManutencao('22:00', '06:00', 60, '12345');
        // quarta 21:00 até quinta 07:00: 22:00–06:00 normal (480), 1h antes + 1h depois extra
        $this->assertSame(['normais' => 480, 'extras' => 120], $h->dividir($this->local('2026-11-11 21:00'), $this->local('2026-11-12 07:00')));
    }

    public function test_horas_disponiveis_no_periodo(): void
    {
        $h = new HorasManutencao('07:00', '17:00', 60, '12345');
        // segunda 09/11 a domingo 15/11: 5 dias × 9h
        $this->assertSame(5 * 540, $h->minutosDisponiveis(CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-15')));
        $this->assertSame(540, $h->minutosPorDia());
    }

    public function test_formatos(): void
    {
        $this->assertSame('1:30', HorasManutencao::hhmm(90));
        $this->assertSame('1,50', HorasManutencao::decimal(90));
    }

    public function test_feriado_do_calendario_e_todo_extra_e_compensacao_tem_jornada(): void
    {
        // 2026-11-02 (segunda) é feriado; 2026-11-07 (sábado) é compensação.
        $h = new HorasManutencao('07:00', '17:00', 60, '12345', [], ['2026-11-02' => false, '2026-11-07' => true]);
        $fuso = HorasManutencao::fuso();
        $this->assertSame(['normais' => 0, 'extras' => 120],
            $h->dividir(CarbonImmutable::parse('2026-11-02 08:00', $fuso), CarbonImmutable::parse('2026-11-02 10:00', $fuso)));
        $this->assertSame(['normais' => 120, 'extras' => 0],
            $h->dividir(CarbonImmutable::parse('2026-11-07 08:00', $fuso), CarbonImmutable::parse('2026-11-07 10:00', $fuso)));
        // Disponível na semana de 02 a 07/11: 4 dias úteis (03 a 06) + a compensação do sábado = 5 × 540 min.
        $this->assertSame(5 * 540, $h->minutosDisponiveis(CarbonImmutable::parse('2026-11-02', $fuso), CarbonImmutable::parse('2026-11-07', $fuso)));
    }
}
