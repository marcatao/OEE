<?php

namespace App\Console\Commands;

use App\Models\Legenda;
use App\Services\StatusOrdemProducao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Troca TODAS as legendas pelas legendas da Alumbra (lista abaixo). Pedido e autorizado pelo usuário (12/11/2026):
 * apaga as legendas antigas E os apontamentos reais que usam elas (o histórico da timeline e o OEE desses apontamentos
 * se perdem). Solicitações e ordens de serviço que apontavam para um desses apontamentos continuam existindo; só
 * perdem o vínculo com a parada. As OPs que tinham apontamentos têm o status recalculado.
 *
 * Sem --confirmar só mostra o que seria feito.
 */
class SubstituirLegendas extends Command
{
    protected $signature = 'legendas:substituir {--confirmar : Apaga e recria de verdade (sem isto, só mostra o que seria feito)}';

    protected $description = 'Apaga todas as legendas e os apontamentos reais delas e cria as legendas da Alumbra';

    /** codigo => [descrição, tipo, cor]. Tipos: produzindo | parada (não planejada) | planejada | desligada. */
    public const LEGENDAS = [
        '1' => ['Limpeza', 'planejada', '#26a69a'],
        '2' => ['Produção Normal', 'produzindo', '#43a047'],
        '3' => ['Manutenção', 'parada', '#e53935'],
        '4' => ['Falta de Mat. Fábrica', 'parada', '#fb8c00'],
        '5' => ['Ajuste de Máquina', 'parada', '#f4511e'],
        '6' => ['Teste', 'planejada', '#8e24aa'],
        '7' => ['Falta de Operadora', 'parada', '#fdd835'],
        '8' => ['Falta Material no Setor', 'parada', '#ffb74d'],
        '9' => ['Ferramentaria', 'parada', '#6d4c41'],
        '10' => ['Setup', 'planejada', '#1e88e5'],
        '11' => ['Modificação de Molde', 'planejada', '#5e35b1'],
        '12' => ['Falta de Preparador', 'parada', '#f9a825'],
        '13' => ['Falta de Energia', 'parada', '#424242'],
        '14' => ['Falta Estufar Material', 'parada', '#ff7043'],
        '15' => ['Controle Qualidade', 'planejada', '#00acc1'],
        '16' => ['Refeição', 'planejada', '#90a4ae'],
        '18' => ['Partida/Inicialização', 'planejada', '#7cb342'],
        '21' => ['CQ Material NC', 'parada', '#d81b60'],
        '27' => ['Reunião CQ/Treinamento', 'planejada', '#3949ab'],
        '28' => ['Falta Abastecer Injetora', 'parada', '#ef6c00'],
        '29' => ['Falta Trocar o Molde', 'parada', '#c62828'],
    ];

    public function handle(StatusOrdemProducao $status): int
    {
        $legendas = DB::table('legendas')->count();
        $apontamentos = DB::table('apontamentos_estado')->count();
        $vinculos = [];
        foreach (['solicitacoes_servico', 'ordens_servico'] as $tabela) {
            if (DB::getSchemaBuilder()->hasTable($tabela)) {
                $vinculos[$tabela] = DB::table($tabela)->whereNotNull('apontamento_estado_id')->count();
            }
        }

        $this->line("Legendas atuais: {$legendas} (serão APAGADAS).");
        $this->line("Apontamentos reais: {$apontamentos} (serão APAGADOS; o histórico da timeline e o OEE deles se perdem).");
        foreach ($vinculos as $tabela => $qtd) {
            $this->line("{$tabela} ligadas a um apontamento: {$qtd} (continuam existindo, só perdem o vínculo com a parada).");
        }
        $this->line('Legendas novas: '.count(self::LEGENDAS).'.');

        if (! $this->option('confirmar')) {
            $this->warn('Nada foi alterado. Para fazer de verdade: php artisan legendas:substituir --confirmar');

            return self::SUCCESS;
        }

        $ops = DB::table('apontamentos_estado')->whereNotNull('ordem_producao_id')->distinct()->pluck('ordem_producao_id')->all();
        DB::transaction(function () use ($vinculos) {
            foreach (array_keys($vinculos) as $tabela) {
                DB::table($tabela)->whereNotNull('apontamento_estado_id')->update(['apontamento_estado_id' => null]);
            }
            DB::table('apontamentos_estado')->delete();
            // A máquina guarda a legenda que deu a situação atual: solta o vínculo (a situação e a OP continuam).
            if (DB::getSchemaBuilder()->hasColumn('maquinas', 'situacao_legenda_id')) {
                DB::table('maquinas')->whereNotNull('situacao_legenda_id')->update(['situacao_legenda_id' => null]);
            }
            DB::table('legendas')->delete();
            foreach (self::LEGENDAS as $codigo => [$descricao, $tipo, $cor]) {
                Legenda::create(['codigo' => (string) $codigo, 'descricao' => $descricao, 'tipo' => $tipo, 'cor' => $cor,
                    'ordem' => (int) $codigo, 'ativo' => true, 'unidade' => 'manutencao']);
            }
        });
        $status->atualizarLote($ops); // OPs que tinham apontamento voltam ao status só pelo Protheus

        $this->info('Pronto: '.count(self::LEGENDAS)." legendas criadas; {$legendas} legendas e {$apontamentos} apontamentos apagados.");

        return self::SUCCESS;
    }
}
