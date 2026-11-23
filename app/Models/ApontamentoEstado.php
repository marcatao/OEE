<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Services\HorarioApontamento;
use App\Services\Manutencao\SsAutomatica;
use App\Services\PecasProduzidas;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    /**
     * Folga (dias) para buscar por período: um apontamento ENCERRADO não passa de um dia (HorarioApontamento), então
     * só os que começaram até essa folga antes do período podem tocá-lo; os EM ABERTO entram sempre. Assim a busca usa
     * o índice (maquina_id, inicio) sem ler o histórico inteiro da máquina. A folga cobre registros antigos maiores.
     */
    public const DIAS_FOLGA_PERIODO = 7;

    /** Apontamentos que tocam o período [inicio, fim) (encerrados que terminam depois do início ou em aberto). */
    public function scopeTocando($query, CarbonInterface $inicio, CarbonInterface $fim)
    {
        $desde = CarbonImmutable::instance($inicio)->subDays(self::DIAS_FOLGA_PERIODO);

        return $query->where('inicio', '<', $fim)
            ->where(fn ($q) => $q->whereNull('fim')
                ->orWhere(fn ($e) => $e->where('fim', '>', $inicio)->where('inicio', '>=', $desde)));
    }

    /**
     * Colunas do apontamento MAIS RECENTE (por início, depois id) de cada máquina, numa consulta só: uma busca TOP 1
     * pelo índice (maquina_id, inicio) por máquina, sem varrer o histórico.
     *
     * @param  iterable<int|string>  $maquinaIds
     * @param  list<string>  $colunas
     * @return array<int, array<string, mixed>> máquina => [coluna => valor]
     */
    public static function ultimosPorMaquina(iterable $maquinaIds, array $colunas): array
    {
        $ids = collect($maquinaIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $resultado = [];
        foreach ($ids->chunk(1000) as $bloco) {
            $consulta = DB::table('maquinas')->whereIn('id', $bloco->all())->select('id');
            foreach ($colunas as $coluna) {
                $consulta->selectSub(DB::table('apontamentos_estado')->select($coluna)
                    ->whereColumn('apontamentos_estado.maquina_id', 'maquinas.id')
                    ->orderByDesc('inicio')->orderByDesc('id')->limit(1), $coluna);
            }
            foreach ($consulta->get() as $linha) {
                $resultado[(int) $linha->id] = collect($colunas)->mapWithKeys(fn ($c) => [$c => $linha->{$c}])->all();
            }
        }

        return $resultado;
    }

    /**
     * SS desta parada para levar adiante (continuação da virada do dia, troca de OP): a vinculada ou a que este
     * apontamento abriu. Assim a nova parte não abre outra SS e a Timeline/app seguem mostrando o chamado.
     */
    public function chamadoParaContinuar(): ?int
    {
        $id = $this->solicitacao_vinculada_id
            ?? DB::table('solicitacoes_servico')->where('apontamento_estado_id', $this->id)->orderByDesc('id')->value('id');

        return $id ? (int) $id : null;
    }

    /** Marca que o Integrador MQTT põe no início da observação dos apontamentos automáticos. */
    public const MARCA_IOT = '[IoT]';

    /**
     * Legenda que "abre SS": ao gravar o apontamento com ela (novo ou trocando a legenda), abre a SS automática
     * (App\Services\Manutencao\SsAutomatica). Falha ali não impede o apontamento.
     */
    protected static function booted(): void
    {
        // Em aberto (sem fim) só o ÚLTIMO apontamento da máquina (o integrador grava direto no banco e faz a mesma regra).
        static::saving(fn (ApontamentoEstado $ap) => HorarioApontamento::exigirUltimoSeAberto($ap));

        // Peças (ciclos do IoT × cavidades): trocar a OP ou a legenda recalcula o apontamento e a quantidade real das OPs.
        static::saved(function (ApontamentoEstado $ap) {
            if ($ap->ciclos === null || ! $ap->wasChanged(['ordem_producao_id', 'legenda_id'])) {
                return;
            }
            try {
                $pecas = app(PecasProduzidas::class);
                // No "saved" o original ainda é o de antes (aplicar() grava de novo e o sincroniza): guarda a OP antiga.
                $opAntiga = $ap->wasChanged('ordem_producao_id') ? (int) ($ap->getOriginal('ordem_producao_id') ?? 0) : 0;
                if ($opAntiga !== 0 || $ap->wasChanged('ordem_producao_id')) {
                    $ap->cavidades = null; // outra OP, outro molde
                }
                $pecas->recalcularOp($pecas->aplicar($ap));
                $pecas->recalcularOp($opAntiga ?: null);
            } catch (\Throwable $e) {
                report($e);
            }
        });
        static::deleted(function (ApontamentoEstado $ap) {
            if ($ap->pecas !== null && $ap->ordem_producao_id) {
                app(PecasProduzidas::class)->recalcularOp((int) $ap->ordem_producao_id);
            }
        });
        static::saved(function (ApontamentoEstado $ap) {
            if (! $ap->wasRecentlyCreated && ! $ap->wasChanged('legenda_id')) {
                return;
            }
            try {
                app(SsAutomatica::class)->aoApontar($ap);
            } catch (\Throwable $e) {
                report($e);
                SsAutomatica::registrar($ap->id, 'SS automática NÃO aberta por um erro ao gravar: '.Str::limit($e->getMessage(), 200));
            }
        });
    }

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fim' => 'datetime', 'ciclos' => 'integer', 'cavidades' => 'integer', 'pecas' => 'integer',
            'ciclo_medio_ms' => 'integer'];
    }

    /** Ciclo médio em segundos com fração: ciclo_medio_ms (Integrador MQTT, 23/11/2026) ou o antigo ciclo_medio (s). */
    public static function cicloEmSegundos(mixed $cicloMs, mixed $cicloS): ?float
    {
        if ($cicloMs !== null && $cicloMs !== '') {
            return (int) $cicloMs / 1000;
        }

        return $cicloS === null || $cicloS === '' ? null : (float) $cicloS;
    }

    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class);
    }

    /** Legenda do apontamento, de qualquer unidade (ex.: a 98 do IoT): quem vê a máquina vê o apontamento inteiro. */
    public function legenda(): BelongsTo
    {
        return $this->belongsTo(Legenda::class)->withoutGlobalScopes();
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
