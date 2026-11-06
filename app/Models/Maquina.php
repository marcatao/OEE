<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Maquina extends Model
{
    use Auditavel;

    protected $table = 'maquinas';

    /** Tipos de canal IoT: o que o valor do canal representa. */
    public const TIPOS_CANAL = ['producao' => 'Produção', 'refugo' => 'Refugo'];

    /** Números dos canais IoT da máquina (colunas iotN_id, iotN_canal, iotN_tipo). */
    public const CANAIS_IOT = [1, 2, 3];

    protected $fillable = [
        'linha_id', 'codigo', 'nome', 'fabricante', 'modelo', 'ciclo_ideal_s', 'ativo',
        'centro_custo', 'micro_parada_s', 'macro_parada_s',
        'iot1_id', 'iot1_canal', 'iot1_tipo', 'iot2_id', 'iot2_canal', 'iot2_tipo', 'iot3_id', 'iot3_canal', 'iot3_tipo',
    ];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'ciclo_ideal_s' => 'float', 'micro_parada_s' => 'integer', 'macro_parada_s' => 'integer'];
    }

    /** Centro de custo pelo código (a máquina guarda só o código, como no Protheus). */
    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo', 'ctt_custo');
    }

    /**
     * Canais IoT preenchidos.
     *
     * @return list<array{numero: int, id: string, canal: string, tipo: ?string}>
     */
    public function canaisIot(): array
    {
        $canais = [];
        foreach (self::CANAIS_IOT as $n) {
            if ($this->{"iot{$n}_id"} || $this->{"iot{$n}_canal"}) {
                $canais[] = ['numero' => $n, 'id' => (string) $this->{"iot{$n}_id"}, 'canal' => (string) $this->{"iot{$n}_canal"}, 'tipo' => $this->{"iot{$n}_tipo"}];
            }
        }

        return $canais;
    }

    public function linha(): BelongsTo
    {
        return $this->belongsTo(Linha::class);
    }

    public function dispositivos(): HasMany
    {
        return $this->hasMany(DispositivoIot::class);
    }

    /** "Linha 1 · Planta Matriz" para listas e cabeçalhos. */
    public function localizacao(): string
    {
        return collect([$this->linha?->nome, $this->linha?->planta?->nome])->filter()->join(' · ');
    }
}
