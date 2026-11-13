<?php

namespace App\Services\Calendario;

use App\Models\Calendario;
use App\Models\CalendarioDia;
use App\Models\Manutencao\Mantenedor;
use App\Models\Maquina;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Dias úteis de um calendário. Padrão: segunda a sexta úteis; uma exceção (calendario_dias) manda no dia.
 * Para máquina e mantenedor: trabalha no dia se o calendário marca o dia como útil por exceção (compensação, dia
 * útil extra) ou, sem exceção, se o dia da semana está nos dias de trabalho dele; feriado, ponte e folga = não trabalha.
 */
class DiasUteis
{
    public function __construct(private Feriados $feriados) {}

    /** Cria o calendário do ano já com os feriados (nacionais, SP e São Bernardo do Campo). */
    public function criar(array $dados): Calendario
    {
        return DB::transaction(function () use ($dados) {
            $calendario = Calendario::create($dados);
            foreach ($this->feriados->doAno((int) $calendario->ano) as $data => $descricao) {
                $calendario->dias()->create(['data' => $data, 'tipo' => 'feriado', 'descricao' => $descricao]);
            }

            return $calendario;
        });
    }

    /**
     * Cria o calendário do ano seguinte (mesmo nome e unidade, feriados do novo ano). Com $mover, as máquinas e os
     * mantenedores deste calendário passam a usar o novo.
     */
    public function proximoAno(Calendario $calendario, bool $mover): Calendario
    {
        return DB::transaction(function () use ($calendario, $mover) {
            $novo = Calendario::withoutGlobalScopes()->where(['unidade' => $calendario->unidade, 'nome' => $calendario->nome, 'ano' => $calendario->ano + 1])->first()
                ?? $this->criar(['unidade' => $calendario->unidade, 'nome' => $calendario->nome, 'ano' => $calendario->ano + 1,
                    'descricao' => $calendario->descricao, 'ativo' => true]);
            if ($mover) {
                Maquina::withoutGlobalScopes()->where('calendario_id', $calendario->id)->update(['calendario_id' => $novo->id]);
                Mantenedor::withoutGlobalScopes()->where('calendario_id', $calendario->id)->update(['calendario_id' => $novo->id]);
            }

            return $novo;
        });
    }

    /** Exceções do calendário. @return array<string, CalendarioDia> "AAAA-MM-DD" => exceção */
    public function excecoes(Calendario $calendario): array
    {
        return $calendario->dias()->orderBy('data')->get()->keyBy(fn ($d) => $d->data->format('Y-m-d'))->all();
    }

    /**
     * Situação do dia no calendário (sem jornada específica: segunda a sexta úteis).
     *
     * @return array{util: bool, tipo: ?string, descricao: ?string}
     */
    public function dia(Calendario $calendario, CarbonInterface|string $data, ?array $excecoes = null, string $diasSemana = '12345'): array
    {
        $data = CarbonImmutable::parse($data);
        $excecoes ??= $this->excecoes($calendario);
        $excecao = $excecoes[$data->format('Y-m-d')] ?? null;
        if ($excecao) {
            return ['util' => $excecao->util(), 'tipo' => $excecao->tipo, 'descricao' => $excecao->descricao];
        }

        return ['util' => str_contains($diasSemana, (string) $data->dayOfWeekIso), 'tipo' => null, 'descricao' => null];
    }

    /** A máquina ou o mantenedor trabalha no dia? (sem calendário: só pelos dias da semana) */
    public function trabalha(Maquina|Mantenedor $quem, CarbonInterface|string $data): bool
    {
        $dias = (string) ($quem->dias_semana ?: '12345');
        if (! $quem->calendario_id) {
            return str_contains($dias, (string) CarbonImmutable::parse($data)->dayOfWeekIso);
        }
        $calendario = Calendario::withoutGlobalScopes()->find($quem->calendario_id);

        return $calendario ? $this->dia($calendario, $data, null, $dias)['util'] : false;
    }

    /** Dias úteis por mês e no ano (segunda a sexta + exceções). @return array{meses: array<int, int>, total: int} */
    public function resumo(Calendario $calendario): array
    {
        $excecoes = $this->excecoes($calendario);
        $meses = array_fill(1, 12, 0);
        for ($d = CarbonImmutable::create($calendario->ano, 1, 1); $d->year === $calendario->ano; $d = $d->addDay()) {
            if ($this->dia($calendario, $d, $excecoes)['util']) {
                $meses[$d->month]++;
            }
        }

        return ['meses' => $meses, 'total' => array_sum($meses)];
    }
}
