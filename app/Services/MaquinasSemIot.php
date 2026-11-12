<?php

namespace App\Services;

use App\Models\Maquina;
use Illuminate\Support\Collection;

/**
 * Máquina só fica ATIVA com pelo menos um canal IoT (dispositivo IoT ativo + canal). Aqui ficam as inativações
 * automáticas: ao desativar um dispositivo IoT e no comando maquinas:desativar-sem-iot.
 */
class MaquinasSemIot
{
    /** Máquinas ativas sem nenhum canal IoT ativo (opcional: só as que usam o dispositivo). @return Collection<int, Maquina> */
    public function encontrar(?int $dispositivoId = null): Collection
    {
        return Maquina::withoutGlobalScopes()->where('ativo', true)
            ->when($dispositivoId, fn ($q) => $q->where(fn ($w) => $w->where('iot1_dispositivo_id', $dispositivoId)
                ->orWhere('iot2_dispositivo_id', $dispositivoId)->orWhere('iot3_dispositivo_id', $dispositivoId)))
            ->orderBy('codigo')->get()
            ->reject(fn (Maquina $m) => $m->temCanalIotAtivo())->values();
    }

    /** Inativa essas máquinas. @return Collection<int, Maquina> as inativadas */
    public function inativar(?int $dispositivoId = null): Collection
    {
        $maquinas = $this->encontrar($dispositivoId);
        foreach ($maquinas as $m) {
            $m->update(['ativo' => false]);
        }

        return $maquinas;
    }
}
