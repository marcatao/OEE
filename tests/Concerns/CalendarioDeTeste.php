<?php

namespace Tests\Concerns;

use App\Models\Calendario;

/** Calendário + jornada para cadastrar máquina ativa nos testes (obrigatórios para ativar). */
trait CalendarioDeTeste
{
    private ?int $calendarioDeTeste = null;

    /** @return array{calendario_id: int, dias: list<int>, jornada_inicio: string, jornada_fim: string} */
    protected function jornada(): array
    {
        $this->calendarioDeTeste ??= Calendario::withoutGlobalScopes()->firstOrCreate(
            ['unidade' => 'ambos', 'nome' => 'Teste', 'ano' => 2026], ['ativo' => true])->id;

        return ['calendario_id' => $this->calendarioDeTeste, 'dias' => [1, 2, 3, 4, 5], 'jornada_inicio' => '06:00', 'jornada_fim' => '22:00'];
    }

    /** Os mesmos campos como gravados na máquina (para Maquina::create). */
    protected function jornadaGravada(): array
    {
        $j = $this->jornada();

        return ['calendario_id' => $j['calendario_id'], 'dias_semana' => '12345', 'jornada_inicio' => '06:00', 'jornada_fim' => '22:00'];
    }
}
