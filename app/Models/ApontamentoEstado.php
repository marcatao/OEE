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

    /** iot_dispositivo_id e iot_canal ficam FORA de propósito: só o Integrador MQTT grava a origem IoT. */
    protected $fillable = ['maquina_id', 'legenda_id', 'ordem_producao_id', 'inicio', 'fim', 'referencia', 'observacao'];

    /** Marca que o Integrador MQTT põe no início da observação dos apontamentos automáticos. */
    public const MARCA_IOT = '[IoT]';

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

    /** Dispositivo IoT que gerou o apontamento automático (gravado pelo integrador). Sem o filtro de unidade: quem
     * enxerga a máquina enxerga de onde veio o apontamento dela. */
    public function iotDispositivo(): BelongsTo
    {
        return $this->belongsTo(DispositivoIot::class, 'iot_dispositivo_id')->withoutGlobalScopes();
    }

    /** Apontamento automático do Integrador MQTT: observação com a marca "[IoT]" ou dispositivo de origem gravado. */
    public function automaticoIot(): bool
    {
        return str_starts_with(ltrim((string) $this->observacao), self::MARCA_IOT) || $this->iot_dispositivo_id !== null;
    }

    /** Dispositivo e canal de origem ("TESTE_ALUMBRA / chd1_value"); vazio se o integrador não informou. */
    public function origemIot(): string
    {
        $dispositivo = $this->iot_dispositivo_id !== null
            ? ($this->iotDispositivo?->codigo ?? 'dispositivo #'.$this->iot_dispositivo_id)
            : null;

        return implode(' / ', array_filter([$dispositivo, $this->iot_canal]));
    }

    /** "Automático (IoT: TESTE_ALUMBRA / chd1_value)" ou "Automático (IoT)"; null se for manual. */
    public function textoOrigem(): ?string
    {
        if (! $this->automaticoIot()) {
            return null;
        }
        $origem = $this->origemIot();

        return $origem === '' ? 'Automático (IoT)' : "Automático (IoT: {$origem})";
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
