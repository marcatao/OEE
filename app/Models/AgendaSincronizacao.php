<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;

/**
 * Periodicidade da sincronização de uma tabela do Protheus: "a cada {intervalo} a partir das {inicio}" de cada dia
 * (horário local). As regras ficam em App\Services\Protheus\AgendaSincronizacoes.
 */
class AgendaSincronizacao extends Model
{
    use Auditavel;

    protected $table = 'agendas_sincronizacao';

    protected $fillable = ['chave', 'ativo', 'intervalo_minutos', 'inicio', 'ultimo_horario'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'intervalo_minutos' => 'integer', 'ultimo_horario' => 'datetime'];
    }

    /** "01:00", "23:59", "24:00". */
    public function intervaloTexto(): string
    {
        return sprintf('%02d:%02d', intdiv($this->intervalo_minutos, 60), $this->intervalo_minutos % 60);
    }
}
