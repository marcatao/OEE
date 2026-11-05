<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Período dos painéis, calculado no fuso local (FUSO_EXIBICAO) e convertido para UTC (como as datas do banco).
 */
final readonly class Periodo
{
    public const OPCOES = [
        'hoje' => 'Hoje',
        'ontem' => 'Ontem',
        '7dias' => 'Últimos 7 dias',
        '30dias' => 'Últimos 30 dias',
        'mes' => 'Este mês',
    ];

    public function __construct(
        public string $chave,
        public CarbonImmutable $inicio,
        public CarbonImmutable $fim,
    ) {}

    public static function de(?string $chave): self
    {
        $chave = array_key_exists((string) $chave, self::OPCOES) ? (string) $chave : 'hoje';
        $hoje = CarbonImmutable::now(config('oee.fuso_exibicao'))->startOfDay();

        [$inicio, $fim] = match ($chave) {
            'ontem' => [$hoje->subDay(), $hoje],
            '7dias' => [$hoje->subDays(6), $hoje->addDay()],
            '30dias' => [$hoje->subDays(29), $hoje->addDay()],
            'mes' => [$hoje->startOfMonth(), $hoje->addDay()],
            default => [$hoje, $hoje->addDay()],
        };

        return new self($chave, $inicio->utc(), $fim->utc());
    }

    public function rotulo(): string
    {
        return self::OPCOES[$this->chave];
    }
}
