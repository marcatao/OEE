<?php

namespace App\Services\Filtros;

use App\Models\Linha;
use App\Models\Maquina;
use App\Models\Planta;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Filtro da Timeline: período "De/Até" (data e hora, no fuso local) e plantas, linhas e máquinas (vazio = todas).
 *
 * Padrão: HOJE, de 01:00 até 23:59 (padrão comum em sistemas OEE). O "Até" vale o minuto inteiro: 23:59 vai até
 * 23:59:59. Pode vir da URL, de um filtro salvo pelo usuário (FiltroUsuario) ou do padrão.
 */
final class FiltroTimeline
{
    public const TELA = 'timeline';

    public const HORA_DE_PADRAO = '01:00';

    public const HORA_ATE_PADRAO = '23:59';

    /** Maior período aceito (dias), para a tela não ficar lenta. */
    public const MAX_DIAS = 31;

    /**
     * @param  list<int>  $plantas
     * @param  list<int>  $linhas
     * @param  list<int>  $maquinas
     */
    public function __construct(
        public CarbonImmutable $de,
        public CarbonImmutable $ate,
        public array $plantas = [],
        public array $linhas = [],
        public array $maquinas = [],
        public ?string $aviso = null,
    ) {}

    public static function fuso(): string
    {
        return (string) config('oee.fuso_exibicao');
    }

    /** Hoje, 01:00 até 23:59. */
    public static function padrao(): self
    {
        [$de, $ate] = self::periodoDoDia(CarbonImmutable::now(self::fuso()), self::HORA_DE_PADRAO, self::HORA_ATE_PADRAO);

        return new self($de, $ate);
    }

    /** Período de um dia a partir das horas (se "até" for antes de "de", termina no dia seguinte). */
    public static function periodoDoDia(CarbonImmutable $dia, string $horaDe, string $horaAte): array
    {
        $de = $dia->setTimeFromTimeString($horaDe)->startOfMinute();
        $ate = $dia->setTimeFromTimeString($horaAte)->startOfMinute();
        if ($ate->lte($de)) {
            $ate = $ate->addDay();
        }

        return [$de, $ate];
    }

    public static function daRequisicao(Request $request): self
    {
        $filtro = self::padrao();
        $fuso = self::fuso();

        // Compatível com links antigos (?data=AAAA-MM-DD): o dia inteiro no padrão 01:00–23:59.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('data'))) {
            [$filtro->de, $filtro->ate] = self::periodoDoDia(CarbonImmutable::createFromFormat('!Y-m-d', $request->input('data'), $fuso),
                self::HORA_DE_PADRAO, self::HORA_ATE_PADRAO);
        }

        $de = self::dataHora($request->input('de'));
        $ate = self::dataHora($request->input('ate'));
        if ($de && $ate) {
            if ($ate->lte($de)) {
                $filtro->aviso = 'O "Até" precisa ser depois do "De". Mostrando o período padrão.';
            } elseif ($de->diffInDays($ate) > self::MAX_DIAS) {
                $filtro->aviso = 'Período maior que '.self::MAX_DIAS.' dias. Mostrando o período padrão.';
            } else {
                [$filtro->de, $filtro->ate] = [$de, $ate];
            }
        }

        $filtro->plantas = self::ids($request->input('plantas'));
        $filtro->linhas = self::ids($request->input('linhas'));
        $filtro->maquinas = self::ids($request->input('maquinas'));

        return $filtro;
    }

    /**
     * De um filtro salvo. "periodo" = "hoje" (aplica as horas no dia atual) ou "fixo" (as datas gravadas).
     */
    public static function doSalvo(array $dados): self
    {
        $filtro = self::padrao();
        if (($dados['periodo'] ?? 'hoje') === 'fixo') {
            $de = self::dataHora($dados['de'] ?? null);
            $ate = self::dataHora($dados['ate'] ?? null);
            if ($de && $ate && $ate->gt($de)) {
                [$filtro->de, $filtro->ate] = [$de, $ate];
            }
        } elseif (isset($dados['hora_de'], $dados['hora_ate'])
            && preg_match('/^\d{2}:\d{2}$/', $dados['hora_de']) && preg_match('/^\d{2}:\d{2}$/', $dados['hora_ate'])) {
            [$filtro->de, $filtro->ate] = self::periodoDoDia(CarbonImmutable::now(self::fuso()), $dados['hora_de'], $dados['hora_ate']);
        }

        $filtro->plantas = self::ids($dados['plantas'] ?? []);
        $filtro->linhas = self::ids($dados['linhas'] ?? []);
        $filtro->maquinas = self::ids($dados['maquinas'] ?? []);

        return $filtro;
    }

    /** O que é gravado em filtros_usuarios.filtros. */
    public function paraSalvar(string $periodo): array
    {
        return ($periodo === 'fixo'
            ? ['periodo' => 'fixo', 'de' => $this->de->format('Y-m-d\TH:i'), 'ate' => $this->ate->format('Y-m-d\TH:i')]
            : ['periodo' => 'hoje', 'hora_de' => $this->de->format('H:i'), 'hora_ate' => $this->ate->format('H:i')])
            + ['plantas' => $this->plantas, 'linhas' => $this->linhas, 'maquinas' => $this->maquinas];
    }

    /** Parâmetros de URL deste filtro. */
    public function parametros(): array
    {
        return array_filter([
            'de' => $this->de->format('Y-m-d\TH:i'),
            'ate' => $this->ate->format('Y-m-d\TH:i'),
            'plantas' => $this->plantas,
            'linhas' => $this->linhas,
            'maquinas' => $this->maquinas,
        ]);
    }

    /** Fim da janela (exclusivo): o "Até" vale o minuto inteiro. */
    public function fimExclusivo(): CarbonImmutable
    {
        return $this->ate->addMinute();
    }

    /** Máquinas ativas que passam no filtro (plantas, linhas e máquinas se somam: precisa passar em todos). */
    public function maquinas(): Builder
    {
        // Só máquinas ativas e com IoT cadastrado (dispositivo + canal).
        return Maquina::with('linha.planta')->where('ativo', true)->comIot()
            ->when($this->maquinas, fn ($q) => $q->whereIn('id', $this->maquinas))
            ->when($this->linhas, fn ($q) => $q->whereIn('linha_id', $this->linhas))
            ->when($this->plantas, fn ($q) => $q->whereIn('linha_id', Linha::whereIn('planta_id', $this->plantas)->select('id')))
            ->orderBy('nome');
    }

    /** Opções dos filtros (só cadastros ativos; máquinas só com IoT cadastrado). */
    public static function opcoes(): array
    {
        return [
            'plantas' => Planta::where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
            'linhas' => Linha::with('planta:id,nome')->where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'planta_id']),
            'maquinas' => Maquina::with('linha:id,planta_id')->where('ativo', true)->comIot()->orderBy('nome')->get(['id', 'nome', 'codigo', 'linha_id']),
        ];
    }

    private static function dataHora(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $valor)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d\TH:i', $valor, self::fuso()) ?: null;
    }

    /** @return list<int> */
    private static function ids(mixed $valor): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $valor), fn ($id) => $id > 0)));
    }
}
