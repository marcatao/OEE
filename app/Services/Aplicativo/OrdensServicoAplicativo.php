<?php

namespace App\Services\Aplicativo;

use App\Models\CentroCusto;
use App\Models\Manutencao\ApontamentoHoras;
use App\Models\Manutencao\AssinaturaOs;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Usuario;
use App\Services\HorarioApontamento;
use App\Services\Manutencao\FluxoOrdemServico;
use App\Support\AcessoOs;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Função "Ordens de serviço" do aplicativo (mantenedor). Usa as MESMAS regras do portal: AcessoOs (quem vê, aprova e
 * executa) e FluxoOrdemServico (SS → OS, ações, apontamento de horas, avaliação).
 * - "Assumir" uma SS pendente = aprová-la ficando como mantenedor principal (o primeiro que assume leva; os outros
 *   recebem "já assumida").
 * - Apontamento com cronômetro: iniciar / encerrar (com a descrição do serviço, se conclui a OS e se a máquina
 *   estava parada). O fim informado pelo celular vale (entre o início e agora), para não perder minutos sem rede.
 * - Avaliação da OS concluída pelo SOLICITANTE no celular do mantenedor, com assinatura (tabela os_assinaturas).
 */
class OrdensServicoAplicativo
{
    public function __construct(private FluxoOrdemServico $fluxo) {}

    /** Resumo para o menu e para os alertas (consultado de tempos em tempos pelo app). */
    public function resumo(Usuario $u): array
    {
        $m = AcessoOs::mantenedorDo($u);
        $pendentes = $this->pendentesParaAprovar($u);

        return [
            'servidor_agora' => now()->toIso8601String(),
            'mantenedor' => $m ? ['id' => $m->id, 'nome' => $m->nomeExibicao()] : null,
            'pode_aprovar' => AcessoOs::podeAprovar($u),
            'pode_executar' => AcessoOs::podeExecutar($u),
            'pode_solicitar' => AcessoOs::podeSolicitar($u),
            'pendentes' => $pendentes->map(fn ($ss) => $this->ss($ss))->values()->all(),
            'minhas_abertas' => $m ? $this->minhasOrdens($u, $m)->count() : 0,
            'avaliacoes' => $m ? $this->paraAvaliar($u, $m)->count() : 0,
            'rodando' => ($ap = $this->rodando($m)) ? $this->apontamento($ap) + ['os' => $this->osResumo($ap->ordemServico)] : null,
        ];
    }

    /** SS aguardando análise que o usuário pode aprovar (as de máquina parada primeiro). */
    public function pendentesParaAprovar(Usuario $u): Collection
    {
        if (! AcessoOs::podeAprovar($u)) {
            return collect();
        }

        return $this->visiveis(SolicitacaoServico::query(), $u)->where('status', 'pendente')
            ->with(['maquina' => fn ($q) => $q->withoutGlobalScopes(), 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes(), 'solicitante'])
            ->orderByDesc('maquina_parada')->orderBy('id')->limit(50)->get()
            ->filter(fn ($ss) => AcessoOs::podeAprovar($u, $ss))->values();
    }

    /** SS do próprio usuário (as últimas 30). */
    public function minhasSolicitacoes(Usuario $u): Collection
    {
        return SolicitacaoServico::where('solicitante_id', $u->id)
            ->with(['maquina' => fn ($q) => $q->withoutGlobalScopes(), 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes(), 'solicitante', 'ordemServico'])
            ->orderByDesc('id')->limit(30)->get();
    }

    /**
     * O mantenedor assume a SS: ela é aprovada e vira OS com ele como principal. Só o primeiro leva (a SS é relida com
     * trava dentro da transação).
     */
    public function assumir(Usuario $u, int $ssId): OrdemServico
    {
        $m = $this->exigirMantenedor($u);

        return DB::transaction(function () use ($u, $ssId, $m) {
            $ss = $this->visiveis(SolicitacaoServico::query(), $u)->lockForUpdate()->findOrFail($ssId);
            if ($ss->status !== 'pendente') {
                $quem = $ss->ordemServico?->responsavel?->nomeExibicao() ?? $ss->analisadoPor?->nome;
                throw ValidationException::withMessages(['ss' => 'Esta solicitação já foi '.($ss->status === 'aprovada' ? 'assumida' : 'analisada')
                    .($quem ? " por {$quem}" : '').'.']);
            }
            abort_unless(AcessoOs::podeAprovar($u, $ss), 403, 'Esta SS é de uma área executante que você não atende.');

            return $this->fluxo->aprovar($ss, $u, [$m->id], 'Assumida pelo aplicativo.');
        });
    }

    public function rejeitar(Usuario $u, int $ssId, string $motivo): void
    {
        $ss = $this->visiveis(SolicitacaoServico::query(), $u)->findOrFail($ssId);
        abort_unless(AcessoOs::podeAprovar($u, $ss), 403, 'Esta SS é de uma área executante que você não atende.');
        $this->fluxo->rejeitar($ss, $u, $motivo);
    }

    /** Dados para abrir uma SS: áreas que o usuário pode pedir, máquinas de cada área, tipos e prioridades. */
    public function formulario(Usuario $u): array
    {
        $areas = AcessoOs::areasParaSolicitar($u);
        $maquinas = AcessoOs::maquinasDasAreas($areas);

        return [
            'pode_solicitar' => AcessoOs::podeSolicitar($u),
            'areas' => $areas->map(fn ($a) => ['id' => $a->id, 'nome' => $a->nome, 'unidade' => $a->unidade])->values()->all(),
            'maquinas' => $maquinas->map(fn ($mq) => [
                'id' => $mq->id, 'codigo' => rtrim((string) $mq->codigo), 'nome' => $mq->nome,
                'areas' => $areas->filter(fn ($a) => AcessoOs::maquinaDaArea($mq, $a))->pluck('id')->values()->all(),
            ])->values()->all(),
            'tipos' => $this->opcoes(SolicitacaoServico::TIPOS),
            'prioridades' => $this->opcoes(SolicitacaoServico::PRIORIDADES),
            'centro_custo_usuario' => AcessoOs::centroCustoDo($u),
        ];
    }

    /** OS em aberto do mantenedor (as dele e as ainda sem ninguém), como "Minhas OS" do portal. */
    public function minhasOrdens(Usuario $u, ?Mantenedor $m): Builder
    {
        return $this->visiveis(OrdemServico::query(), $u)
            ->whereIn('status', OrdemServico::STATUS_ABERTOS)
            ->when($m, fn ($q) => $q->where(fn ($q) => $q->where('responsavel_id', $m->id)
                ->orWhereHas('mantenedores', fn ($w) => $w->where('os_mantenedores.id', $m->id))
                ->orWhere(fn ($q) => $q->whereNull('responsavel_id')->whereDoesntHave('mantenedores'))));
    }

    public function listaOrdens(Usuario $u): array
    {
        $m = AcessoOs::mantenedorDo($u);
        $ordens = $this->minhasOrdens($u, $m)->with($this->relacoes())
            ->orderByDesc('maquina_parada')
            ->orderByRaw("CASE prioridade WHEN 'urgente' THEN 0 WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END")
            ->orderBy('id')->limit(100)->get();

        return $ordens->map(fn ($os) => $this->osResumo($os))->values()->all();
    }

    /** OS concluídas (dele) esperando a avaliação do solicitante. */
    public function paraAvaliar(Usuario $u, Mantenedor $m): Builder
    {
        return $this->visiveis(OrdemServico::query(), $u)->where('status', 'concluida')
            ->where(fn ($q) => $q->where('responsavel_id', $m->id)->orWhereHas('mantenedores', fn ($w) => $w->where('os_mantenedores.id', $m->id)));
    }

    public function listaAvaliacoes(Usuario $u): array
    {
        $m = AcessoOs::mantenedorDo($u);
        if (! $m) {
            return [];
        }

        return $this->paraAvaliar($u, $m)->with($this->relacoes())->orderByDesc('concluida_em')->limit(50)->get()
            ->map(fn ($os) => $this->osResumo($os))->values()->all();
    }

    public function buscarOs(Usuario $u, int $id): OrdemServico
    {
        return $this->visiveis(OrdemServico::query(), $u)
            ->with([...$this->relacoes(), 'apontamentos.mantenedor', 'solicitacao'])->findOrFail($id);
    }

    /** Tudo da OS para a tela do app (e para a tela travada do apontamento e a avaliação). */
    public function detalhe(Usuario $u, OrdemServico $os): array
    {
        $m = AcessoOs::mantenedorDo($u);
        $fuso = HorarioApontamento::fuso();
        $podeExecutar = AcessoOs::podeExecutar($u, $os);
        $acoes = $os->estaAberta() && $podeExecutar ? FluxoOrdemServico::acoesPossiveis($os) : [];
        unset($acoes['cancelar'], $acoes['reabrir'], $acoes['iniciar']); // cancelar/reabrir: só no portal; iniciar = apontamento
        $meu = $m ? $os->apontamentos->first(fn ($a) => (int) $a->mantenedor_id === (int) $m->id && $a->fim === null) : null;

        return $this->osResumo($os) + [
            'servidor_agora' => now()->toIso8601String(),
            'descricao' => $os->descricao,
            'solucao' => $os->solucao,
            'tipo' => $os->rotuloTipo(),
            'abertura' => $this->hora($os->solicitacao?->criado_em ?? $os->criado_em, $fuso),
            'concluida_em' => $this->hora($os->concluida_em, $fuso),
            'total_minutos' => (int) $os->total_minutos,
            'pode_executar' => $podeExecutar,
            'pode_avaliar' => $os->status === 'concluida' && $podeExecutar && $os->solicitante_id !== null,
            'acoes' => collect($acoes)->map(fn ($rotulo, $acao) => ['acao' => $acao, 'rotulo' => $rotulo])->values()->all(),
            'meu_apontamento' => $meu ? $this->apontamento($meu) : null,
            'apontamentos' => $os->apontamentos->sortBy('inicio')->map(fn ($a) => $this->apontamento($a))->values()->all(),
        ];
    }

    public function iniciar(Usuario $u, int $osId): ApontamentoHoras
    {
        $os = $this->buscarOs($u, $osId);
        AcessoOs::exigirAberta($os);
        abort_unless(AcessoOs::podeExecutar($u, $os), 403, 'Esta OS não é de uma área executante que você atende.');

        return $this->fluxo->iniciarApontamento($os, $this->exigirMantenedor($u), $u);
    }

    /**
     * Encerra o apontamento do próprio mantenedor: descrição obrigatória, fim informado pelo celular (entre o início e
     * agora), "a máquina estava/está parada?" (grava na OS) e, se pedido, conclui a OS.
     */
    public function encerrar(Usuario $u, int $apId, string $descricao, bool $concluir, ?bool $maquinaParada, ?string $fimUtc): OrdemServico
    {
        $m = $this->exigirMantenedor($u);
        $ap = ApontamentoHoras::findOrFail($apId);
        // (int): no SQL Server os ids chegam como texto.
        abort_unless((int) $ap->mantenedor_id === (int) $m->id, 403, 'Você só pode encerrar os seus apontamentos.');
        $os = $this->buscarOs($u, $ap->ordem_servico_id);
        if ($ap->fim !== null) {
            return $os; // já encerrado (ex.: o celular reenviou): nada a fazer
        }
        AcessoOs::exigirAberta($os);

        $agora = CarbonImmutable::now()->startOfMinute();
        $fim = $fimUtc ? rescue(fn () => CarbonImmutable::parse($fimUtc)->utc()->startOfMinute(), $agora, false) : $agora;
        $fim = $fim->greaterThan($agora) ? $agora : $fim;
        $fim = $fim->lessThan($ap->inicio) ? CarbonImmutable::parse($ap->inicio) : $fim;

        DB::transaction(function () use ($u, $ap, $os, $descricao, $concluir, $maquinaParada, $fim) {
            $this->fluxo->gravarApontamento($ap, $ap->inicio, $fim, $descricao);
            if ($maquinaParada !== null && $os->maquina_id && $maquinaParada !== (bool) $os->maquina_parada) {
                $os->forceFill(['maquina_parada' => $maquinaParada])->save();
                $this->fluxo->registrar($os, null, 'maquina_parada', $os->status, $os->status,
                    $maquinaParada ? 'Máquina parada (informado no aplicativo)' : 'Máquina funcionando (informado no aplicativo)', $u);
            }
            if ($concluir) {
                $this->fluxo->executarAcao($os->fresh(), 'concluir', $u, $descricao);
            }
        });

        return $os->fresh();
    }

    public function acao(Usuario $u, int $osId, string $acao, ?string $obs): void
    {
        $os = $this->buscarOs($u, $osId);
        if (! in_array($acao, ['aguardar_material', 'retomar', 'concluir'], true)) {
            throw ValidationException::withMessages(['acao' => 'Esta ação é feita no portal.']);
        }
        AcessoOs::exigirAberta($os);
        abort_unless(AcessoOs::podeExecutar($u, $os), 403, 'Esta OS não é de uma área executante que você atende.');
        $this->fluxo->executarAcao($os, $acao, $u, $obs);
    }

    /**
     * Avaliação do SOLICITANTE feita no celular do mantenedor, com assinatura. Conta como a avaliação do solicitante
     * (FluxoOrdemServico::avaliar com ele), e o histórico registra quem estava logado no app.
     */
    public function avaliar(Usuario $u, int $osId, string $avaliacao, ?string $obs, string $assinatura, ?string $nome): OrdemServico
    {
        $os = $this->buscarOs($u, $osId);
        abort_unless($os->status === 'concluida', 422, 'Só dá para avaliar uma OS concluída.');
        abort_unless(AcessoOs::podeExecutar($u, $os) || (int) $os->solicitante_id === (int) $u->id, 403, 'Esta OS não é sua.');
        $solicitante = $os->solicitante;
        if (! $solicitante) {
            throw ValidationException::withMessages(['os' => 'Esta OS não tem solicitante para avaliar (ex.: preventiva).']);
        }
        $png = $this->conferirAssinatura($assinatura);

        DB::transaction(function () use ($u, $os, $avaliacao, $obs, $png, $nome, $solicitante) {
            $this->fluxo->avaliar($os, $solicitante, $avaliacao, $obs);
            AssinaturaOs::create([
                'ordem_servico_id' => $os->id,
                'assinado_por_id' => $solicitante->id,
                'registrado_por_id' => $u->id,
                'nome_assinante' => mb_substr(trim((string) $nome) ?: $solicitante->nome, 0, 120),
                'avaliacao' => $avaliacao,
                'imagem_png' => $png,
            ]);
            $this->fluxo->registrar($os, null, 'assinatura', $os->status, $os->status,
                'Avaliação feita pelo solicitante no aplicativo, com assinatura, no celular de '.$u->nome.'.', $u);
        });

        return $os->fresh();
    }

    public function rodando(?Mantenedor $m): ?ApontamentoHoras
    {
        return $m ? ApontamentoHoras::where('mantenedor_id', $m->id)->whereNull('fim')
            ->with(['ordemServico' => fn ($q) => $q->with($this->relacoes())])->first() : null;
    }

    /**
     * SS/OS que o usuário vê no aplicativo: exatamente as regras do portal (AcessoOs::filtrar), que já restringem às
     * unidades habilitadas nos perfis do login — o mantenedor não vê nem é alertado por SS/OS de outra unidade.
     */
    public function visiveis(Builder $consulta, Usuario $u): Builder
    {
        return AcessoOs::filtrar($consulta, $u);
    }

    public function exigirMantenedor(Usuario $u): Mantenedor
    {
        return AcessoOs::mantenedorDo($u) ?? throw ValidationException::withMessages(['mantenedor' => 'Seu login não está ligado a um mantenedor. '
            .'Peça para um administrador ligar em Ordens de serviço › Mantenedores.']);
    }

    /** PNG em base64 (com ou sem "data:image/png;base64,"), até 600 KB. */
    private function conferirAssinatura(string $assinatura): string
    {
        $base64 = preg_replace('#^data:image/png;base64,#', '', trim($assinatura));
        $bytes = base64_decode((string) $base64, true);
        if ($bytes === false || ! str_starts_with($bytes, "\x89PNG") || strlen($bytes) > 600 * 1024 || strlen($bytes) < 100) {
            throw ValidationException::withMessages(['assinatura' => 'Peça para o solicitante assinar no quadro.']);
        }

        return base64_encode($bytes);
    }

    private function relacoes(): array
    {
        return ['maquina' => fn ($q) => $q->withoutGlobalScopes(), 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes(),
            'solicitante', 'responsavel' => fn ($q) => $q->withoutGlobalScopes()];
    }

    private function osResumo(OrdemServico $os): array
    {
        $cc = trim((string) $os->centro_custo);

        return [
            'id' => $os->id,
            'numero' => $os->numeroFormatado(),
            'titulo' => $os->titulo,
            'status' => $os->status,
            'status_rotulo' => $os->rotuloStatus(),
            'prioridade' => $os->prioridade,
            'prioridade_rotulo' => $os->rotuloPrioridade(),
            'maquina' => $os->maquina ? trim(rtrim((string) $os->maquina->codigo).' · '.$os->maquina->nome) : null,
            'maquina_parada' => (bool) $os->maquina_parada,
            'area' => $os->areaExecutante?->nome,
            'centro_custo' => $cc !== '' ? trim($cc.' · '.$this->descricaoCc($cc), ' ·') : null,
            'setor' => $os->solicitante?->setor ?: $os->centro_custo_solicitante,
            'solicitante' => $os->solicitante?->nome,
            'responsavel' => $os->responsavel?->nomeExibicao(),
        ];
    }

    private function ss(SolicitacaoServico $ss): array
    {
        return [
            'id' => $ss->id,
            'numero' => $ss->numeroFormatado(),
            'titulo' => $ss->titulo,
            'descricao' => $ss->descricao,
            'status' => $ss->status,
            'status_rotulo' => $ss->rotuloStatus(),
            'prioridade_rotulo' => $ss->rotuloPrioridade(),
            'tipo' => $ss->rotuloTipo(),
            'maquina' => $ss->maquina ? trim(rtrim((string) $ss->maquina->codigo).' · '.$ss->maquina->nome) : null,
            'maquina_parada' => (bool) $ss->maquina_parada,
            'area' => $ss->areaExecutante?->nome,
            'centro_custo' => $ss->centro_custo,
            'solicitante' => $ss->solicitante?->nome,
            'aberta_em' => $this->hora($ss->criado_em, HorarioApontamento::fuso()),
            'os' => $ss->relationLoaded('ordemServico') ? $ss->ordemServico?->numeroFormatado() : null,
            'motivo_rejeicao' => $ss->motivo_rejeicao,
        ];
    }

    public function ssPublica(SolicitacaoServico $ss): array
    {
        return $this->ss($ss);
    }

    private function apontamento(ApontamentoHoras $a): array
    {
        $fuso = HorarioApontamento::fuso();

        return [
            'id' => $a->id,
            'ordem_servico_id' => $a->ordem_servico_id,
            'mantenedor' => $a->mantenedor?->nomeExibicao(),
            'inicio_utc' => $a->inicio?->toIso8601String(),
            'fim_utc' => $a->fim?->toIso8601String(),
            'inicio' => $this->hora($a->inicio, $fuso),
            'fim' => $this->hora($a->fim, $fuso),
            'minutos' => (int) ($a->minutos_normais ?? 0) + (int) ($a->minutos_extras ?? 0),
            'descricao' => $a->descricao,
        ];
    }

    private function descricaoCc(string $codigo): string
    {
        static $cache = [];

        return $cache[$codigo] ??= trim((string) CentroCusto::withoutGlobalScope('unidade')->where('ctt_custo', $codigo)->value('ctt_desc01'));
    }

    private function opcoes(array $mapa): array
    {
        return collect($mapa)->map(fn ($rotulo, $valor) => ['valor' => $valor, 'rotulo' => $rotulo])->values()->all();
    }

    private function hora(?CarbonInterface $valor, string $fuso): ?string
    {
        return $valor?->copy()->setTimezone($fuso)->format('d/m/Y H:i');
    }
}
