<?php

namespace App\Services;

use App\Models\DispositivoIot;
use App\Models\Maquina;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ciclo a ciclo da máquina, lido DIRETO da tabela de leituras do IoT (`MQTT.dbo.IOT001`, gravada pelo Integrador
 * MQTT; pedido do usuário em 26/11/2026, Dashboard por máquina). Fonte: o 1º canal de PRODUÇÃO da máquina (dispositivo
 * + coluna chdN_value), o mesmo que o integrador usa para apontar e para o ciclo médio.
 *
 * Cada vez que o valor do canal MUDA é um registro: intervalo = tempo desde a mudança anterior (ts_unix, segundos do
 * aparelho) e ciclo por peça = intervalo ÷ quantas peças o contador subiu (dá os milissegundos das máquinas com mais de
 * uma peça por leitura: 250 em 10 s = 0,040 s). Contador menor que o anterior = zerado: conta o valor novo.
 * Classificação pelo intervalo, como o integrador (`limites_efetivos`): micro efetiva ≥ 1,5 × o passo das leituras;
 * intervalo ≥ macro = PARADA GRANDE (vermelha no gráfico, cortada para não esconder os ciclos normais).
 */
class CiclosIot
{
    /** Leituras anteriores ao dia lidas para saber o intervalo da 1ª mudança do dia. */
    public const HORAS_ANTES = 2;

    /** Macro parada usada quando o cadastro não tem (segundos). */
    public const MACRO_PADRAO = 300.0;

    /** @return array{dispositivo: DispositivoIot, canal: string}|null 1º canal de produção com dispositivo e canal válidos */
    public function origem(Maquina $maquina): ?array
    {
        foreach ($maquina->canaisIot() as $c) {
            $canal = (string) $c['canal'];
            if ($c['dispositivo'] && in_array($canal, config('oee.iot_canais'), true) && in_array($c['tipo'] ?? '', ['', null, 'producao'], true)) {
                return ['dispositivo' => $c['dispositivo'], 'canal' => $canal];
            }
        }

        return null;
    }

    /**
     * Ciclos de um dia (local) da máquina.
     *
     * @return array{origem: array|null, erro: string|null, dia: CarbonImmutable}&array<string, mixed>
     */
    public function doDia(Maquina $maquina, CarbonImmutable $diaLocal): array
    {
        $diaLocal = $diaLocal->startOfDay();
        $inicio = $diaLocal->getTimestamp();
        $fim = $diaLocal->addDay()->getTimestamp();
        $origem = $this->origem($maquina);
        $base = ['origem' => $origem, 'erro' => null, 'dia' => $diaLocal, 'tabela' => self::tabela()];
        if ($origem === null) {
            return ['erro' => 'A máquina não tem canal de produção com dispositivo IoT no cadastro.'] + $base + self::calcular([], $inicio, $fim, null, null);
        }
        try {
            $leituras = DB::connection(config('oee.iot_leituras.conexao'))->table(self::tabela())
                ->where('device_id', (string) $origem['dispositivo']->codigo)
                ->where('ts_unix', '>=', $inicio - self::HORAS_ANTES * 3600)->where('ts_unix', '<', $fim)
                ->whereNotNull($origem['canal'])
                ->orderBy('ts_unix')->orderBy('id')
                ->get(['ts_unix', $origem['canal'].' as valor'])
                ->map(fn ($l) => [(int) $l->ts_unix, (float) $l->valor])->all();
        } catch (Throwable $e) {
            report($e);

            return ['erro' => 'Não foi possível ler a tabela '.self::tabela().' do IoT. O login do portal no SQL Server precisa '
                .'de permissão de leitura (SELECT) nela (script em database/scripts/permissao_leitura_iot001.sql).'] + $base + self::calcular([], $inicio, $fim, null, null);
        }

        return $base + self::calcular($leituras, $inicio, $fim, $maquina->microParadaSegundos(), $maquina->macroParadaSegundos());
    }

    /**
     * Dados compactos do gráfico (public/js/componentes.js): [ts, contador, peças, intervalo, ciclo por peça, nível
     * 0 normal | 1 micro | 2 parada grande].
     *
     * @param  list<array<string, mixed>>  $ciclos
     * @return list<array<int, mixed>>
     */
    public static function paraGrafico(array $ciclos): array
    {
        $nivel = ['normal' => 0, 'micro' => 1, 'parada' => 2];

        return array_map(fn ($c) => [$c['ts'], $c['valor'], $c['pecas'], $c['intervalo'], $c['por_peca'], $nivel[$c['nivel']]], $ciclos);
    }

    public static function tabela(): string
    {
        return (string) config('oee.iot_leituras.tabela');
    }

    /**
     * Regra pura (testada): das leituras `[ts_unix, valor]` em ordem, os ciclos com a mudança dentro de [inicio, fim).
     *
     * @param  list<array{0: int, 1: float}>  $leituras
     * @return array{ciclos: list<array{ts: int, valor: float, pecas: int, intervalo: int|null, por_peca: float|null, nivel: string}>, resumo: array<string, mixed>, limites: array<string, float|bool>}
     */
    public static function calcular(array $leituras, int $inicio, int $fim, ?float $micro, ?float $macro): array
    {
        // Passo típico das leituras do aparelho (mediana) e limites efetivos, como o integrador.
        $difs = [];
        for ($i = 1, $n = count($leituras); $i < $n; $i++) {
            if (($d = $leituras[$i][0] - $leituras[$i - 1][0]) > 0) {
                $difs[] = $d;
            }
        }
        sort($difs);
        $passo = count($difs) < 2 ? 10.0 : (count($difs) % 2 ? (float) $difs[intdiv(count($difs), 2)] : ($difs[count($difs) / 2 - 1] + $difs[count($difs) / 2]) / 2);
        $microEf = max((float) ($micro ?? 0), 1.5 * $passo);
        $macroEf = max((float) ($macro ?? self::MACRO_PADRAO), $microEf + 1.0);

        $ciclos = [];
        $anterior = null;
        $ultimaMudanca = null;
        foreach ($leituras as [$ts, $valor]) {
            if ($anterior !== null && abs($valor - $anterior) > 1e-9) {
                $pecas = $valor > $anterior ? (int) round($valor - $anterior) : (int) round($valor); // zerado: conta o novo
                $intervalo = $ultimaMudanca === null ? null : $ts - $ultimaMudanca;
                if ($ts >= $inicio && $ts < $fim) {
                    $ciclos[] = [
                        'ts' => $ts, 'valor' => $valor, 'pecas' => $pecas, 'intervalo' => $intervalo,
                        'por_peca' => $intervalo === null || $pecas <= 0 ? null : round($intervalo / $pecas, 3),
                        'nivel' => $intervalo === null ? 'normal' : ($intervalo >= $macroEf ? 'parada' : ($intervalo >= $microEf ? 'micro' : 'normal')),
                        'zerado' => $valor < $anterior,
                    ];
                }
                $ultimaMudanca = $ts;
            }
            $anterior = $valor;
        }

        $normais = array_values(array_filter(array_column(array_filter($ciclos, fn ($c) => $c['nivel'] === 'normal'), 'por_peca'), fn ($v) => $v !== null));
        sort($normais);
        $paradas = array_filter($ciclos, fn ($c) => $c['nivel'] === 'parada');
        $pecas = array_sum(array_column($ciclos, 'pecas'));
        $intervalosNormais = array_sum(array_map(fn ($c) => $c['nivel'] === 'normal' ? (int) $c['intervalo'] : 0, $ciclos));
        $pecasNormais = array_sum(array_map(fn ($c) => $c['nivel'] === 'normal' && $c['intervalo'] !== null ? $c['pecas'] : 0, $ciclos));

        return [
            'ciclos' => $ciclos,
            'resumo' => [
                'mudancas' => count($ciclos),
                'pecas' => $pecas,
                // Ciclo médio dos trechos normais = tempo ÷ peças (o mesmo critério do ciclo médio do integrador).
                'ciclo_medio' => $pecasNormais > 0 ? round($intervalosNormais / $pecasNormais, 3) : null,
                'ciclo_mediano' => $normais ? $normais[intdiv(count($normais), 2)] : null,
                'ciclo_minimo' => $normais ? $normais[0] : null,
                'ciclo_maximo' => $normais ? end($normais) : null,
                'micro' => count(array_filter($ciclos, fn ($c) => $c['nivel'] === 'micro')),
                'paradas' => count($paradas),
                'tempo_paradas' => array_sum(array_column($paradas, 'intervalo')),
                'maior_parada' => $paradas ? max(array_column($paradas, 'intervalo')) : null,
                'leituras' => count(array_filter($leituras, fn ($l) => $l[0] >= $inicio && $l[0] < $fim)),
            ],
            'limites' => ['passo' => $passo, 'micro' => $microEf, 'macro' => $macroEf, 'macro_padrao' => $macro === null],
        ];
    }
}
