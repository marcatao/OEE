<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apontamento de estado da máquina (timeline): de "inicio" até "fim" a máquina ficou na legenda informada.
 * "fim" vazio = a máquina ainda está nesse estado. Datas em UTC.
 */
class ApontamentoEstado extends Model
{
    use Auditavel;

    protected $table = 'apontamentos_estado';

    protected $fillable = ['maquina_id', 'legenda_id', 'ordem_producao_id', 'inicio', 'fim', 'referencia', 'observacao'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fim' => 'datetime'];
    }

    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class);
    }

    public function legenda(): BelongsTo
    {
        return $this->belongsTo(Legenda::class);
    }

    public function ordemProducao(): BelongsTo
    {
        return $this->belongsTo(OrdemProducao::class, 'ordem_producao_id');
    }

    /** Texto dentro da barra da Timeline: referência digitada, senão o número da OP, senão a legenda. */
    /** Texto dentro da barra da timeline: sempre o código da legenda (a OP fica embaixo do nome da máquina). */
    public function rotuloBarra(): string
    {
        return (string) $this->legenda?->codigo;
    }

    /** "2026-11-05T08:30" digitado no fuso local → UTC. */
    public static function deHoraLocal(?string $valor): ?CarbonImmutable
    {
        return $valor ? CarbonImmutable::parse($valor, config('oee.fuso_exibicao'))->utc() : null;
    }

    /** Data em UTC → valor do campo datetime-local no fuso local. */
    public static function paraHoraLocal(?CarbonInterface $valor): ?string
    {
        return $valor?->copy()->setTimezone(config('oee.fuso_exibicao'))->format('Y-m-d\TH:i');
    }

    /** Duração em minutos (até agora, se ainda estiver aberto). */
    public function duracaoMin(): float
    {
        return max(($this->fim ?? now())->getTimestamp() - $this->inicio->getTimestamp(), 0) / 60;
    }
}
