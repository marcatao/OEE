<?php

namespace App\Services\Protheus;

use App\Models\AgendaSincronizacao;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Periodicidade da sincronização de cada tabela do Protheus: "a cada HH:MM, a partir das HH:MM" (horário local).
 *
 * Regra dos horários: o dia recomeça no "a partir das" e a tabela roda nele e a cada intervalo, enquanto couber um
 * intervalo inteiro antes do recomeço do dia seguinte (nunca roda com menos que o intervalo entre duas execuções).
 * Assim: a cada 01:00 a partir das 00:00 = 24x ao dia (00:00, 01:00 … 23:00); a cada 23:59 (ou 24:00) = 1x ao dia,
 * às 00:00; a cada 07:00 a partir das 06:00 = 06:00, 13:00 e 20:00 (às 03:00 não, faltariam só 3 h para as 06:00).
 *
 * O comando protheus:sincronizar-agendadas roda a cada minuto (agendador do Laravel) e dispara cada tabela cujo
 * último horário da agenda ainda não foi disparado (servidor parado por horas = roda uma vez só ao voltar).
 */
class AgendaSincronizacoes
{
    public const MINIMO_MINUTOS = 5;

    public const MAXIMO_MINUTOS = 24 * 60;

    public function __construct(private SincronizadorProtheus $sincronizador) {}

    public static function fuso(): string
    {
        return (string) config('oee.fuso_exibicao');
    }

    /** Agenda de cada tabela de config/protheus.php (nova, desligada, quando ainda não existe). @return Collection<string, AgendaSincronizacao> */
    public function todas(): Collection
    {
        $gravadas = AgendaSincronizacao::all()->keyBy('chave');

        return collect(array_keys($this->sincronizador->itens()))
            ->mapWithKeys(fn ($chave) => [$chave => $gravadas[$chave] ?? new AgendaSincronizacao(['chave' => $chave,
                'ativo' => false, 'intervalo_minutos' => 60, 'inicio' => '00:00'])]);
    }

    /** Horários do dia (minutos desde 00:00, em ordem de execução a partir do início). @return list<int> */
    public static function horarios(int $intervalo, string $inicio): array
    {
        $intervalo = max(self::MINIMO_MINUTOS, min(self::MAXIMO_MINUTOS, $intervalo));
        $base = self::minutos($inicio);
        $vezes = max(1, intdiv(self::MAXIMO_MINUTOS, $intervalo));

        return array_map(fn ($k) => ($base + $k * $intervalo) % self::MAXIMO_MINUTOS, range(0, $vezes - 1));
    }

    /** Horário da agenda mais recente até agora (null se ainda nenhum). */
    public function ultimoHorario(AgendaSincronizacao $agenda, ?CarbonImmutable $agora = null): ?CarbonImmutable
    {
        $agora = ($agora ?? CarbonImmutable::now())->setTimezone(self::fuso());
        $ultimo = null;
        foreach ($this->ciclos($agenda, $agora) as $momento) {
            if ($momento->lte($agora) && ($ultimo === null || $momento->gt($ultimo))) {
                $ultimo = $momento;
            }
        }

        return $ultimo;
    }

    /** Próximo horário da agenda depois de agora. */
    public function proximoHorario(AgendaSincronizacao $agenda, ?CarbonImmutable $agora = null): CarbonImmutable
    {
        $agora = ($agora ?? CarbonImmutable::now())->setTimezone(self::fuso());
        $proximo = null;
        foreach ($this->ciclos($agenda, $agora) as $momento) {
            if ($momento->gt($agora) && ($proximo === null || $momento->lt($proximo))) {
                $proximo = $momento;
            }
        }

        return $proximo;
    }

    /** Próxima sincronização automática da tabela (agenda ligada); null = agenda desligada ou inexistente. */
    public static function proximaDe(string $chave): ?CarbonImmutable
    {
        $agenda = AgendaSincronizacao::where('chave', $chave)->where('ativo', true)->first();

        return $agenda ? app(self::class)->proximoHorario($agenda) : null;
    }

    /** "24x ao dia: 00:00, 01:00, 02:00 … 23:00" / "1x ao dia, às 00:00". */
    public static function descrever(AgendaSincronizacao $agenda): string
    {
        $h = array_map(fn ($m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60), self::horarios($agenda->intervalo_minutos, $agenda->inicio));
        if (count($h) === 1) {
            return "1x ao dia, às {$h[0]}";
        }
        $lista = count($h) <= 6 ? implode(', ', $h) : implode(', ', array_slice($h, 0, 3)).' … '.end($h);

        return count($h)."x ao dia: {$lista}";
    }

    /**
     * Tabelas na hora de sincronizar: agenda ligada e último horário ainda não disparado. Marca o horário como
     * disparado (mesmo que a sincronização falhe, não repete até o próximo horário).
     *
     * @return list<string> chaves
     */
    public function devidas(?CarbonImmutable $agora = null): array
    {
        $devidas = [];
        foreach (AgendaSincronizacao::where('ativo', true)->get() as $agenda) {
            if (! array_key_exists($agenda->chave, $this->sincronizador->itens())) {
                continue;
            }
            $ultimo = $this->ultimoHorario($agenda, $agora)?->utc();
            if ($ultimo === null || ($agenda->ultimo_horario && $agenda->ultimo_horario->gte($ultimo))) {
                continue;
            }
            // Sem passar pelo Auditavel: o agendador não muda quem editou a agenda.
            AgendaSincronizacao::whereKey($agenda->id)->toBase()->update(['ultimo_horario' => $ultimo]);
            $devidas[] = $agenda->chave;
        }

        return $devidas;
    }

    /** Horários de ontem, hoje e amanhã (cada ciclo começa no "a partir das" do dia). @return list<CarbonImmutable> */
    private function ciclos(AgendaSincronizacao $agenda, CarbonImmutable $referencia): array
    {
        $momentos = [];
        $base = self::minutos($agenda->inicio);
        $intervalo = max(self::MINIMO_MINUTOS, min(self::MAXIMO_MINUTOS, $agenda->intervalo_minutos));
        $vezes = max(1, intdiv(self::MAXIMO_MINUTOS, $intervalo));
        foreach ([-1, 0, 1] as $dias) {
            $inicioDoCiclo = $referencia->startOfDay()->addDays($dias)->addMinutes($base);
            for ($k = 0; $k < $vezes; $k++) {
                $momentos[] = $inicioDoCiclo->addMinutes($k * $intervalo);
            }
        }

        return $momentos;
    }

    private static function minutos(string $hora): int
    {
        return preg_match('/^(\d{1,2}):(\d{2})$/', trim($hora), $m) ? ((int) $m[1] % 24) * 60 + min(59, (int) $m[2]) : 0;
    }
}
