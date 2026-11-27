<?php

namespace App\Services\Manutencao;

use App\Models\Manutencao\ApontamentoHoras;
use App\Models\Manutencao\ChecklistOs;
use App\Models\Manutencao\HistoricoOs;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Usuario;
use App\Support\AcessoOs;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fluxo da manutenção (ver .claude/agents/especialista-manutencao.md):
 * SS pendente → aprovada (vira OS, copiando os dados) | rejeitada (com motivo, volta ao solicitante) | cancelada;
 * OS aberta → em execução ⇄ aguardando material → concluída (data do último apontamento) → encerrada (aceite do
 * solicitante) | cancelada. Toda mudança grava os_historico.
 */
final class FluxoOrdemServico
{
    /** Ações da OS: [status de onde pode sair, status novo, rótulo]. */
    public const ACOES = [
        'iniciar' => [['aberta'], 'em_execucao', 'Iniciar execução'],
        'aguardar_material' => [['aberta', 'em_execucao'], 'aguardando_material', 'Aguardando material'],
        'retomar' => [['aguardando_material'], 'em_execucao', 'Retomar execução'],
        'concluir' => [['aberta', 'em_execucao', 'aguardando_material'], 'concluida', 'Concluir serviço'],
        'reabrir' => [['concluida', 'cancelada'], 'em_execucao', 'Reabrir'], // encerrada (avaliada pelo solicitante) não reabre
        'cancelar' => [['aberta', 'em_execucao', 'aguardando_material'], 'cancelada', 'Cancelar OS'],
    ];

    public function registrar(?OrdemServico $os, ?SolicitacaoServico $ss, string $acao, ?string $de, ?string $para, ?string $obs, ?Usuario $usuario): void
    {
        HistoricoOs::create([
            'ordem_servico_id' => $os?->id,
            'solicitacao_id' => $ss?->id ?? $os?->solicitacao_id,
            'acao' => $acao,
            'status_anterior' => $de,
            'status_novo' => $para,
            'observacao' => $obs !== null ? mb_substr($obs, 0, 1000) : null,
            'usuario_id' => $usuario?->id,
        ]);
    }

    /**
     * Respostas do checklist (preventiva), regra única do portal e do aplicativo: para cada item informado (id => [resposta,
     * observação]), grava só o que mudou, com quem respondeu e quando. Item fora da lista fica como está; resposta vazia
     * limpa o item. @param array<int, array{resposta: ?string, observacao: ?string}> $respostas
     */
    public function responderChecklist(OrdemServico $os, array $respostas, Usuario $usuario): int
    {
        $mudou = 0;
        foreach ($os->checklist()->get() as $item) {
            if (! array_key_exists((int) $item->id, $respostas)) {
                continue;
            }
            $resposta = $respostas[(int) $item->id]['resposta'] ?? null;
            $resposta = in_array($resposta, array_keys(ChecklistOs::RESPOSTAS), true) ? $resposta : null;
            $obs = trim((string) ($respostas[(int) $item->id]['observacao'] ?? '')) ?: null;
            if ($resposta !== $item->resposta || $obs !== $item->observacao) {
                $item->forceFill(['resposta' => $resposta, 'observacao' => $obs !== null ? mb_substr($obs, 0, 300) : null,
                    'respondido_por_id' => $usuario->id, 'respondido_em' => now()])->save();
                $mudou++;
            }
        }

        return $mudou;
    }

    /**
     * Um mantenedor inclui outro na OS (sem tirar ninguém): o incluído passa a ver a OS em "Minhas OS". Só mantenedor
     * ATIVO que atende a área executante da OS (ou já estava nela); o histórico registra quem incluiu.
     */
    public function adicionarMantenedor(OrdemServico $os, Mantenedor $mantenedor, Usuario $usuario, string $origem = 'portal'): void
    {
        if ($os->mantenedores()->where('os_mantenedores.id', $mantenedor->id)->exists()) {
            throw ValidationException::withMessages(['mantenedor_id' => $mantenedor->nomeExibicao().' já está nesta OS.']);
        }
        if (! $mantenedor->ativo || ! AcessoOs::mantenedoresDaArea($os)->contains('id', (int) $mantenedor->id)) {
            throw ValidationException::withMessages(['mantenedor_id' => 'Escolha um mantenedor ativo que atende a área executante desta OS.']);
        }
        $os->incluirMantenedor((int) $mantenedor->id);
        $this->registrar($os, null, 'distribuida', $os->status, $os->status,
            'Mantenedor '.$mantenedor->nomeExibicao().' incluído por '.$usuario->nome.($origem === 'aplicativo' ? ' (aplicativo).' : '.'), $usuario);
    }

    /** Só SS aguardando análise pode ser revisada/aprovada. */
    public function exigirPendente(SolicitacaoServico $ss): void
    {
        $this->exigirStatus($ss->status, ['pendente'], 'Esta solicitação já foi analisada.');
    }

    /**
     * SS aprovada: grava quem aprovou e cria a OS com todos os dados da SS, atribuída aos mantenedores escolhidos (vazio =
     * definir depois). Se quem aprova é um mantenedor, ele fica sempre atribuído à OS (é o primeiro, o principal).
     *
     * @param  list<int>  $mantenedorIds
     */
    public function aprovar(SolicitacaoServico $ss, Usuario $usuario, array $mantenedorIds = [], ?string $obs = null): OrdemServico
    {
        $this->exigirStatus($ss->status, ['pendente'], 'Esta solicitação já foi analisada.');

        $proprio = AcessoOs::mantenedorDo($usuario);
        $mantenedorIds = array_values(array_unique(array_map('intval', array_filter($mantenedorIds))));
        if ($proprio) {
            $mantenedorIds = array_values(array_unique([$proprio->id, ...$mantenedorIds]));
        }

        return DB::transaction(function () use ($ss, $usuario, $mantenedorIds, $obs) {
            // Corrida (aprovar no portal × assumir no app ao mesmo tempo): relê a SS travada; quem chegar depois recebe
            // a mensagem em vez de erro de chave duplicada.
            $status = SolicitacaoServico::withoutGlobalScopes()->whereKey($ss->id)->lockForUpdate()->value('status');
            if ($status !== 'pendente' || OrdemServico::withoutGlobalScopes()->where('solicitacao_id', $ss->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'Esta solicitação acabou de ser analisada por outra pessoa.']);
            }
            $ss->forceFill(['status' => 'aprovada', 'analisado_por_id' => $usuario->id, 'analisado_em' => now()])->save();
            $os = OrdemServico::create([
                'unidade' => $ss->unidade,
                'solicitacao_id' => $ss->id,
                'tipo' => $ss->tipo,
                'maquina_id' => $ss->maquina_id,
                'ferramenta_id' => $ss->ferramenta_id,
                'item_ti_id' => $ss->item_ti_id,
                'area_executante_id' => $ss->area_executante_id,
                'centro_custo' => $ss->centro_custo,
                'centro_custo_solicitante' => $ss->centro_custo_solicitante,
                'prioridade' => $ss->prioridade,
                'maquina_parada' => $ss->maquina_parada,
                'risco_integridade' => (bool) $ss->risco_integridade,
                'automatica' => (bool) $ss->automatica,
                'origem_iot' => $ss->origem_iot,
                'titulo' => $ss->titulo,
                'descricao' => $ss->descricao,
                'solicitante_id' => $ss->solicitante_id,
                'aprovado_por_id' => $usuario->id,
                'responsavel_id' => $mantenedorIds[0] ?? null,
                'status' => 'aberta',
                'ordem_producao_id' => $ss->ordem_producao_id,
                'apontamento_estado_id' => $ss->apontamento_estado_id,
            ]);
            $os->atribuirMantenedores($mantenedorIds);
            $nomes = $os->mantenedores()->get()->map->nomeExibicao()->join(', ');
            $this->registrar($os, $ss, 'aprovada', 'pendente', 'aberta', trim(($nomes !== '' ? "Mantenedores: {$nomes}. " : 'Mantenedores: definir depois. ').($obs ?? '')), $usuario);

            return $os;
        });
    }

    public function rejeitar(SolicitacaoServico $ss, Usuario $usuario, string $motivo): void
    {
        $this->exigirStatus($ss->status, ['pendente'], 'Esta solicitação já foi analisada.');
        $ss->forceFill(['status' => 'rejeitada', 'analisado_por_id' => $usuario->id, 'analisado_em' => now(), 'motivo_rejeicao' => $motivo])->save();
        $this->registrar(null, $ss, 'rejeitada', 'pendente', 'rejeitada', $motivo, $usuario);
    }

    public function cancelarSolicitacao(SolicitacaoServico $ss, Usuario $usuario, ?string $obs = null): void
    {
        $this->exigirStatus($ss->status, ['pendente'], 'Só dá para cancelar uma solicitação que ainda não foi analisada.');
        $ss->forceFill(['status' => 'cancelada'])->save();
        $this->registrar(null, $ss, 'cancelada', 'pendente', 'cancelada', $obs, $usuario);
    }

    /** Ações possíveis no status atual da OS. @return array<string, string> acao => rótulo */
    public static function acoesPossiveis(OrdemServico $os): array
    {
        $acoes = [];
        foreach (self::ACOES as $acao => [$de, , $rotulo]) {
            // "Iniciar" não é botão: a OS entra em atendimento sozinha no primeiro apontamento de horas.
            if ($acao !== 'iniciar' && in_array($os->status, $de, true)) {
                $acoes[$acao] = $rotulo;
            }
        }

        return $acoes;
    }

    public function executarAcao(OrdemServico $os, string $acao, Usuario $usuario, ?string $obs = null): void
    {
        if (! isset(self::ACOES[$acao])) {
            throw ValidationException::withMessages(['acao' => 'Ação desconhecida.']);
        }
        [$de, $para] = self::ACOES[$acao];
        if ($acao === 'reabrir' && $os->status === 'encerrada') {
            throw ValidationException::withMessages(['acao' => 'Esta OS já foi avaliada pelo solicitante e está encerrada: não pode ser reaberta. Se o problema voltou, abra uma nova solicitação.']);
        }
        $this->exigirStatus($os->status, $de, 'Esta ação não vale para uma OS '.mb_strtolower(OrdemServico::STATUS[$os->status] ?? $os->status).'.');
        // Toda OS concluída tem de ter a área executante que atendeu (cronômetro, app ou botão da tela).
        if ($acao === 'concluir' && ! $os->area_executante_id) {
            throw ValidationException::withMessages(['area_executante_id' => 'Informe a área executante que atendeu para concluir a OS.']);
        }
        if ($acao === 'cancelar' && trim((string) $obs) === '') {
            throw ValidationException::withMessages(['observacao' => 'Informe o motivo do cancelamento.']);
        }
        // Concluir só com o checklist (preventiva) todo respondido.
        if ($acao === 'concluir' && ($faltam = $os->checklist()->whereNull('resposta')->count()) > 0) {
            throw ValidationException::withMessages(['checklist' => "Responda os {$faltam} itens do checklist antes de concluir a OS."]);
        }

        DB::transaction(function () use ($os, $acao, $para, $usuario, $obs) {
            $anterior = $os->status;
            $dados = ['status' => $para];
            if ($para === 'em_execucao' && $os->iniciada_em === null) {
                $dados['iniciada_em'] = now();
            }
            if (in_array($acao, ['concluir', 'cancelar'], true)) {
                // Encerra os cronômetros ainda rodando (de todos os mantenedores), cada um com a descrição dele;
                // cancelar também encerra, senão o mantenedor ficaria preso a um apontamento aberto numa OS fechada.
                foreach ($os->apontamentos()->whereNull('fim')->get() as $ap) {
                    $this->encerrarApontamento($ap, $ap->descricao ?: ($acao === 'cancelar' ? 'Encerrado no cancelamento da OS.' : 'Encerrado na conclusão da OS.'));
                }
            }
            if ($acao === 'concluir') {
                $ultimo = $os->apontamentos()->max('fim');
                $dados['concluida_em'] = $ultimo ?? now();
                if ($obs !== null && trim($obs) !== '' && trim((string) $os->solucao) === '') {
                    $dados['solucao'] = mb_substr($obs, 0, 2000);
                }
            }
            if ($acao === 'cancelar') {
                $dados['cancelamento_motivo'] = mb_substr((string) $obs, 0, 500);
            }
            if ($acao === 'reabrir') {
                $dados += ['concluida_em' => null, 'aceite_por_id' => null, 'aceite_em' => null, 'avaliacao' => null];
            }
            $os->forceFill($dados)->save();
            $this->registrar($os, null, $acao, $anterior, $para, $obs, $usuario);
        });
    }

    /**
     * Avaliação do solicitante quando a OS é concluída (vale como a assinatura eletrônica dele):
     * Excelente ou Boa → OS encerrada; Ruim → a OS volta para execução e o motivo é obrigatório. Tudo vai para o histórico.
     */
    public function avaliar(OrdemServico $os, Usuario $usuario, string $avaliacao, ?string $obs = null): void
    {
        $this->exigirStatus($os->status, ['concluida'], 'Só dá para avaliar uma OS concluída.');
        if (! isset(OrdemServico::AVALIACOES[$avaliacao])) {
            throw ValidationException::withMessages(['avaliacao' => 'Escolha Excelente, Boa ou Ruim.']);
        }
        $obs = trim((string) $obs) !== '' ? mb_substr(trim($obs), 0, 500) : null;
        if ($avaliacao === 'ruim' && $obs === null) {
            throw ValidationException::withMessages(['observacao' => 'Na avaliação Ruim, conte o motivo: a OS volta para a manutenção.']);
        }
        $rotulo = OrdemServico::AVALIACOES[$avaliacao];

        DB::transaction(function () use ($os, $usuario, $avaliacao, $obs, $rotulo) {
            $dados = ['avaliacao' => $avaliacao, 'aceite_por_id' => $usuario->id, 'aceite_em' => now(), 'aceite_observacao' => $obs];
            if ($avaliacao === 'ruim') {
                $os->forceFill($dados + ['status' => 'em_execucao', 'concluida_em' => null])->save();
                $this->registrar($os, null, 'avaliada_ruim', 'concluida', 'em_execucao', "Avaliação: {$rotulo}. Motivo: {$obs}", $usuario);
            } else {
                $os->forceFill($dados + ['status' => 'encerrada'])->save();
                $this->registrar($os, null, 'avaliada', 'concluida', 'encerrada', "Avaliação: {$rotulo}.".($obs ? " {$obs}" : ''), $usuario);
            }
        });
    }

    /** Começa a contar as horas do mantenedor na OS (um apontamento aberto por mantenedor). */
    public function iniciarApontamento(OrdemServico $os, Mantenedor $mantenedor, Usuario $usuario): ApontamentoHoras
    {
        if (in_array($os->status, ['concluida', 'encerrada', 'cancelada'], true)) {
            throw ValidationException::withMessages(['os' => 'Esta OS não está em aberto.']);
        }
        $aberto = ApontamentoHoras::where('mantenedor_id', $mantenedor->id)->whereNull('fim')->first();
        if ($aberto) {
            throw ValidationException::withMessages(['os' => "{$mantenedor->nome} já está com um apontamento rodando na OS ".str_pad((string) $aberto->ordem_servico_id, 6, '0', STR_PAD_LEFT).'. Encerre antes de começar outro.']);
        }

        return DB::transaction(function () use ($os, $mantenedor, $usuario) {
            $ap = ApontamentoHoras::create(['ordem_servico_id' => $os->id, 'mantenedor_id' => $mantenedor->id, 'inicio' => now()->startOfMinute()]);
            if ($os->status === 'aberta') {
                $this->executarAcao($os, 'iniciar', $usuario, 'Início do apontamento de horas');
            }
            $os->incluirMantenedor($mantenedor->id);

            return $ap;
        });
    }

    /**
     * Encerra o apontamento (agora), separa normal × extra pela jornada e grava a descrição do serviço. Cronômetro
     * esquecido: no máximo config('oee.cronometro_max_horas') horas (padrão 12) — o resto precisa ser lançado à mão.
     */
    public function encerrarApontamento(ApontamentoHoras $ap, ?string $descricao = null): void
    {
        $fim = now()->startOfMinute();
        if ($fim < $ap->inicio) {
            $fim = $ap->inicio;
        }
        $maximo = max(1, (int) config('oee.cronometro_max_horas', 12));
        if ($ap->inicio->copy()->addHours($maximo)->lt($fim)) {
            $fim = $ap->inicio->copy()->addHours($maximo);
            $descricao = trim(($descricao ?? $ap->descricao ?? '').' [cronômetro encerrado no limite de '.$maximo.' h: lance o restante à mão, se houver]');
        }
        $this->gravarApontamento($ap, $ap->inicio, $fim, $descricao ?? $ap->descricao);
    }

    /** Grava início/fim (e calcula os minutos pela jornada, se não vierem informados) e atualiza o total da OS. */
    public function gravarApontamento(ApontamentoHoras $ap, $inicio, $fim, ?string $descricao, ?int $normais = null, ?int $extras = null): void
    {
        $ap->inicio = $inicio;
        $ap->fim = $fim;
        $ap->descricao = $descricao;
        if ($fim !== null) {
            if ($normais === null || $extras === null) {
                $mantenedor = $ap->mantenedor ?? Mantenedor::withoutGlobalScopes()->find($ap->mantenedor_id);
                ['normais' => $normais, 'extras' => $extras] = HorasManutencao::de($mantenedor)->dividir($ap->inicio, $ap->fim);
            }
            $ap->minutos_normais = $normais;
            $ap->minutos_extras = $extras;
        } else {
            $ap->minutos_normais = null;
            $ap->minutos_extras = null;
        }
        $ap->save();
        $this->recalcularTotal($ap->ordem_servico_id);
    }

    public function recalcularTotal(int $osId): void
    {
        $total = (int) ApontamentoHoras::where('ordem_servico_id', $osId)->whereNotNull('fim')
            ->sum(DB::raw('COALESCE(minutos_normais, 0) + COALESCE(minutos_extras, 0)'));
        OrdemServico::whereKey($osId)->update(['total_minutos' => $total]);
    }

    private function exigirStatus(string $atual, array $permitidos, string $mensagem): void
    {
        if (! in_array($atual, $permitidos, true)) {
            throw ValidationException::withMessages(['status' => $mensagem]);
        }
    }
}
