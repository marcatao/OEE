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

    public function test_almoco_trabalhado_e_hora_normal_e_sai_do_disponivel(): void
    {
        $h = new HorasManutencao('07:00', '17:00', 60, '12345');
        // Trabalhou no horário do almoço (12:00–13:00, quarta): conta como normal, nunca como extra.
        $this->assertSame(['normais' => 60, 'extras' => 0], $h->dividir($this->local('2026-11-11 12:00'), $this->local('2026-11-11 13:00')));
        // Disponível do dia = jornada de 10 h − 60 min de intervalo.
        $this->assertSame(540, $h->minutosDisponiveis(CarbonImmutable::parse('2026-11-11'), CarbonImmutable::parse('2026-11-11')));
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

    public function test_turnos_do_mantenedor_normal_em_qualquer_turno_e_extra_entre_eles(): void
    {
        // Seg–Sex: T1 05:00–13:30 e T2 14:00–22:00 (meia hora entre eles); T3 22:00–02:00 só na sexta (vira o dia).
        $turnos = array_fill_keys([1, 2, 3, 4], [1 => ['05:00', '13:30'], 2 => ['14:00', '22:00']])
            + [5 => [1 => ['05:00', '13:30'], 2 => ['14:00', '22:00'], 3 => ['22:00', '02:00']]];
        $h = new HorasManutencao('05:00', '22:00', 60, '12345', [], [], $turnos);
        // Quarta 13:00–14:30: 30 min no T1 + 30 no T2 = normais; os 30 entre os turnos = extra.
        $this->assertSame(['normais' => 60, 'extras' => 30], $h->dividir($this->local('2026-11-11 13:00'), $this->local('2026-11-11 14:30')));
        // Quarta 21:00–23:00: 1 h no T2, 1 h depois do último turno = extra.
        $this->assertSame(['normais' => 60, 'extras' => 60], $h->dividir($this->local('2026-11-11 21:00'), $this->local('2026-11-11 23:00')));
        // Sexta 23:00 até sábado 03:00: o T3 vai até as 02:00 do sábado.
        $this->assertSame(['normais' => 180, 'extras' => 60], $h->dividir($this->local('2026-11-13 23:00'), $this->local('2026-11-14 03:00')));
        // Disponível: (8:30 + 8:00) − 60 min de intervalo = 930 min; na sexta + 4 h do T3 = 1170.
        $this->assertSame(930, $h->minutosDisponiveis(CarbonImmutable::parse('2026-11-11'), CarbonImmutable::parse('2026-11-11')));
        $this->assertSame(1170, $h->minutosDisponiveis(CarbonImmutable::parse('2026-11-13'), CarbonImmutable::parse('2026-11-13')));
        $this->assertSame(intdiv(4 * 930 + 1170, 5), $h->minutosPorDia());
        // Feriado: nenhum turno vale.
        $feriado = new HorasManutencao('05:00', '22:00', 60, '12345', [], ['2026-11-11' => false], $turnos);
        $this->assertSame(['normais' => 0, 'extras' => 90], $feriado->dividir($this->local('2026-11-11 13:00'), $this->local('2026-11-11 14:30')));
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
