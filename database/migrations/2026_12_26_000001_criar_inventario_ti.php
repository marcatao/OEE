<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventário de Tecnologia (pedido do usuário em 25/11/2026): equipamentos (hardware), licenças de software e linhas/chips
 * de celular, cadastrados só no portal. Histórico de movimentação (responsável, local, centro de custo, situação),
 * atribuições das licenças, termo de responsabilidade com assinatura no portal e chamado (SS/OS) para um item.
 * Só ACRESCENTA: tabelas novas (os tipos padrão entram na tabela nova) e a coluna vazia item_ti_id nas SS e OS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ti_tipos', function (Blueprint $t) {
            $t->id();
            $t->string('unidade', 20)->default('tecnologia');
            $t->string('categoria', 20);                       // hardware | licenca | linha
            $t->string('nome', 60);
            $t->boolean('ativo')->default(true);
            $this->auditoria($t);
            $t->index(['categoria', 'ativo']);
        });

        Schema::create('ti_itens', function (Blueprint $t) {
            $t->id();
            $t->string('unidade', 20)->default('tecnologia');
            $t->string('categoria', 20);                       // hardware | licenca | linha (a do tipo)
            $t->foreignId('tipo_id')->constrained('ti_tipos');
            $t->string('patrimonio', 30)->nullable();          // plaqueta / código interno (único quando preenchido)
            $t->string('descricao', 150);
            $t->string('marca', 60)->nullable();
            $t->string('modelo', 80)->nullable();
            $t->string('numero_serie', 80)->nullable();        // na linha: ICCID do chip
            $t->string('hostname', 60)->nullable();
            $t->text('especificacao')->nullable();             // processador, memória, disco, sistema…
            $t->string('acessorios', 255)->nullable();         // carregador, mouse, capa… (vão no termo)
            $t->string('centro_custo', 20)->nullable();
            $t->string('local', 100)->nullable();
            $t->foreignId('responsavel_id')->nullable()->constrained('usuarios');
            $t->dateTime('responsavel_desde')->nullable();
            $t->string('situacao', 20)->default('estoque');    // em_uso | estoque | manutencao | baixado
            $t->date('data_compra')->nullable();
            $t->string('nota_fiscal', 30)->nullable();
            $t->string('fornecedor', 100)->nullable();
            $t->date('garantia_ate')->nullable();
            $t->decimal('valor', 14, 2)->nullable();
            // Licença de software
            $t->string('licenca_chave', 255)->nullable();      // chave, contrato ou conta
            $t->integer('quantidade_licencas')->nullable();
            $t->date('vencimento')->nullable();                // licença (assinatura) ou plano da linha
            // Linha / chip de celular
            $t->string('linha_numero', 20)->nullable();
            $t->string('operadora', 40)->nullable();
            $t->string('plano', 80)->nullable();
            $t->foreignId('vinculado_item_id')->nullable()->constrained('ti_itens'); // aparelho onde o chip está
            $t->string('observacao', 500)->nullable();
            $t->dateTime('baixado_em')->nullable();
            $t->string('motivo_baixa', 255)->nullable();
            $this->auditoria($t);
            $t->index(['unidade', 'situacao']);
            $t->index('tipo_id');
            $t->index('responsavel_id');
            $t->index('patrimonio');
            $t->index('vinculado_item_id');
        });

        Schema::create('ti_movimentacoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->constrained('ti_itens');
            $t->string('campo', 30);                           // cadastro | responsavel | local | centro_custo | situacao | vinculado | licenca
            $t->string('valor_anterior', 255)->nullable();
            $t->string('valor_novo', 255)->nullable();
            $t->string('observacao', 500)->nullable();
            $t->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $t->dateTime('criado_em')->nullable();
            $t->index(['item_id', 'criado_em']);
        });

        Schema::create('ti_licenca_atribuicoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('licenca_id')->constrained('ti_itens');
            $t->foreignId('item_id')->nullable()->constrained('ti_itens');      // computador onde está instalada
            $t->foreignId('usuario_id')->nullable()->constrained('usuarios');   // ou a pessoa que usa
            $t->dateTime('atribuida_em')->nullable();
            $t->foreignId('atribuida_por_id')->nullable()->constrained('usuarios');
            $t->dateTime('removida_em')->nullable();
            $t->foreignId('removida_por_id')->nullable()->constrained('usuarios');
            $t->string('observacao', 255)->nullable();
            $t->index(['licenca_id', 'removida_em']);
            $t->index('item_id');
            $t->index('usuario_id');
        });

        Schema::create('ti_termos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->constrained('ti_itens');
            $t->foreignId('usuario_id')->constrained('usuarios');               // responsável que assina
            $t->string('status', 20)->default('pendente');                       // pendente | assinado | cancelado
            $t->longText('conteudo');                                            // JSON: os dados do item no momento da entrega
            $t->foreignId('gerado_por_id')->nullable()->constrained('usuarios');
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('assinado_em')->nullable();
            $t->string('nome_assinante', 120)->nullable();
            $t->longText('imagem_png')->nullable();                              // assinatura, PNG em base64
            $t->foreignId('registrado_por_id')->nullable()->constrained('usuarios'); // quem estava logado ao assinar
            $t->boolean('presencial')->default(false);                           // assinado no aparelho do administrador
            $t->string('assinado_ip', 45)->nullable();
            $t->string('assinado_navegador', 255)->nullable();
            $t->dateTime('cancelado_em')->nullable();
            $t->string('motivo_cancelamento', 255)->nullable();
            $t->index(['usuario_id', 'status']);
            $t->index(['item_id', 'status']);
        });

        foreach (['solicitacoes_servico', 'ordens_servico'] as $tabela) {
            if (! Schema::hasColumn($tabela, 'item_ti_id')) {
                Schema::table($tabela, function (Blueprint $t) {
                    $t->foreignId('item_ti_id')->nullable()->constrained('ti_itens');
                    $t->index('item_ti_id');
                });
            }
        }

        // Tipos iniciais (tabela nova; o administrador acrescenta e desativa na tela Tipos de item).
        $agora = now();
        $tipos = [
            'hardware' => ['Computador (desktop)', 'Notebook', 'Monitor', 'Impressora', 'Celular', 'Tablet', 'Equipamento de rede',
                'Servidor', 'Nobreak', 'Periférico', 'Outro equipamento'],
            'licenca' => ['Licença de software'],
            'linha' => ['Linha / chip de celular'],
        ];
        foreach ($tipos as $categoria => $nomes) {
            foreach ($nomes as $nome) {
                DB::table('ti_tipos')->insert(['unidade' => 'tecnologia', 'categoria' => $categoria, 'nome' => $nome, 'ativo' => true,
                    'criado_em' => $agora, 'atualizado_em' => $agora]);
            }
        }
    }

    public function down(): void
    {
        foreach (['solicitacoes_servico', 'ordens_servico'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) {
                $t->dropForeign(['item_ti_id']);
                $t->dropIndex(['item_ti_id']);
                $t->dropColumn('item_ti_id');
            });
        }
        foreach (['ti_termos', 'ti_licenca_atribuicoes', 'ti_movimentacoes', 'ti_itens', 'ti_tipos'] as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }

    private function auditoria(Blueprint $t): void
    {
        $t->dateTime('criado_em')->nullable();
        $t->foreignId('criado_por_id')->nullable()->constrained('usuarios');
        $t->dateTime('atualizado_em')->nullable();
        $t->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
    }
};
