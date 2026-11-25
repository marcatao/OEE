<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\InventarioTi\AtribuicaoLicenca;
use App\Models\InventarioTi\DescricaoItemTi;
use App\Models\InventarioTi\ItemTi;
use App\Models\InventarioTi\TermoResponsabilidade;
use App\Models\InventarioTi\TipoItemTi;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Usuario;
use App\Services\Manutencao\FluxoOrdemServico;
use App\Support\AcessoOs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inventário de TI: cadastro e histórico, termo de responsabilidade com assinatura no portal, licenças, escopo de
 * unidade e chamado (SS/OS) para um item.
 */
class InventarioTiTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $admin;

    private Usuario $ana;

    private TipoItemTi $notebook;

    protected function setUp(): void
    {
        parent::setUp();
        AcessoOs::limparCache();
        CentroCusto::create(['ctt_custo' => '3003', 'ctt_desc01' => 'TI']);
        $this->admin = $this->usuario(['admin_tecnologia'], 'admti@empresa.com');
        $this->ana = $this->usuario([], 'ana@empresa.com'); // sem perfil: recebe o notebook e assina mesmo assim
        $this->notebook = TipoItemTi::withoutGlobalScopes()->where('nome', 'Notebook')->firstOrFail();
    }

    private function usuario(array $perfis, string $email): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => ucfirst(strtok($email, '@')), 'email' => $email]);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(40, 20);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /** Id da descrição padrão com este texto (cadastra se não existir). */
    private function desc(string $texto): int
    {
        return DescricaoItemTi::withoutGlobalScopes()->firstOrCreate(['descricao' => $texto], ['unidade' => 'tecnologia'])->id;
    }

    private function qualificacao(): array
    {
        return ['cargo' => 'Vendedora', 'nacionalidade' => 'Brasileira', 'rg' => '12.345.678-9', 'cpf' => '529.982.247-25',
            'endereco' => 'Rua das Flores, 10 – Centro – São Bernardo do Campo/SP – 09700-000'];
    }

    private function cadastrar(array $dados = []): ItemTi
    {
        $this->actingAs($this->admin)->post('/tecnologia/inventario', $dados + [
            'unidade' => 'tecnologia', 'tipo_id' => $this->notebook->id, 'patrimonio' => 'ti-0001', 'descricao_id' => $this->desc('Notebook do comercial'),
            'marca' => 'Dell', 'modelo' => 'Latitude 5440', 'numero_serie' => 'ABC123', 'acessorios' => 'Carregador e mochila',
            'situacao' => 'em_uso', 'responsavel_id' => $this->ana->id, 'centro_custo' => '3003', 'garantia_ate' => '2028-01-31',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return ItemTi::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    public function test_cadastro_gera_historico_e_termo_que_o_responsavel_assina_pelo_portal(): void
    {
        $item = $this->cadastrar();
        $this->assertSame(['TI-0001', 'hardware', 'em_uso'], [$item->patrimonio, $item->categoria, $item->situacao]);
        $this->assertSame(['cadastro', 'responsavel', 'centro_custo'], $item->movimentacoes()->orderBy('id')->pluck('campo')->take(3)->all());
        $termo = TermoResponsabilidade::where('item_id', $item->id)->firstOrFail();
        $this->assertSame(['pendente', $this->ana->id, 'ABC123'], [$termo->status, (int) $termo->usuario_id, $termo->conteudo['numero_serie']]);
        $this->get("/tecnologia/inventario/{$item->id}")->assertOk()->assertSee('Notebook do comercial')->assertSee($termo->numeroFormatado());

        // A Ana (sem perfil) vê o aviso, os itens dela e assina com o dedo.
        $this->actingAs($this->ana)->get('/tecnologia/meus-equipamentos')->assertOk()->assertSee('TI-0001')->assertSee('Ler e assinar');
        // Contrato de comodato no modelo da empresa: notebook não leva as cláusulas de telefonia (chip, ligações).
        $this->get("/tecnologia/termos/{$termo->id}")->assertOk()->assertSee('CONTRATO PARTICULAR DE COMODATO')
            ->assertSee('01 NOTEBOOK DO COMERCIAL DELL LATITUDE 5440 PATRIMÔNIO: TI-0001 S/N: ABC123 e carregador e mochila em ótimas condições.')
            ->assertSee('É vedado seu empréstimo a terceiros.')->assertDontSee('remover o chip')->assertSee('data-assinatura', false);
        $q = $this->qualificacao();
        $this->post("/tecnologia/termos/{$termo->id}/assinar", ['assinatura' => 'nao-e-png', 'aceite' => '1'] + $q)->assertSessionHasErrors('assinatura');
        $this->post("/tecnologia/termos/{$termo->id}/assinar", ['assinatura' => $this->png()] + $q)->assertSessionHasErrors('aceite');
        $this->post("/tecnologia/termos/{$termo->id}/assinar", ['assinatura' => $this->png(), 'aceite' => '1', 'cpf' => '111.111.111-11'] + $q)->assertSessionHasErrors('cpf');
        $this->post("/tecnologia/termos/{$termo->id}/assinar", ['assinatura' => $this->png(), 'aceite' => '1', 'rg' => ''] + $q)->assertSessionHasErrors('rg');
        $this->post("/tecnologia/termos/{$termo->id}/assinar", ['assinatura' => $this->png(), 'aceite' => '1', 'cpf' => '52998224725'] + $q)->assertRedirect()->assertSessionHasNoErrors();
        $termo->refresh();
        $this->assertSame(['assinado', false, 'Ana'], [$termo->status, $termo->presencial, $termo->nome_assinante]);
        $this->assertSame('529.982.247-25', $termo->conteudo['comodatario']['cpf']);
        $this->get("/tecnologia/termos/{$termo->id}")->assertOk()->assertSee('Assinado eletronicamente')->assertSee('529.982.247-25')
            ->assertSee('Rua das Flores, 10');
        // Assinado não assina de novo.
        $this->post("/tecnologia/termos/{$termo->id}/assinar", ['assinatura' => $this->png(), 'aceite' => '1'] + $q)->assertForbidden();

        // Outro usuário (sem o inventário) não abre o termo da Ana; nem o inventário.
        $this->actingAs($this->usuario(['operador'], 'op@empresa.com'))->get("/tecnologia/termos/{$termo->id}")->assertNotFound();
        $this->get('/tecnologia/inventario')->assertForbidden();
    }

    public function test_trocar_responsavel_cancela_o_termo_pendente_e_gera_outro_e_baixa_tira_o_responsavel(): void
    {
        $item = $this->cadastrar();
        $bruno = $this->usuario([], 'bruno@empresa.com');
        $base = ['unidade' => 'tecnologia', 'tipo_id' => $this->notebook->id, 'patrimonio' => $item->patrimonio, 'descricao_id' => $this->desc($item->descricao), 'situacao' => 'em_uso',
            'local' => 'Comercial', 'observacao_movimentacao' => 'Ana saiu de férias'];
        $this->actingAs($this->admin)->put("/tecnologia/inventario/{$item->id}", $base + ['responsavel_id' => $bruno->id])->assertSessionHasNoErrors();

        $termos = TermoResponsabilidade::where('item_id', $item->id)->orderBy('id')->get();
        $this->assertSame(['cancelado', 'pendente'], $termos->pluck('status')->all());
        $this->assertSame($bruno->id, (int) $termos[1]->usuario_id);
        $mov = $item->movimentacoes()->where('campo', 'responsavel')->latest('id')->first();
        $this->assertSame(['Ana', 'Bruno', 'Ana saiu de férias'], [$mov->valor_anterior, $mov->valor_novo, $mov->observacao]);

        // O administrador colhe a assinatura do Bruno na hora (presencial).
        $this->post("/tecnologia/termos/{$termos[1]->id}/assinar", ['assinatura' => $this->png(), 'aceite' => '1', 'nome' => 'Bruno Souza'] + $this->qualificacao())
            ->assertSessionHasErrors('confirmo_presencial'); // presencial exige confirmar quem assina
        $this->post("/tecnologia/termos/{$termos[1]->id}/assinar", ['assinatura' => $this->png(), 'aceite' => '1', 'nome' => 'Bruno Souza', 'confirmo_presencial' => '1'] + $this->qualificacao())->assertSessionHasNoErrors();
        $this->assertSame([true, 'Bruno Souza', $this->admin->id], [$termos[1]->fresh()->presencial, $termos[1]->fresh()->nome_assinante, (int) $termos[1]->fresh()->registrado_por_id]);

        // Baixa exige motivo, tira o responsável e não apaga nada.
        $this->put("/tecnologia/inventario/{$item->id}", ['situacao' => 'baixado', 'responsavel_id' => $bruno->id] + $base)->assertSessionHasErrors('motivo_baixa');
        $this->put("/tecnologia/inventario/{$item->id}", ['situacao' => 'baixado', 'motivo_baixa' => 'Tela quebrada'] + $base)->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame(['baixado', null], [$item->situacao, $item->responsavel_id]);
        $this->assertNotNull($item->baixado_em);
        $this->get('/tecnologia/inventario')->assertOk()->assertDontSee('<strong>TI-0001</strong>', false);
        $this->get('/tecnologia/inventario?situacao=baixado')->assertOk()->assertSee('TI-0001');
    }

    public function test_validacoes_do_cadastro(): void
    {
        $this->cadastrar();
        $this->actingAs($this->admin)->post('/tecnologia/inventario', ['unidade' => 'tecnologia', 'tipo_id' => $this->notebook->id,
            'patrimonio' => 'TI-0001', 'descricao_id' => $this->desc('Outro'), 'situacao' => 'estoque'])->assertSessionHasErrors('patrimonio');
        $this->post('/tecnologia/inventario', ['unidade' => 'tecnologia', 'tipo_id' => $this->notebook->id, 'descricao_id' => $this->desc('Sem dono'),
            'situacao' => 'em_uso'])->assertSessionHasErrors('responsavel_id');
        $this->post('/tecnologia/inventario', ['unidade' => 'manutencao', 'tipo_id' => $this->notebook->id, 'descricao_id' => $this->desc('Outra unidade'),
            'situacao' => 'estoque'])->assertSessionHasErrors('unidade');
    }

    public function test_licenca_respeita_a_quantidade_e_linha_fica_no_aparelho(): void
    {
        $note = $this->cadastrar(['responsavel_id' => null, 'situacao' => 'estoque']);
        $tipoLicenca = TipoItemTi::withoutGlobalScopes()->where('categoria', 'licenca')->firstOrFail();
        $this->actingAs($this->admin)->post('/tecnologia/inventario', ['unidade' => 'tecnologia', 'tipo_id' => $tipoLicenca->id,
            'descricao_id' => $this->desc('Office 365'), 'situacao' => 'em_uso', 'quantidade_licencas' => 1, 'licenca_chave' => 'XXXX', 'hostname' => 'nao-vale'])
            ->assertSessionHasNoErrors();
        $licenca = ItemTi::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(['licenca', null], [$licenca->categoria, $licenca->hostname]);
        $this->assertSame(0, TermoResponsabilidade::where('item_id', $licenca->id)->count());

        $this->post("/tecnologia/inventario/{$licenca->id}/licencas", ['item_id' => $note->id])->assertSessionHasNoErrors();
        $this->post("/tecnologia/inventario/{$licenca->id}/licencas", ['usuario_id' => $this->ana->id])->assertSessionHasErrors('item_id');
        $a = AtribuicaoLicenca::firstOrFail();
        $this->get("/tecnologia/inventario/{$note->id}")->assertOk()->assertSee('Office 365');
        $this->post("/tecnologia/inventario/{$licenca->id}/licencas/{$a->id}/remover")->assertSessionHasNoErrors();
        $this->post("/tecnologia/inventario/{$licenca->id}/licencas", ['usuario_id' => $this->ana->id])->assertSessionHasNoErrors();
        $this->actingAs($this->ana)->get('/tecnologia/meus-equipamentos')->assertOk()->assertSee('Office 365');

        $tipoLinha = TipoItemTi::withoutGlobalScopes()->where('categoria', 'linha')->firstOrFail();
        $this->actingAs($this->admin)->post('/tecnologia/inventario', ['unidade' => 'tecnologia', 'tipo_id' => $tipoLinha->id,
            'descricao_id' => $this->desc('Linha do supervisor'), 'situacao' => 'em_uso', 'linha_numero' => '11999990000', 'operadora' => 'Vivo',
            'vinculado_item_id' => $note->id, 'responsavel_id' => $this->ana->id])->assertSessionHasNoErrors();
        $linha = ItemTi::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame($note->id, (int) $linha->vinculado_item_id);
        $termoLinha = TermoResponsabilidade::where('item_id', $linha->id)->pendentes()->firstOrFail();
        $this->actingAs($this->ana)->get("/tecnologia/termos/{$termoLinha->id}")->assertOk()->assertSee('remover o chip')
            ->assertSee('LINHA: 11999990000 (Vivo)');
        // Já assinou antes: o formulário vem com os dados dela.
        $this->post("/tecnologia/termos/{$termoLinha->id}/assinar", ['assinatura' => $this->png(), 'aceite' => '1'] + $this->qualificacao())->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/tecnologia/inventario/{$linha->id}/termo")->assertRedirect();
        $novo = TermoResponsabilidade::where('item_id', $linha->id)->pendentes()->firstOrFail();
        $this->actingAs($this->ana)->get("/tecnologia/termos/{$novo->id}")->assertOk()->assertSee('value="529.982.247-25"', false);
    }

    public function test_escopo_de_unidade_e_quem_edita(): void
    {
        $item = $this->cadastrar();
        $mantenedor = $this->usuario(['mantenedor_tecnologia'], 'mant@empresa.com');
        $this->actingAs($mantenedor)->get('/tecnologia/inventario')->assertOk()->assertSee('TI-0001')->assertDontSee('Novo item');
        $this->get("/tecnologia/inventario/{$item->id}")->assertOk()->assertDontSee('Editar / movimentar');
        $this->get("/tecnologia/inventario/{$item->id}/editar")->assertForbidden();

        // Administrador de Produção abre a tela (permissões '*'), mas não vê nem altera o item da Tecnologia.
        $adminProducao = $this->usuario(['admin_producao'], 'admprod@empresa.com');
        $this->actingAs($adminProducao)->get('/tecnologia/inventario')->assertOk()->assertDontSee('TI-0001');
        $this->get("/tecnologia/inventario/{$item->id}/editar")->assertNotFound();
    }

    public function test_chamado_para_item_do_inventario_na_tecnologia(): void
    {
        $item = $this->cadastrar();
        $suporte = AreaExecutante::create(['codigo' => 'TI', 'nome' => 'Suporte de TI', 'unidade' => 'tecnologia']);
        $mecanica = AreaExecutante::create(['codigo' => 'MEC', 'nome' => 'Mecânica', 'unidade' => 'manutencao']);
        $sol = $this->usuario(['solicitante_tecnologia', 'solicitante_manutencao'], 'sol@empresa.com');
        $this->actingAs($sol)->get("/ordens-de-servico/solicitacoes/nova?item_ti={$item->id}")->assertOk()
            ->assertSee('Item do inventário de TI')->assertSee('TI-0001');

        $base = ['unidade' => 'tecnologia', 'titulo' => 'Notebook não liga', 'tipo' => 'corretiva', 'area_executante_id' => $suporte->id,
            'prioridade' => 'alta', 'descricao' => 'Não liga desde ontem'];
        $this->post('/ordens-de-servico/solicitacoes', $base + ['item_ti_id' => $item->id])->assertSessionHasNoErrors();
        $ss = SolicitacaoServico::latest('id')->firstOrFail();
        $this->assertSame([$item->id, '3003'], [(int) $ss->item_ti_id, trim($ss->centro_custo)]); // CC do item
        $this->get("/ordens-de-servico/solicitacoes/{$ss->id}")->assertOk()->assertSee('Inventário de TI')->assertSee('TI-0001');

        // Na Manutenção não aceita item de TI.
        $this->post('/ordens-de-servico/solicitacoes', ['unidade' => 'manutencao', 'area_executante_id' => $mecanica->id, 'item_ti_id' => $item->id,
            'centro_custo' => '3003'] + $base)->assertSessionHasErrors('item_ti_id');

        $os = app(FluxoOrdemServico::class)->aprovar($ss->fresh(), $this->admin);
        $this->assertSame($item->id, (int) $os->item_ti_id);
        $this->actingAs($this->admin)->get("/tecnologia/inventario/{$item->id}")->assertOk()->assertSee($ss->numeroFormatado())->assertSee($os->numeroFormatado());
    }

    public function test_tipos_de_item(): void
    {
        $this->actingAs($this->admin)->get('/tecnologia/inventario/tipos')->assertOk()->assertSee('Notebook');
        $this->post('/tecnologia/inventario/tipos', ['categoria' => 'hardware', 'nome' => 'Leitor de código de barras', 'ativo' => 1, 'unidade' => 'tecnologia'])
            ->assertSessionHasNoErrors();
        $t = TipoItemTi::latest('id')->firstOrFail();
        $this->put("/tecnologia/inventario/tipos/{$t->id}", ['categoria' => 'licenca', 'nome' => 'X', 'ativo' => 1])->assertSessionHasErrors('categoria');
        $this->put("/tecnologia/inventario/tipos/{$t->id}", ['nome' => 'Leitor', 'ativo' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($t->fresh()->ativo);
        $this->get('/tecnologia/inventario/novo')->assertOk()->assertDontSee('>Leitor<', false);
    }

    public function test_atributos_do_item(): void
    {
        $item = $this->cadastrar(['atributos' => [['nome' => 'IP', 'valor' => '10.0.5.20'], ['nome' => 'Memória', 'valor' => '16 GB'], ['nome' => '', 'valor' => '']]]);
        $this->assertSame(['IP' => '10.0.5.20', 'Memória' => '16 GB'], $item->atributos()->pluck('valor', 'nome')->all());
        $this->get("/tecnologia/inventario/{$item->id}")->assertOk()->assertSee('Memória')->assertSee('16 GB');
        $this->get('/tecnologia/inventario?busca=10.0.5')->assertOk()->assertSee('<strong>TI-0001</strong>', false);
        $this->get("/tecnologia/inventario/{$item->id}/editar")->assertOk()->assertSee('value="10.0.5.20"', false)->assertSee('<option value="IP">', false);
        $this->post('/tecnologia/inventario', ['unidade' => 'tecnologia', 'tipo_id' => $this->notebook->id, 'descricao_id' => $this->desc('X'), 'situacao' => 'estoque',
            'atributos' => [['nome' => '', 'valor' => 'sem nome']]])->assertSessionHasErrors('atributos.0.nome');

        // Editar: remove um e troca o valor do outro.
        $this->put("/tecnologia/inventario/{$item->id}", ['unidade' => 'tecnologia', 'tipo_id' => $this->notebook->id, 'patrimonio' => 'TI-0001',
            'descricao_id' => $this->desc($item->descricao), 'situacao' => 'em_uso', 'responsavel_id' => $this->ana->id,
            'atributos' => [['nome' => 'IP', 'valor' => '10.0.5.21']]])->assertSessionHasNoErrors();
        $this->assertSame(['IP' => '10.0.5.21'], $item->atributos()->pluck('valor', 'nome')->all());
    }

    public function test_duas_testemunhas_assinam_o_termo(): void
    {
        $item = $this->cadastrar();
        $termo = TermoResponsabilidade::where('item_id', $item->id)->firstOrFail();
        $carla = $this->usuario([], 'carla@empresa.com');
        $davi = $this->usuario(['operador'], 'davi@empresa.com');
        $url = "/tecnologia/inventario/termos/{$termo->id}/testemunhas";

        $this->actingAs($this->admin)->get("/tecnologia/termos/{$termo->id}")->assertOk()->assertSee('Testemunha 1 não definida')->assertSee('Salvar testemunhas');
        $this->post($url, ['testemunha_1' => $this->ana->id, 'testemunha_2' => $carla->id])->assertSessionHasErrors('testemunhas'); // comodatária
        $this->post($url, ['testemunha_1' => $carla->id, 'testemunha_2' => $carla->id])->assertSessionHasErrors('testemunhas');
        $this->post($url, ['testemunha_1' => $carla->id, 'testemunha_2' => $davi->id])->assertSessionHasNoErrors();
        // Quem não administra a unidade não define testemunhas.
        $this->actingAs($this->usuario(['mantenedor_tecnologia'], 'mt@empresa.com'))->post($url, ['testemunha_1' => $davi->id])->assertForbidden();

        // A Carla (sem perfil) vê o aviso e assina como testemunha 1 no portal; o Davi não assina no lugar dela.
        $this->actingAs($carla)->get('/tecnologia/meus-equipamentos')->assertOk()->assertSee('Assinar como testemunha');
        $this->get("/tecnologia/termos/{$termo->id}")->assertOk()->assertSee('Assinar como testemunha 1');
        $this->post("/tecnologia/termos/{$termo->id}/testemunhas/1/assinar", ['assinatura_testemunha' => $this->png()])->assertSessionHasErrors('rg');
        $this->post("/tecnologia/termos/{$termo->id}/testemunhas/1/assinar", ['assinatura_testemunha' => $this->png(), 'rg' => '33.081.668-8 SSP/SP'])
            ->assertSessionHasNoErrors();
        $this->actingAs($davi)->post("/tecnologia/termos/{$termo->id}/testemunhas/1/assinar", ['assinatura_testemunha' => $this->png(), 'rg' => '1'])->assertForbidden();
        $this->actingAs($this->usuario([], 'estranho@empresa.com'))->get("/tecnologia/termos/{$termo->id}")->assertNotFound();

        // O administrador colhe a do Davi na hora; testemunha que assinou não é trocada.
        $this->actingAs($this->admin)->post("/tecnologia/termos/{$termo->id}/testemunhas/2/assinar", ['assinatura_testemunha' => $this->png(), 'rg' => '9'])
            ->assertSessionHasErrors('confirmo_presencial');
        $this->post("/tecnologia/termos/{$termo->id}/testemunhas/2/assinar", ['assinatura_testemunha' => $this->png(), 'rg' => '9', 'confirmo_presencial' => '1'])
            ->assertSessionHasNoErrors();
        $this->post($url, ['testemunha_1' => $this->admin->id, 'testemunha_2' => $davi->id])->assertSessionHasErrors('testemunhas');
        $ts = $termo->testemunhas()->get();
        $this->assertSame([false, true], $ts->pluck('presencial')->all());
        $this->assertSame('33.081.668-8 SSP/SP', $ts[0]->rg);
        $this->get("/tecnologia/termos/{$termo->id}")->assertOk()->assertSee('NOME: CARLA')->assertSee('RG nº 33.081.668-8 SSP/SP');

        // Comodatária assina: termo completo. O próximo termo já nasce com as mesmas testemunhas.
        $this->actingAs($this->ana)->post("/tecnologia/termos/{$termo->id}/assinar", ['assinatura' => $this->png(), 'aceite' => '1'] + $this->qualificacao());
        $this->assertTrue($termo->fresh()->completo());
        $this->actingAs($this->admin)->post("/tecnologia/inventario/{$item->id}/termo")->assertRedirect();
        $novo = TermoResponsabilidade::where('item_id', $item->id)->pendentes()->firstOrFail();
        $this->assertSame([$carla->id, $davi->id], $novo->testemunhas()->pluck('usuario_id')->map(fn ($id) => (int) $id)->all());
        $this->actingAs($carla)->get("/tecnologia/termos/{$novo->id}")->assertOk()->assertSee('value="33.081.668-8 SSP/SP"', false);
    }

    public function test_administrador_que_e_testemunha_assina_como_testemunha_e_anula_termo_errado(): void
    {
        $item = $this->cadastrar();
        $termo = TermoResponsabilidade::where('item_id', $item->id)->firstOrFail();
        $rafaela = $this->usuario([], 'rafaela@empresa.com');
        $this->actingAs($this->admin)->post("/tecnologia/inventario/termos/{$termo->id}/testemunhas", ['testemunha_1' => $this->admin->id, 'testemunha_2' => $rafaela->id])
            ->assertSessionHasNoErrors();

        // O administrador logado é testemunha: o quadro DELE vem primeiro; o da Ana (presencial) fica recolhido e depois.
        $html = $this->get("/tecnologia/termos/{$termo->id}")->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'Colher na hora a assinatura de Ana'), strpos($html, 'Assinar como testemunha 1'));
        // Mandar o formulário da Ana sem confirmar que é ela quem assina é recusado (o erro do dia 25/11).
        $this->post("/tecnologia/termos/{$termo->id}/assinar", ['assinatura' => $this->png(), 'aceite' => '1'] + $this->qualificacao())
            ->assertSessionHasErrors('confirmo_presencial');
        $this->assertSame('pendente', $termo->fresh()->status);
        $this->post("/tecnologia/termos/{$termo->id}/testemunhas/1/assinar", ['assinatura_testemunha' => $this->png(), 'rg' => '35.089.260-8'])
            ->assertSessionHasNoErrors();

        // Se mesmo assim ficou errado: anular cancela (sem apagar) e gera um termo novo com as mesmas testemunhas.
        $this->post("/tecnologia/inventario/termos/{$termo->id}/anular", [])->assertSessionHasErrors('motivo');
        $this->actingAs($this->usuario(['mantenedor_tecnologia'], 'mt2@empresa.com'))->post("/tecnologia/inventario/termos/{$termo->id}/anular", ['motivo' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->admin)->post("/tecnologia/inventario/termos/{$termo->id}/anular", ['motivo' => 'Assinatura no lugar errado'])->assertRedirect();
        $this->assertSame(['cancelado', 'Anulado: Assinatura no lugar errado'], [$termo->fresh()->status, $termo->fresh()->motivo_cancelamento]);
        $this->assertNotNull($termo->testemunhas()->where('ordem', 1)->value('assinado_em')); // nada apagado
        $novo = TermoResponsabilidade::where('item_id', $item->id)->pendentes()->firstOrFail();
        $this->assertSame([$this->admin->id, $rafaela->id], $novo->testemunhas()->pluck('usuario_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(0, $novo->testemunhas()->whereNotNull('assinado_em')->count());
    }

    public function test_descricao_vem_das_descricoes_padrao(): void
    {
        $this->actingAs($this->admin)->post('/tecnologia/inventario/descricoes', ['descricao' => '  Notebook   Dell Latitude 5440 ', 'tipo_id' => $this->notebook->id,
            'ativo' => 1, 'unidade' => 'tecnologia'])->assertSessionHasNoErrors();
        $d = DescricaoItemTi::firstOrFail();
        $this->assertSame('Notebook Dell Latitude 5440', $d->descricao);
        $this->post('/tecnologia/inventario/descricoes', ['descricao' => 'Notebook Dell Latitude 5440', 'ativo' => 1, 'unidade' => 'tecnologia'])
            ->assertSessionHasErrors('descricao');
        $this->get('/tecnologia/inventario/descricoes')->assertOk()->assertSee('Notebook Dell Latitude 5440');
        $this->post('/tecnologia/inventario/descricoes', ['descricao' => 'Monitor LG 24', 'ativo' => 1, 'unidade' => 'tecnologia'])->assertSessionHasNoErrors();
        $this->get('/tecnologia/inventario/descricoes?busca=dell+5440')->assertOk()->assertSee('value="Notebook Dell Latitude 5440"', false)
            ->assertDontSee('value="Monitor LG 24"', false);
        $this->get('/tecnologia/inventario/descricoes?tipo=sem')->assertOk()->assertSee('value="Monitor LG 24"', false)
            ->assertDontSee('value="Notebook Dell Latitude 5440"', false);
        $this->get('/tecnologia/inventario/novo')->assertOk()->assertSee('Notebook Dell Latitude 5440');

        $base = ['unidade' => 'tecnologia', 'tipo_id' => $this->notebook->id, 'situacao' => 'estoque'];
        $this->post('/tecnologia/inventario', $base)->assertSessionHasErrors('descricao_id'); // sem descrição
        $monitor = TipoItemTi::withoutGlobalScopes()->where('nome', 'Monitor')->firstOrFail();
        $this->post('/tecnologia/inventario', ['tipo_id' => $monitor->id, 'descricao_id' => $d->id] + $base)->assertSessionHasErrors('descricao_id'); // de outro tipo
        $this->post('/tecnologia/inventario', ['descricao_id' => $d->id] + $base)->assertSessionHasNoErrors();
        $item = ItemTi::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame([$d->id, 'Notebook Dell Latitude 5440'], [(int) $item->descricao_id, $item->descricao]);

        // Renomear a descrição padrão não muda o item já gravado; desativada some da lista dos novos.
        $this->put("/tecnologia/inventario/descricoes/{$d->id}", ['descricao' => 'Notebook Dell 5440', 'tipo_id' => $this->notebook->id, 'ativo' => 0])
            ->assertSessionHasNoErrors();
        $this->assertSame('Notebook Dell Latitude 5440', $item->fresh()->descricao);
        $this->get('/tecnologia/inventario/novo')->assertOk()->assertDontSee('>Notebook Dell 5440</option>', false);
        $this->get("/tecnologia/inventario/{$item->id}/editar")->assertOk()->assertSee('>Notebook Dell 5440</option>', false); // a dele continua na lista

        // Item antigo (sem descrição padrão) mantém a descrição dele ao editar.
        $antigo = ItemTi::withoutGlobalScopes()->create(['unidade' => 'tecnologia', 'categoria' => 'hardware', 'tipo_id' => $this->notebook->id,
            'descricao' => 'Notebook velho', 'situacao' => 'estoque']);
        $this->get("/tecnologia/inventario/{$antigo->id}/editar")->assertOk()->assertSee('Manter a atual: Notebook velho');
        $this->put("/tecnologia/inventario/{$antigo->id}", $base + ['local' => 'TI'])->assertSessionHasNoErrors();
        $this->assertSame('Notebook velho', $antigo->fresh()->descricao);
    }
}
