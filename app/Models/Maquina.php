<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Maquina extends Model
{
    use Auditavel;
    use TemUnidade;

    protected $table = 'maquinas';

    /** Tipos de canal IoT: o que o valor do canal representa. */
    public const TIPOS_CANAL = ['producao' => 'Produção', 'refugo' => 'Refugo'];

    /** Números dos canais IoT da máquina (colunas iotN_id, iotN_canal, iotN_tipo). */
    public const CANAIS_IOT = [1, 2, 3];

    protected $fillable = ['unidade',
        'linha_id', 'codigo', 'nome', 'fabricante', 'modelo', 'ciclo_ideal_s', 'ativo',
        'centro_custo', 'micro_parada_s', 'macro_parada_s',
        // Canais IoT: dispositivo (FK para dispositivos_iot), canal e tipo. As colunas antigas iotN_id (texto livre)
        // ficam só para consulta: não são mais gravadas pelo portal.
        'iot1_dispositivo_id', 'iot1_canal', 'iot1_tipo', 'iot2_dispositivo_id', 'iot2_canal', 'iot2_tipo',
        'iot3_dispositivo_id', 'iot3_canal', 'iot3_tipo',
        // Situação atual (produzindo | parada) e OP atual: pelo cadastro ou pelo apontamento real mais recente.
        'situacao', 'situacao_desde', 'situacao_legenda_id', 'ordem_producao_id',
        // Calendário de dias úteis e jornada (dias da semana ISO "12345", HH:MM). Obrigatórios para ativar.
        'calendario_id', 'dias_semana', 'jornada_inicio', 'jornada_fim', 'horarios_semana',
    ];

    public const SITUACOES = ['produzindo' => 'Produzindo', 'parada' => 'Parada'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'ciclo_ideal_s' => 'float', 'micro_parada_s' => 'integer', 'macro_parada_s' => 'integer',
            'r_e_c_n_o_' => 'integer', 'sincronizado_em' => 'datetime', 'excluida_protheus_em' => 'datetime',
            'situacao_desde' => 'datetime'];
    }

    /** Unidades em que a máquina só fica ATIVA com calendário, dias de trabalho e canal IoT. */
    public const UNIDADES_COM_IOT_E_CALENDARIO = ['manutencao'];

    /** Esta máquina (ou a unidade informada) exige calendário, jornada e canal IoT para ficar ativa? */
    public function exigeIotECalendario(?string $unidade = null): bool
    {
        return in_array($unidade ?? $this->unidade ?? 'manutencao', self::UNIDADES_COM_IOT_E_CALENDARIO, true);
    }

    /**
     * Veio do Protheus (tabela SHB)? Então código, nome e centro de custo são do Protheus e não se editam no portal.
     */
    public function doProtheus(): bool
    {
        return $this->r_e_c_n_o_ !== null;
    }

    public function excluidaNoProtheus(): bool
    {
        return $this->d_e_l_e_t_ === '*';
    }

    /** Veio do Protheus e ainda não foi completada/ativada no portal. */
    public function aguardandoCadastro(): bool
    {
        return $this->doProtheus() && ! $this->ativo && ! $this->excluidaNoProtheus();
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
            $dispositivo = $this->{"iot{$n}Dispositivo"};
            $textoAntigo = (string) $this->{"iot{$n}_id"};
            if ($dispositivo || $textoAntigo !== '' || $this->{"iot{$n}_canal"}) {
                $canais[] = [
                    'numero' => $n,
                    'dispositivo' => $dispositivo,
                    // Descrição exibida: nome do dispositivo; sem dispositivo, o texto antigo (anterior ao cadastro).
                    'id' => $dispositivo ? $dispositivo->nome : $textoAntigo,
                    'antigo' => $dispositivo === null && $textoAntigo !== '',
                    'canal' => (string) $this->{"iot{$n}_canal"},
                    'tipo' => $this->{"iot{$n}_tipo"},
                ];
            }
        }

        return $canais;
    }

    /**
     * Canais IoT preenchidos (dispositivo + canal), pelos valores informados (ou os gravados).
     *
     * @return list<array{numero: int, dispositivo_id: int, canal: string}>
     */
    public function canaisConfigurados(?array $dados = null): array
    {
        $canais = [];
        foreach (self::CANAIS_IOT as $n) {
            // Campo que não veio nos dados (ex.: formulário sem os canais) vale o que está gravado.
            $dispositivo = $dados !== null && array_key_exists("iot{$n}_dispositivo_id", $dados) ? $dados["iot{$n}_dispositivo_id"] : $this->{"iot{$n}_dispositivo_id"};
            $canal = trim((string) ($dados !== null && array_key_exists("iot{$n}_canal", $dados) ? $dados["iot{$n}_canal"] : $this->{"iot{$n}_canal"}));
            if ($dispositivo && $canal !== '') {
                $canais[] = ['numero' => $n, 'dispositivo_id' => (int) $dispositivo, 'canal' => $canal];
            }
        }

        return $canais;
    }

    /** Com IoT cadastrado: pelo menos um canal com dispositivo e canal preenchidos (ex.: Timeline). */
    public function scopeComIot(Builder $q): Builder
    {
        return $q->where(function ($w) {
            foreach (self::CANAIS_IOT as $n) {
                $w->orWhere(fn ($c) => $c->whereNotNull("iot{$n}_dispositivo_id")
                    ->whereNotNull("iot{$n}_canal")->where("iot{$n}_canal", '<>', ''));
            }
        });
    }

    /** Com IoT ATIVO: pelo menos um canal com canal preenchido e dispositivo ativo (ex.: Dashboard por máquina). */
    public function scopeComIotAtivo(Builder $q): Builder
    {
        return $q->where(function ($w) {
            foreach (self::CANAIS_IOT as $n) {
                $w->orWhere(fn ($c) => $c->whereNotNull("iot{$n}_canal")->where("iot{$n}_canal", '<>', '')
                    ->whereIn("iot{$n}_dispositivo_id", DispositivoIot::withoutGlobalScopes()->where('ativo', true)->select('id')));
            }
        });
    }

    /** Tem pelo menos um canal IoT com dispositivo ATIVO? (condição para a máquina ficar ativa) */
    public function temCanalIotAtivo(?array $dados = null): bool
    {
        $ids = array_column($this->canaisConfigurados($dados), 'dispositivo_id');

        return $ids && DispositivoIot::withoutGlobalScopes()->whereIn('id', $ids)->where('ativo', true)->exists();
    }

    public function iot1Dispositivo(): BelongsTo
    {
        return $this->belongsTo(DispositivoIot::class, 'iot1_dispositivo_id');
    }

    public function iot2Dispositivo(): BelongsTo
    {
        return $this->belongsTo(DispositivoIot::class, 'iot2_dispositivo_id');
    }

    public function iot3Dispositivo(): BelongsTo
    {
        return $this->belongsTo(DispositivoIot::class, 'iot3_dispositivo_id');
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

    /** OP atual da máquina. */
    public function ordemProducao(): BelongsTo
    {
        return $this->belongsTo(OrdemProducao::class, 'ordem_producao_id');
    }

    /** Legenda do apontamento que deu a situação atual (vazio = situação informada no cadastro). */
    public function situacaoLegenda(): BelongsTo
    {
        return $this->belongsTo(Legenda::class, 'situacao_legenda_id');
    }

    public function rotuloSituacao(): string
    {
        return self::SITUACOES[$this->situacao] ?? 'Sem informação';
    }

    /** Calendário de dias úteis da máquina (Administração › Calendários). */
    public function calendario(): BelongsTo
    {
        return $this->belongsTo(Calendario::class);
    }
}
