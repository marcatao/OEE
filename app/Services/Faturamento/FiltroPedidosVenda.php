<?php

namespace App\Services\Faturamento;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Filtro da tela Faturamento › Pedidos de venda.
 * - Mês (AAAA-MM): SEMPRE abre no mês atual (pedido do usuário em 26/11/2026), escolhido mês a mês; não vai nos
 *   filtros salvos. Faturados = faturamento (C6_DATFAT) no mês; em aberto = conforme "abertos".
 * - Abertos: mes_atrasados (padrão: entrega prevista no mês ou antes, e sem data) | mes (entrega no mês) | todos.
 * - Etapas: vazio = todas.
 * - Busca: número do pedido, cliente (código ou nome), produto (código ou descrição) ou pedido do cliente.
 * Pode vir da URL, de um filtro salvo pelo usuário (FiltroUsuario, tela "faturamento_pedidos") ou do padrão.
 */
final class FiltroPedidosVenda
{
    public const TELA = 'faturamento_pedidos';

    public const PARAMETROS = ['f', 'etapas', 'abertos', 'busca'];

    public const ABERTOS = [
        'mes_atrasados' => 'Entrega no mês e atrasados',
        'mes' => 'Só com entrega no mês',
        'todos' => 'Todos os em aberto',
    ];

    /** @param  list<string>  $etapas */
    public function __construct(
        public CarbonImmutable $mes,
        public array $etapas = [],
        public string $abertos = 'mes_atrasados',
        public string $busca = '',
    ) {}

    public static function fuso(): string
    {
        return (string) config('oee.fuso_exibicao', 'America/Sao_Paulo');
    }

    public static function padrao(): self
    {
        return new self(CarbonImmutable::now(self::fuso())->startOfMonth());
    }

    public static function daRequisicao(Request $request): self
    {
        return self::dos($request->all(), $request->query('mes'));
    }

    /** Filtro salvo: o mês é o da URL (ou o atual), nunca o do dia em que foi salvo. */
    public static function doSalvo(array $dados, mixed $mes = null): self
    {
        return self::dos($dados, $mes);
    }

    private static function dos(array $dados, mixed $mes): self
    {
        $filtro = self::padrao();
        if (is_string($mes) && preg_match('/^(\d{4})-(\d{2})$/', $mes, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $filtro->mes = CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, self::fuso());
        }
        $filtro->etapas = array_values(array_intersect(array_keys(EtapasPedidoVenda::ETAPAS), (array) ($dados['etapas'] ?? [])));
        $abertos = (string) ($dados['abertos'] ?? '');
        $filtro->abertos = isset(self::ABERTOS[$abertos]) ? $abertos : 'mes_atrasados';
        $filtro->busca = mb_substr(trim((string) ($dados['busca'] ?? '')), 0, 60);

        return $filtro;
    }

    /** Primeiro e último dia do mês (AAAAMMDD). @return array{0: string, 1: string} */
    public function dias(): array
    {
        return [$this->mes->format('Ymd'), $this->mes->endOfMonth()->format('Ymd')];
    }

    public function rotuloMes(): string
    {
        $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro',
            'novembro', 'dezembro'];

        return $meses[$this->mes->month - 1].' de '.$this->mes->year;
    }

    public function ehMesAtual(): bool
    {
        return $this->mes->format('Y-m') === CarbonImmutable::now(self::fuso())->format('Y-m');
    }

    /** O que gravar num filtro salvo (sem o mês). */
    public function paraSalvar(): array
    {
        return ['etapas' => $this->etapas, 'abertos' => $this->abertos, 'busca' => $this->busca];
    }

    /** Parâmetros de URL deste filtro (ex.: cartão que filtra a etapa, mês anterior). */
    public function parametros(array $trocar = []): array
    {
        return array_filter(array_merge(['f' => 1, 'mes' => $this->mes->format('Y-m')] + $this->paraSalvar(), $trocar),
            fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
