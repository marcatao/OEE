<?php

namespace App\Services\Filtros;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Filtro da Produção por hora: o DIA (cada máquina mostra o turno dela nesse dia; padrão hoje) e plantas, linhas e
 * máquinas (vazio = todas; as mesmas máquinas da Timeline: ativas e com IoT cadastrado). Pode vir da URL, de um
 * filtro salvo (FiltroUsuario, tela "producao_hora") ou do padrão.
 */
final class FiltroProducaoHora
{
    public const TELA = 'producao_hora';

    /**
     * @param  list<int>  $plantas
     * @param  list<int>  $linhas
     * @param  list<int>  $maquinas
     */
    public function __construct(
        public CarbonImmutable $dia,
        public array $plantas = [],
        public array $linhas = [],
        public array $maquinas = [],
    ) {}

    public static function hoje(): CarbonImmutable
    {
        return CarbonImmutable::now(FiltroTimeline::fuso())->startOfDay();
    }

    public static function daRequisicao(Request $request): self
    {
        $dia = self::dia($request->input('dia')) ?? self::hoje();

        return new self($dia, self::ids($request->input('plantas')), self::ids($request->input('linhas')), self::ids($request->input('maquinas')));
    }

    /** De um filtro salvo: "periodo" = "hoje" (sempre o dia em que a tela abre) ou "fixo" (o dia gravado). */
    public static function doSalvo(array $dados): self
    {
        $dia = ($dados['periodo'] ?? 'hoje') === 'fixo' ? (self::dia($dados['dia'] ?? null) ?? self::hoje()) : self::hoje();

        return new self($dia, self::ids($dados['plantas'] ?? []), self::ids($dados['linhas'] ?? []), self::ids($dados['maquinas'] ?? []));
    }

    public function paraSalvar(string $periodo): array
    {
        return ['periodo' => $periodo === 'fixo' ? 'fixo' : 'hoje'] + ($periodo === 'fixo' ? ['dia' => $this->dia->format('Y-m-d')] : [])
            + ['plantas' => $this->plantas, 'linhas' => $this->linhas, 'maquinas' => $this->maquinas];
    }

    public function ehHoje(): bool
    {
        return $this->dia->isSameDay(self::hoje());
    }

    /** Máquinas ativas com IoT cadastrado que passam no filtro (as da Timeline). */
    public function maquinas(): Builder
    {
        return (new FiltroTimeline($this->dia, $this->dia, $this->plantas, $this->linhas, $this->maquinas))->maquinas();
    }

    private static function dia(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }
        $dia = CarbonImmutable::createFromFormat('!Y-m-d', $valor, FiltroTimeline::fuso());

        return $dia && $dia->format('Y-m-d') === $valor ? $dia : null;
    }

    /** @return list<int> */
    private static function ids(mixed $valor): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $valor), fn ($id) => $id > 0)));
    }
}
