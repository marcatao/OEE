<?php

namespace Tests\Feature;

use App\Models\MotivoParada;
use App\Services\InfoBanco;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BancoTest extends TestCase
{
    use RefreshDatabase;

    public function test_descricao_do_sql_server_nao_mostra_a_senha(): void
    {
        config([
            'database.connections.sqlsrv.host' => 'SRV01\SQLEXPRESS',
            'database.connections.sqlsrv.port' => '',
            'database.connections.sqlsrv.database' => 'OEE_Alumbra',
            'database.connections.sqlsrv.username' => 'oee_app',
            'database.connections.sqlsrv.password' => 's3nh@;secreta',
        ]);

        $d = (new InfoBanco)->descricao('sqlsrv');
        $this->assertSame('SQL Server', $d['Tipo']);
        $this->assertSame('SRV01\SQLEXPRESS', $d['Servidor']);
        $this->assertSame('usuário e senha do SQL Server', $d['Autenticação']);
        $this->assertStringNotContainsString('s3nh', json_encode($d));
    }

    public function test_sem_usuario_usa_autenticacao_do_windows(): void
    {
        config(['database.connections.sqlsrv.username' => '']);
        $this->assertStringStartsWith('Windows', (new InfoBanco)->descricao('sqlsrv')['Autenticação']);
    }

    public function test_comando_banco_testar(): void
    {
        $this->artisan('banco:testar')->expectsOutputToContain('Conexão OK')->assertSuccessful();
    }

    public function test_seeder_cria_motivos_de_parada_sem_duplicar(): void
    {
        $this->seed();
        $this->seed();
        $this->assertSame(11, MotivoParada::count());
        $this->assertSame(3, MotivoParada::where('planejada', true)->count());
    }
}
