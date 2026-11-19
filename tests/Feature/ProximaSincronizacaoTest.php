<?php

namespace Tests\Feature;

use App\Models\AgendaSincronizacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Telas de dados do Protheus mostram a última e a PRÓXIMA sincronização (agenda da tela Banco de dados). */
class ProximaSincronizacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_mostra_a_proxima_sincronizacao_ou_que_a_automatica_esta_desligada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 13:10', 'UTC')); // 10:10 em São Paulo
        $this->actingAs(Usuario::create(['entra_oid' => 'adm', 'nome' => 'Admin', 'email' => 'adm@empresa.com', 'perfil' => 'admin']));
        AgendaSincronizacao::create(['chave' => 'ordens_producao', 'ativo' => true, 'intervalo_minutos' => 60, 'inicio' => '00:30']);

        $this->get('/producao/ordens-de-producao')->assertOk()->assertSee('Próxima sincronização: <strong>hoje às 10:30</strong>', false);
        foreach (['/producao/apontamentos-de-producao', '/producao/empenhos-op', '/producao/estruturas', '/producao/roteiros', '/producao/ferramentas', '/producao/falta-de-produtos'] as $url) {
            $this->get($url)->assertOk()->assertSee('Próxima sincronização: automática desligada.');
        }

        Carbon::setTestNow();
    }
}
