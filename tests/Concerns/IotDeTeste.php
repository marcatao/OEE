<?php

namespace Tests\Concerns;

use App\Models\DispositivoIot;

/** Canal IoT (dispositivo + canal) para a máquina aparecer na Timeline nos testes (só máquinas com IoT aparecem). */
trait IotDeTeste
{
    private int $iotDeTeste = 0;

    /** Campos do canal 1 com um dispositivo novo (dispositivo + canal só em uma máquina). */
    protected function iotGravado(): array
    {
        $n = ++$this->iotDeTeste;
        $d = DispositivoIot::withoutGlobalScopes()->create(['codigo' => "IOT-T{$n}", 'nome' => "Sensor de teste {$n}",
            'tipo' => 'sensor', 'protocolo' => 'mqtt', 'ativo' => true]);

        return ['iot1_dispositivo_id' => $d->id, 'iot1_canal' => 'chd1_value'];
    }
}
