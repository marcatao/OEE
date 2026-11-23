<?php

namespace App\Services\Manutencao;

use App\Models\ApontamentoEstado;
use App\Models\DispositivoIot;
use App\Models\Legenda;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Models\Usuario;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SS automática: quando um apontamento real recebe uma legenda que "abre SS" (legendas.abre_ss_unidade +
 * abre_ss_area_executante_id), abre uma solicitação para essa área e máquina — no portal, no aplicativo ou pelo IoT.
 * - título "OS automática — <legenda> — <máquina>"; centro de custo = o do cadastro da máquina;
 * - solicitante = quem apontou (o usuário logado no portal ou no aplicativo: quem criou o apontamento ou trocou a
 *   legenda); apontamento do IoT (sem usuário): o usuário técnico "IoT (automático)" e, em origem_iot, o IoT e o canal;
 * - não repete: uma por apontamento, e nenhuma nova enquanto houver SS automática pendente ou OS automática aberta da
 *   mesma máquina (de qualquer área: uma por máquina) — a parada nova fica VINCULADA a ela e aparece na máquina.
 */
final class SsAutomatica
{
    public const ENTRA_OID_IOT = 'sistema:iot';

    /** Resultado da última tentativa por apontamento (nesta requisição), para a tela avisar o que aconteceu. */
    private static array $resultados = [];

    public function __construct(private readonly FluxoOrdemServico $fluxo) {}

    /** Mensagem para a tela sobre a SS automática deste apontamento (aberta ou por que não abriu); null = nada a dizer. */
    public static function mensagem(?int $apontamentoId): ?string
    {
        return $apontamentoId !== null ? (self::$resultados[$apontamentoId] ?? null) : null;
    }

    public static function registrar(int $apontamentoId, string $mensagem): void
    {
        self::$resultados[$apontamentoId] = $mensagem;
    }

    /**
     * Conferência periódica (agendador, 5 min): apontamentos EM ABERTO com legenda que abre SS e ainda sem chamado (nem
     * aberto por eles, nem vinculado). Cobre o apontamento do IoT cujo aviso se perdeu (portal fora, integrador
     * reiniciado, aviso ainda não configurado quando a parada começou). Só os em aberto: parada que já acabou não abre
     * SS atrasada. Devolve [apontamento => mensagem] do que foi tentado.
     *
     * @return array<int, string>
     */
    public function conferirAbertos(): array
    {
        $legendas = Legenda::withoutGlobalScopes()->whereNotNull('abre_ss_area_executante_id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (! $legendas) {
            return [];
        }
        $resultado = [];
        ApontamentoEstado::whereNull('fim')->whereIntegerInRaw('legenda_id', $legendas)->whereNull('solicitacao_vinculada_id')
            ->whereNotExists(fn ($q) => $q->from('solicitacoes_servico')->whereColumn('solicitacoes_servico.apontamento_estado_id', 'apontamentos_estado.id'))
            ->orderBy('inicio')->limit(200)->get()
            ->each(function (ApontamentoEstado $ap) use (&$resultado) {
                try {
                    $ss = $this->aoApontar($ap);
                    $resultado[(int) $ap->id] = $ss ? 'SS '.$ss->numeroFormatado().' aberta' : (self::mensagem((int) $ap->id) ?? 'não abriu');
                } catch (\Throwable $e) {
                    report($e);
                    $resultado[(int) $ap->id] = 'erro: '.$e->getMessage();
                }
            });

        return $resultado;
    }

    public function aoApontar(ApontamentoEstado $ap): ?SolicitacaoServico
    {
        $legenda = Legenda::withoutGlobalScopes()->find($ap->legenda_id);
        if (! $legenda || ! $legenda->abreSs()) {
            return null; // legenda sem "Abre SS": nada a fazer nem a avisar
        }
        $area = $legenda->abreSsArea;
        $maquina = Maquina::withoutGlobalScopes()->find($ap->maquina_id);
        $nao = "SS automática da legenda {$legenda->codigo} NÃO aberta: ";
        if (! $area || ! $area->ativo) {
            self::registrar($ap->id, $nao.'a área executante escolhida na legenda não existe mais ou está inativa (Produção › Legendas).');

            return null;
        }
        if (! $maquina) {
            return null;
        }
        // Dois apontamentos da mesma máquina ao mesmo tempo (ex.: IoT e operador): um de cada vez, para não abrir duas SS.
        $trava = Cache::lock("ss-automatica-{$maquina->id}", 30); // por máquina (uma automática em aberto por máquina)
        try {
            $trava->block(20);
        } catch (LockTimeoutException) {
            // Não some em silêncio (apontamento do IoT não tem tela para avisar): fica no log para o administrador.
            Log::error('SS automática não aberta: outra gravação da mesma máquina demorou demais', ['apontamento' => $ap->id, 'maquina' => $maquina->id]);
            self::registrar($ap->id, $nao.'outra gravação desta máquina estava em andamento; aponte de novo a legenda.');

            return null;
        }
        try {
            return $this->abrirOuVincular($ap, $legenda, $area, $maquina, $nao);
        } finally {
            $trava->release();
        }
    }

    private function abrirOuVincular(ApontamentoEstado $ap, Legenda $legenda, $area, Maquina $maquina, string $nao): ?SolicitacaoServico
    {
        if ($existente = SolicitacaoServico::withoutGlobalScopes()->where('apontamento_estado_id', $ap->id)->first()) {
            self::registrar($ap->id, $nao.'este apontamento já abriu a SS '.$existente->numeroFormatado().'.');

            return null;
        }
        if ($emAberto = $this->jaEmAberto($maquina->id)) {
            // Não abre outra: vincula a SS que já está em aberto a esta parada (a Timeline e o app mostram o chamado).
            $ap->forceFill(['solicitacao_vinculada_id' => $emAberto['ss_id']])->saveQuietly();
            self::registrar($ap->id, $nao."já existe {$emAberto['texto']} automática em aberto desta máquina (uma por máquina)"
                .($emAberto['ss_id'] ? ' — vinculada a esta parada.' : '.'));

            return null;
        }

        // Quem apontou: quem criou o apontamento ou quem trocou a legenda (portal ou aplicativo). Sem usuário = IoT.
        $quemId = $ap->wasRecentlyCreated ? $ap->criado_por_id : ($ap->atualizado_por_id ?? $ap->criado_por_id);
        $quem = $quemId ? Usuario::find($quemId) : null;
        $porIot = $quem === null || $ap->automaticoIot();
        $solicitante = $quem ?? self::usuarioIot();
        $origem = $porIot ? $this->origem($ap, $maquina) : 'apontado por '.$solicitante->nome;
        $quando = $ap->inicio ? AppServiceProvider::dataHora($ap->inicio) : '';

        return DB::transaction(function () use ($ap, $legenda, $area, $maquina, $solicitante, $porIot, $origem, $quando) {
            $ss = SolicitacaoServico::create([
                'unidade' => $legenda->abre_ss_unidade ?: OrdemServico::unidadePara($area, $maquina),
                'solicitante_id' => $solicitante->id,
                'titulo' => mb_substr("OS automática — {$legenda->codigo} {$legenda->descricao} — {$maquina->codigo}", 0, 120),
                'tipo' => 'corretiva',
                'maquina_id' => $maquina->id,
                'area_executante_id' => $area->id,
                'centro_custo' => trim((string) $maquina->centro_custo) ?: null,
                'centro_custo_solicitante' => mb_substr($porIot ? $origem : ((string) ($solicitante->local_escritorio ?: $solicitante->departamento) ?: ''), 0, 120) ?: null,
                'prioridade' => $legenda->tipo === 'parada' ? 'alta' : 'baixa',
                'maquina_parada' => in_array($legenda->tipo, ['parada', 'planejada'], true),
                'descricao' => mb_substr("Solicitação aberta automaticamente: a legenda {$legenda->codigo} — {$legenda->descricao} foi apontada "
                    ."na máquina {$maquina->codigo} — {$maquina->nome}".($quando !== '' ? " em {$quando}" : '').". Origem: {$origem}.", 0, 2000),
                'status' => 'pendente',
                'apontamento_estado_id' => $ap->id,
                'ordem_producao_id' => $ap->ordem_producao_id,
                'automatica' => true,
                'origem_iot' => $porIot ? mb_substr($origem, 0, 120) : null,
            ]);
            $this->fluxo->registrar(null, $ss, 'aberta', null, 'pendente', "SS automática pela legenda {$legenda->codigo} ({$origem}).", $solicitante);
            self::registrar($ap->id, 'SS automática '.$ss->numeroFormatado()." aberta para {$area->nome}.");

            return $ss;
        });
    }

    /** Solicitante é o usuário técnico do IoT (então quem avalia a OS é o administrador da unidade)? */
    public static function solicitanteEhIot(?int $usuarioId): bool
    {
        return $usuarioId !== null && Usuario::whereKey($usuarioId)->where('entra_oid', self::ENTRA_OID_IOT)->exists();
    }

    /** Usuário técnico solicitante das SS abertas pelo IoT (sem usuário logado). Inativo: não entra no portal. */
    public static function usuarioIot(): Usuario
    {
        return Usuario::firstOrCreate(['entra_oid' => self::ENTRA_OID_IOT], ['nome' => 'IoT (automático)', 'ativo' => false]);
    }

    /**
     * SS automática pendente ou OS automática aberta da máquina e área.
     *
     * @return array{texto: string, ss_id: ?int}|null texto "a SS 000123" / "a OS 000045" e a SS (da OS, se for OS)
     */
    /** SS/OS automática em aberto da máquina, de QUALQUER área executante (uma por máquina; decidido em 23/11/2026). */
    private function jaEmAberto(int $maquinaId): ?array
    {
        $ss = SolicitacaoServico::withoutGlobalScopes()->where('automatica', true)->where('status', 'pendente')
            ->where('maquina_id', $maquinaId)->orderByDesc('id')->first();
        if ($ss) {
            return ['texto' => 'a SS '.$ss->numeroFormatado(), 'ss_id' => $ss->id];
        }
        $os = OrdemServico::withoutGlobalScopes()->where('automatica', true)->whereIn('status', OrdemServico::STATUS_ABERTOS)
            ->where('maquina_id', $maquinaId)->orderByDesc('id')->first();

        return $os ? ['texto' => 'a OS '.$os->numeroFormatado(), 'ss_id' => $os->solicitacao_id] : null;
    }

    /** "IoT GW01 / chd1_value": do apontamento (Integrador MQTT) ou, sem isso, o 1º canal IoT da máquina. */
    private function origem(ApontamentoEstado $ap, Maquina $maquina): string
    {
        $doApontamento = $ap->origemIot();
        if ($doApontamento !== '') {
            return 'IoT '.$doApontamento;
        }
        foreach (Maquina::CANAIS_IOT as $n) {
            if ($id = $maquina->{"iot{$n}_dispositivo_id"}) {
                $codigo = DispositivoIot::withoutGlobalScopes()->whereKey($id)->value('codigo') ?? "dispositivo #{$id}";

                return 'IoT '.implode(' / ', array_filter([$codigo, $maquina->{"iot{$n}_canal"}]));
            }
        }

        return 'IoT';
    }
}
