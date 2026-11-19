<?php

namespace App\Services\FaltaProdutos;

use App\Models\FaltaProduto;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Filtro da tela Produção › Falta de produtos.
 * - De/Até: data da solicitação (dia local); vazio = sem limite de data.
 * - Status (legendas da ZB5): PADRÃO = tudo que não está encerrado (pendente e atendida parcialmente). Com o
 *   formulário enviado (campo "f"), nenhum marcado = todos.
 * - Produção (em produção, direcionada, com OP, sem OP): vazio = todas.
 * - Busca: produto (código ou descrição), documento, pedido ou solicitante.
 * Pode vir da URL, de um filtro salvo pelo usuário (FiltroUsuario, tela "falta_produtos") ou do padrão.
 */
final class FiltroFaltaProdutos
{
    public const TELA = 'falta_produtos';

    /** Parâmetros que, na URL, dizem que o usuário escolheu um filtro (senão vale o salvo como padrão). */
    public const PARAMETROS = ['f', 'de', 'ate', 'status', 'producao', 'busca'];

    /**
     * @param  list<string>  $status
     * @param  list<string>  $producao
     */
    public function __construct(
        public ?CarbonImmutable $de = null,
        public ?CarbonImmutable $ate = null,
        public array $status = FaltaProduto::EM_ABERTO,
        public array $producao = [],
        public string $busca = '',
        public ?string $aviso = null,
    ) {}

    public static function fuso(): string
    {
        return (string) config('oee.fuso_exibicao');
    }

    public static function daRequisicao(Request $request): self
    {
        return self::dos($request->all(), $request->has('f'));
    }

    public static function doSalvo(array $dados): self
    {
        return self::dos($dados, true);
    }

    /** @param  bool  $enviado  formulário enviado: status vazio = todos (senão vale o padrão: em aberto) */
    private static function dos(array $dados, bool $enviado): self
    {
        $filtro = new self;
        $filtro->de = self::dia($dados['de'] ?? null);
        $filtro->ate = self::dia($dados['ate'] ?? null);
        if ($filtro->de && $filtro->ate && $filtro->ate->lt($filtro->de)) {
            [$filtro->de, $filtro->ate] = [$filtro->ate, $filtro->de];
            $filtro->aviso = 'O "Até" era antes do "De": as datas foram trocadas.';
        }
        if ($enviado || isset($dados['status'])) {
            $filtro->status = array_values(array_intersect(array_keys(FaltaProduto::STATUS), (array) ($dados['status'] ?? [])));
        }
        $filtro->producao = array_values(array_intersect(array_keys(FaltaProdutos::PRODUCAO), (array) ($dados['producao'] ?? [])));
        $filtro->busca = mb_substr(trim((string) ($dados['busca'] ?? '')), 0, 60);

        return $filtro;
    }

    /** Status escolhidos; vazio = todos. */
    public function statusEfetivos(): array
    {
        return $this->status ?: array_keys(FaltaProduto::STATUS);
    }

    /** Início do "De" (00:00 local) em UTC; null = sem limite. */
    public function inicioUtc(): ?CarbonImmutable
    {
        return $this->de?->startOfDay()->utc();
    }

    /** Fim exclusivo do "Até" (00:00 do dia seguinte, local) em UTC; null = sem limite. */
    public function fimUtc(): ?CarbonImmutable
    {
        return $this->ate?->addDay()->startOfDay()->utc();
    }

    public function rotuloPeriodo(): string
    {
        return match (true) {
            $this->de && $this->ate => $this->de->format('d/m/Y').' a '.$this->ate->format('d/m/Y'),
            (bool) $this->de => 'a partir de '.$this->de->format('d/m/Y'),
            (bool) $this->ate => 'até '.$this->ate->format('d/m/Y'),
            default => 'sem limite de data',
        };
    }

    /** O que gravar num filtro salvo. */
    public function paraSalvar(): array
    {
        return ['de' => $this->de?->format('Y-m-d'), 'ate' => $this->ate?->format('Y-m-d'), 'status' => $this->status,
            'producao' => $this->producao, 'busca' => $this->busca];
    }

    /** Parâmetros de URL deste filtro (ex.: link de um cartão que muda só a produção). */
    public function parametros(array $trocar = []): array
    {
        return array_filter(array_merge(['f' => 1] + $this->paraSalvar(), $trocar), fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private static function dia(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }
        $d = CarbonImmutable::createFromFormat('!Y-m-d', $valor, self::fuso());

        return $d ?: null;
    }
}
