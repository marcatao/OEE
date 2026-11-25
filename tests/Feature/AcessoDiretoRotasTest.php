<?php

namespace Tests\Feature;

use App\Models\Aplicativo\TokenAplicativo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Rotas;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quem não tem acesso a uma tela e digita o endereço direto (ou chama a API) não vê nada: TODAS as rotas protegidas por
 * permissão ou perfil são testadas, em todos os métodos, com usuários sem a permissão.
 */
class AcessoDiretoRotasTest extends TestCase
{
    use RefreshDatabase;

    /** Rotas internas que só exigem login, de propósito (o próprio perfil, sair, a página de aviso). */
    private const SO_LOGIN = ['aguardando', 'app.entrar.concluir', 'logout', 'perfil', 'perfil.update', 'perfil.entra',
        'ordens-producao.busca', 'usuarios.foto', 'api.app.eu', 'api.app.sair',
        // Atualização do app: todo usuário logado no app precisa saber da versão nova e baixá-la.
        'api.app.versao', 'api.app.versao.apk',
        // Inventário de TI: quem recebe um equipamento (mesmo sem perfil) vê os seus itens e assina o termo dele.
        'inventario-ti.meus', 'inventario-ti.termos.show', 'inventario-ti.termos.assinar'];

    private function usuario(array $perfis, string $email): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => 'Fulano', 'email' => $email]);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    /** @return array{0: list<string>, 1: list<string>} permissões e perfis exigidos pela rota */
    private function exigencias(Route $rota): array
    {
        $permissoes = $perfis = [];
        foreach ($rota->gatherMiddleware() as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'permissao:')) {
                $permissoes = explode(',', Str::after($mw, ':'));
            }
            if (is_string($mw) && str_starts_with($mw, 'perfil:')) {
                $perfis = explode(',', Str::after($mw, ':'));
            }
        }

        return [$permissoes, $perfis];
    }

    private function libera(Usuario $u, array $permissoes, array $perfis): bool
    {
        return ($permissoes === [] || collect($permissoes)->contains(fn ($p) => $u->pode($p)))
            && ($perfis === [] || collect($perfis)->contains(fn ($p) => $p === 'admin' ? $u->ehAdmin() : $u->temPerfil($p)));
    }

    /** @return list<array{0: string, 1: string, 2: Route}> */
    private function rotasInternas(): array
    {
        $lista = [];
        foreach (Rotas::getRoutes() as $rota) {
            $mw = $rota->gatherMiddleware();
            $interna = in_array('auth', $mw, true) || in_array('aplicativo', $mw, true);
            if (! $interna) {
                continue;
            }
            $uri = preg_replace('/\{[^}]+\}/', '1', $rota->uri());
            foreach (array_diff($rota->methods(), ['HEAD']) as $metodo) {
                $lista[] = [$metodo, '/'.$uri, $rota];
            }
        }

        return $lista;
    }

    public function test_toda_rota_interna_exige_permissao_ou_perfil(): void
    {
        $soLogin = [];
        foreach ($this->rotasInternas() as [$metodo, $uri, $rota]) {
            [$permissoes, $perfis] = $this->exigencias($rota);
            if ($permissoes === [] && $perfis === []) {
                $soLogin[] = $rota->getName() ?? $rota->uri();
            }
        }
        $this->assertSame([], array_values(array_diff(array_unique($soLogin), self::SO_LOGIN)),
            'Rota interna sem ->middleware(\'permissao:...\') nem perfil: proteja-a ou justifique em SO_LOGIN.');
    }

    public function test_usuario_sem_perfil_nao_abre_nenhuma_tela_pelo_endereco(): void
    {
        $this->actingAs($this->usuario([], 'semperfil@empresa.com'));
        foreach ($this->rotasInternas() as [$metodo, $uri, $rota]) {
            if (str_starts_with($uri, '/api/') || in_array($rota->getName(), self::SO_LOGIN, true)) {
                continue;
            }
            $status = $this->call($metodo, $uri)->getStatusCode();
            $this->assertContains($status, [302, 404], "{$metodo} {$uri} respondeu {$status} para quem não tem perfil.");
        }
    }

    public function test_perfis_sem_a_permissao_recebem_403_em_todas_as_rotas(): void
    {
        foreach ([['operador'], ['solicitante_manutencao'], ['mantenedor_ferramentaria']] as $perfis) {
            $u = $this->usuario($perfis, implode('-', $perfis).'@empresa.com');
            $this->actingAs($u);
            foreach ($this->rotasInternas() as [$metodo, $uri, $rota]) {
                [$permissoes, $exigePerfis] = $this->exigencias($rota);
                if (str_starts_with($uri, '/api/') || ($permissoes === [] && $exigePerfis === []) || $this->libera($u, $permissoes, $exigePerfis)) {
                    continue;
                }
                $status = $this->call($metodo, $uri)->getStatusCode();
                $this->assertContains($status, [403, 404], "{$metodo} {$uri} respondeu {$status} para o perfil ".implode(',', $perfis).'.');
            }
        }
    }

    public function test_api_do_aplicativo_recusa_quem_nao_tem_o_perfil(): void
    {
        foreach ([[[], false], [['solicitante_tecnologia'], true], [['operador'], true]] as [$perfis, $temAcesso]) {
            $u = $this->usuario($perfis, 'app-'.count($perfis).implode($perfis).'@empresa.com');
            $token = Str::random(64);
            TokenAplicativo::create(['usuario_id' => $u->id, 'token_hash' => hash('sha256', $token), 'ultimo_uso_em' => now()]);
            foreach ($this->rotasInternas() as [$metodo, $uri, $rota]) {
                [$permissoes, $exigePerfis] = $this->exigencias($rota);
                if (! str_starts_with($uri, '/api/app/') || ($temAcesso && ($permissoes === [] && $exigePerfis === [] || $this->libera($u, $permissoes, $exigePerfis)))) {
                    continue;
                }
                $status = $this->json($metodo, $uri, [], ['Authorization' => "Bearer {$token}"])->getStatusCode();
                $esperado = $temAcesso ? [403, 404] : [401];
                $this->assertContains($status, $esperado, "{$metodo} {$uri} respondeu {$status} para o perfil ".(implode(',', $perfis) ?: 'nenhum').'.');
            }
        }
    }
}
