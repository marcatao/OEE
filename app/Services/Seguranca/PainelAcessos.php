<?php

namespace App\Services\Seguranca;

use App\Models\AcessoPagina;
use App\Models\Aplicativo\TokenAplicativo;
use App\Models\RegistroLogin;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Segurança › Painel de logins: entradas por dia e por hora, recusas por motivo, usuários ativos hoje (portal ou app),
 * páginas mais abertas e o último uso de cada usuário ativo (quem nunca entrou e quem está sem uso há N dias, para
 * bloquear). Os dias são os do fuso local (`oee.fuso_exibicao`); no banco tudo é UTC. Só leitura, exceto a retenção.
 */
class PainelAcessos
{
    /** Início (UTC) do dia local de hoje, N-1 dias para trás. */
    public static function inicioDosDias(int $dias): Carbon
    {
        return now(config('oee.fuso_exibicao'))->startOfDay()->subDays(max(1, $dias) - 1)->utc();
    }

    /**
     * Período dos filtros (datas locais "AAAA-MM-DD") em UTC: [início, fim exclusivo]; data inválida ou vazia = null.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function periodo(?string $de, ?string $ate): array
    {
        $fuso = config('oee.fuso_exibicao');
        $ler = function (?string $d) use ($fuso): ?Carbon {
            if (! $d || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return null;
            }
            try {
                return Carbon::createFromFormat('!Y-m-d', $d, $fuso);
            } catch (\Throwable) {
                return null;
            }
        };
        $inicio = $ler($de);
        $fim = $ler($ate);

        return [$inicio?->utc(), $fim?->addDay()->utc()];
    }

    /** @return array<string, mixed> */
    public function resumo(int $dias, int $diasSemUso): array
    {
        $fuso = config('oee.fuso_exibicao');
        $inicio = self::inicioDosDias($dias);
        $hoje = self::inicioDosDias(1);

        $logins = RegistroLogin::where('data_hora', '>=', $inicio)->get(['data_hora', 'sucesso', 'usuario_id', 'motivo']);

        // Entradas, recusas e usuários por dia (todos os dias do período, mesmo sem login).
        $porDia = [];
        for ($d = CarbonImmutable::parse($inicio)->setTimezone($fuso); count($porDia) < $dias; $d = $d->addDay()) {
            $porDia[$d->format('Y-m-d')] = ['dia' => $d, 'entradas' => 0, 'recusas' => 0, 'usuarios' => []];
        }
        $porHora = array_fill(0, 24, 0);
        foreach ($logins as $l) {
            $local = $l->data_hora->copy()->setTimezone($fuso);
            $chave = $local->format('Y-m-d');
            if (! isset($porDia[$chave])) {
                continue;
            }
            if ($l->sucesso) {
                $porDia[$chave]['entradas']++;
                $porHora[(int) $local->format('G')]++;
                if ($l->usuario_id) {
                    $porDia[$chave]['usuarios'][(int) $l->usuario_id] = true;
                }
            } else {
                $porDia[$chave]['recusas']++;
            }
        }
        $porDia = array_map(fn ($d) => ['dia' => $d['dia'], 'entradas' => $d['entradas'], 'recusas' => $d['recusas'],
            'usuarios' => count($d['usuarios'])], array_values($porDia));

        $recusasPorMotivo = $logins->where('sucesso', false)
            ->groupBy(fn ($l) => $l->motivo ?: 'sem motivo informado')
            ->map->count()->sortDesc();

        // Ativos hoje: entraram, abriram página ou usaram o app desde o início do dia local.
        $ativosHoje = collect()
            ->merge($logins->where('sucesso', true)->filter(fn ($l) => $l->data_hora->gte($hoje))->pluck('usuario_id'))
            ->merge(AcessoPagina::where('data_hora', '>=', $hoje)->distinct()->pluck('usuario_id'))
            ->merge(TokenAplicativo::where('ultimo_uso_em', '>=', $hoje)->distinct()->pluck('usuario_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique();

        $paginas = AcessoPagina::where('data_hora', '>=', $inicio)
            ->select('rota', DB::raw('COUNT(*) as total'), DB::raw('COUNT(DISTINCT usuario_id) as usuarios'))
            ->groupBy('rota')->orderByDesc('total')->limit(15)->get();

        $usuarios = $this->ultimoUsoDosUsuarios($diasSemUso);

        return [
            'inicio' => $inicio,
            'porDia' => $porDia,
            'porHora' => $porHora,
            'recusasPorMotivo' => $recusasPorMotivo,
            'entradasPeriodo' => $logins->where('sucesso', true)->count(),
            'recusasPeriodo' => $logins->where('sucesso', false)->count(),
            'entradasHoje' => $logins->where('sucesso', true)->filter(fn ($l) => $l->data_hora->gte($hoje))->count(),
            'ativosHoje' => $ativosHoje->count(),
            'paginas' => $paginas,
            'usuarios' => $usuarios,
            'nuncaEntraram' => $usuarios->where('situacao', 'nunca')->count(),
            'semUso' => $usuarios->where('situacao', 'sem_uso')->count(),
        ];
    }

    /**
     * Usuários ATIVOS com o último uso (o mais recente entre login, página aberta e app) e a situação:
     * `nunca` (nunca entrou), `sem_uso` (sem uso há mais de N dias), `hoje` ou `em_uso`. Mais tempo sem uso primeiro.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ultimoUsoDosUsuarios(int $diasSemUso): Collection
    {
        $usoApp = TokenAplicativo::query()->select('usuario_id', DB::raw('MAX(ultimo_uso_em) as ultimo'))
            ->groupBy('usuario_id')->pluck('ultimo', 'usuario_id')
            ->mapWithKeys(fn ($v, $id) => [(int) $id => $v ? Carbon::parse($v) : null]);
        $limite = now()->subDays($diasSemUso);
        $hoje = self::inicioDosDias(1);

        return Usuario::where('ativo', true)->orderBy('nome')
            ->get(['id', 'nome', 'email', 'perfil', 'ultimo_login_em', 'ultimo_acesso_em', 'criado_em'])
            ->map(function (Usuario $u) use ($usoApp, $limite, $hoje) {
                $app = $usoApp[(int) $u->id] ?? null;
                $ultimo = collect([$u->ultimo_login_em, $u->ultimo_acesso_em, $app])->filter()->max();

                return [
                    'usuario' => $u,
                    'ultimo_login' => $u->ultimo_login_em,
                    'ultima_pagina' => $u->ultimo_acesso_em,
                    'ultimo_app' => $app,
                    'ultimo_uso' => $ultimo,
                    'dias_sem_uso' => $ultimo ? (int) floor($ultimo->diffInDays(now())) : null,
                    'situacao' => match (true) {
                        $ultimo === null => 'nunca',
                        $ultimo->lt($limite) => 'sem_uso',
                        $ultimo->gte($hoje) => 'hoje',
                        default => 'em_uso',
                    },
                ];
            })
            ->sortBy(fn ($l) => $l['ultimo_uso']?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * Retenção de `acessos_paginas`: apaga em lotes as linhas mais antigas que `oee.acessos_paginas.dias_retencao`.
     * Devolve quantas apagou, ou null com a retenção desligada (0). Só esta tabela é tocada.
     */
    public function limparPaginasAntigas(int $lote = 2000): ?int
    {
        $dias = (int) config('oee.acessos_paginas.dias_retencao', 180);
        if ($dias <= 0) {
            return null;
        }
        $limite = now()->subDays($dias);
        $total = 0;
        do {
            $ids = AcessoPagina::where('data_hora', '<', $limite)->orderBy('id')->limit($lote)->pluck('id')
                ->map(fn ($id) => (int) $id)->all();
            if ($ids !== []) {
                $total += AcessoPagina::whereIntegerInRaw('id', $ids)->delete();
            }
        } while (count($ids) === $lote);

        return $total;
    }
}
