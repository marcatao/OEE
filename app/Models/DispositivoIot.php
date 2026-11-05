<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dispositivo IoT (sensor, CLP, gateway, contador) que envia os dados de uma máquina.
 */
class DispositivoIot extends Model
{
    use Auditavel;

    public const TIPOS = [
        'sensor' => 'Sensor',
        'clp' => 'CLP',
        'gateway' => 'Gateway',
        'contador' => 'Contador de peças',
        'outro' => 'Outro',
    ];

    public const PROTOCOLOS = [
        'mqtt' => 'MQTT',
        'opcua' => 'OPC UA',
        'modbus_tcp' => 'Modbus TCP',
        'http' => 'HTTP / REST',
        'outro' => 'Outro',
    ];

    protected $table = 'dispositivos_iot';

    protected $fillable = ['codigo', 'nome', 'tipo', 'protocolo', 'endereco', 'porta', 'topico', 'maquina_id', 'observacao', 'ativo'];

    protected $attributes = ['ativo' => true];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'porta' => 'integer', 'ultimo_sinal_em' => 'datetime'];
    }

    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class);
    }

    /** online | sem_sinal | nunca | inativo */
    public function situacao(): string
    {
        if (! $this->ativo) {
            return 'inativo';
        }
        if ($this->ultimo_sinal_em === null) {
            return 'nunca';
        }

        return $this->ultimo_sinal_em->gte(now()->subMinutes(config('oee.iot_minutos_sem_sinal', 5))) ? 'online' : 'sem_sinal';
    }

    public function rotuloSituacao(): string
    {
        return match ($this->situacao()) {
            'online' => 'Online',
            'sem_sinal' => 'Sem sinal',
            'nunca' => 'Nunca conectou',
            default => 'Inativo',
        };
    }

    public function rotuloTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function rotuloProtocolo(): string
    {
        return self::PROTOCOLOS[$this->protocolo] ?? $this->protocolo;
    }
}
