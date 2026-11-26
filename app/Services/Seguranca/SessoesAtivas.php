<?php

namespace App\Services\Seguranca;

use App\Models\Aplicativo\TokenAplicativo;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Segurança › Sessões ativas: quem está conectado no portal (tabela `sessions`, só com SESSION_DRIVER=database, dentro
 * de `session.lifetime` minutos sem uso) e no aplicativo (tokens válidos pelas mesmas regras do LoginAplicativo).
 * O id da sessão é segredo (quem o tem entra como o usuário): a tela só vê o hash SHA-256 dele (`chave`).
 */
class SessoesAtivas
{
    public function portalDisponivel(): bool
    {
        return config('session.driver') === 'database';
    }

    /** @return Collection<int, array<string, mixed>> */
    public function portal(?string $idAtual = null): Collection
    {
        if (! $this->portalDisponivel()) {
            return collect();
        }
        $linhas = $this->tabela()->whereNotNull('user_id')
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp())
            ->orderByDesc('last_activity')
            ->get(['id', 'user_id', 'ip_address', 'user_agent', 'last_activity']);
        $usuarios = Usuario::whereIntegerInRaw('id', $linhas->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->all())
            ->get(['id', 'nome', 'email'])->keyBy(fn ($u) => (int) $u->id);

        return $linhas->map(fn ($l) => [
            'chave' => hash('sha256', (string) $l->id),
            'usuario' => $usuarios[(int) $l->user_id] ?? null,
            'ip' => $l->ip_address,
            'navegador' => self::descreverNavegador($l->user_agent),
            'ultima_atividade' => Carbon::createFromTimestamp((int) $l->last_activity),
            'atual' => $idAtual !== null && hash_equals((string) $l->id, $idAtual),
        ]);
    }

    /** Desconecta a sessão do portal (apaga a linha da sessão: o navegador volta para o login). */
    public function encerrar(string $chave, ?string $idAtual): string
    {
        if (! $this->portalDisponivel()) {
            return 'indisponivel';
        }
        foreach ($this->tabela()->whereNotNull('user_id')->pluck('id') as $id) {
            if (hash_equals(hash('sha256', (string) $id), $chave)) {
                if ($idAtual !== null && hash_equals((string) $id, $idAtual)) {
                    return 'propria';
                }
                $this->tabela()->where('id', $id)->delete();

                return 'ok';
            }
        }

        return 'nao_encontrada';
    }

    /** Celulares com o app conectado (token não revogado, dentro da validade e do prazo sem uso). */
    public function celulares(): Collection
    {
        return TokenAplicativo::with('usuario:id,nome,email')->whereNull('revogado_em')
            ->where('criado_em', '>=', now()->subDays((int) config('oee.app.dias_validade_token', 30)))
            ->where(fn ($q) => $q->whereNull('ultimo_uso_em')
                ->orWhere('ultimo_uso_em', '>=', now()->subDays((int) config('oee.app.dias_sem_uso', 7))))
            ->orderByDesc('ultimo_uso_em')->get();
    }

    /** "Edge · Windows", "Chrome · Android"... a partir do User-Agent. */
    public static function descreverNavegador(?string $ua): string
    {
        $ua = (string) $ua;
        if ($ua === '') {
            return '-';
        }
        $navegador = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Outro navegador',
        };
        $sistema = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $sistema ? "{$navegador} · {$sistema}" : $navegador;
    }

    private function tabela()
    {
        return DB::connection(config('session.connection'))->table(config('session.table', 'sessions'));
    }
}
