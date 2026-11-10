<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Gera docs/banco-de-dados.md a partir das migrações: diagrama (Mermaid, que o GitHub mostra como imagem), colunas
 * com os tipos do SQL Server, chaves primárias/estrangeiras/únicas, índices, regras CHECK e a origem de cada tabela
 * (Sistema OEE ou Protheus). Não precisa de SQL Server: roda as migrações em modo simulação ("pretend") com a
 * gramática do SQL Server. O trecho entre os marcadores "manual" do arquivo é preservado.
 */
class DocumentarBanco extends Command
{
    protected $signature = 'banco:documentar {--saida=docs/banco-de-dados.md : Arquivo a gerar}';

    protected $description = 'Gera a documentação e o diagrama do banco de dados (docs/banco-de-dados.md) a partir das migrações';

    /** Tabelas do próprio Laravel (cache, filas, sessões): listadas, mas fora do diagrama. */
    private const INFRA = ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions'];

    /** Colunas de auditoria: viram FK para usuarios, mas ficam fora do diagrama para não poluir. */
    private const AUDITORIA = ['criado_por_id', 'atualizado_por_id'];

    /** Ligações por código, sem chave estrangeira no banco (tabela.coluna => tabela.coluna). */
    private const LIGACOES_LOGICAS = [
        'maquinas.centro_custo' => 'centros_custo.ctt_custo',
        'ordens_producao.c2_produto' => 'produtos_protheus.b1_cod',
        'ordens_producao.c2_cc' => 'centros_custo.ctt_custo',
        'movimentos_producao.op_numero' => 'ordens_producao.numero',
        'movimentos_producao.d3_cod' => 'produtos_protheus.b1_cod',
        'notas_fiscais_op.op_numero' => 'ordens_producao.numero',
        'notas_fiscais_op.d2_cod' => 'produtos_protheus.b1_cod',
    ];

    private const MARCA_INICIO = '<!-- manual:inicio -->';

    private const MARCA_FIM = '<!-- manual:fim -->';

    public function handle(): int
    {
        $comandos = $this->simularMigracoes();
        $tabelas = $this->interpretar($comandos);

        $arquivo = base_path($this->option('saida'));
        $manual = $this->trechoManual($arquivo);
        @mkdir(dirname($arquivo), 0775, true);
        file_put_contents($arquivo, $this->markdown($tabelas, $manual));

        $this->info('Gerado '.$this->option('saida').' com '.count($tabelas).' tabelas.');

        return self::SUCCESS;
    }

    /** @return list<string> comandos SQL (SQL Server) que as migrações executariam num banco vazio */
    private function simularMigracoes(): array
    {
        config([
            'database.connections.documentacao' => ['driver' => 'sqlsrv', 'host' => 'documentacao', 'database' => 'OEE', 'prefix' => '', 'prefix_indexes' => true],
            'database.default' => 'documentacao',
        ]);
        $conexao = DB::connection('documentacao');
        // Conexão "de mentira": em modo simulação nada é executado, mas alguns comandos pedem uma conexão aberta.
        $conexao->setPdo(new PDO('sqlite::memory:'));

        $arquivos = glob(database_path('migrations/*.php'));
        sort($arquivos);
        $sql = [];
        foreach ($arquivos as $arquivo) {
            $migracao = require $arquivo;
            foreach ($conexao->pretend(fn () => $migracao->up()) as $q) {
                $sql[] = $q['query'];
            }
        }

        return $sql;
    }

    /** @return array<string, array> tabelas com colunas, pk, fks, únicos, índices e checks */
    private function interpretar(array $comandos): array
    {
        $t = [];
        $nova = fn () => ['colunas' => [], 'pk' => [], 'fks' => [], 'unicos' => [], 'indices' => [], 'checks' => []];

        foreach ($comandos as $sql) {
            if (preg_match('/^create table "(\w+)" \((.*)\)$/s', $sql, $m)) {
                $t[$m[1]] = $nova();
                foreach (preg_split('/,\s*(?=")/', $m[2]) as $def) {
                    $this->coluna($t[$m[1]], $def);
                }
            } elseif (preg_match('/^alter table "(\w+)" add (".*)$/s', $sql, $m)) {
                foreach (preg_split('/,\s*(?=")/', $m[2]) as $def) {
                    $this->coluna($t[$m[1]], $def);
                }
            } elseif (preg_match('/^alter table "(\w+)" add constraint "\w+" foreign key \("(\w+)"\) references "(\w+)" \("(\w+)"\)/', $sql, $m)) {
                $t[$m[1]]['fks'][$m[2]] = [$m[3], $m[4]];
            } elseif (preg_match('/^alter table "(\w+)" add constraint "\w+" primary key \((.*)\)/', $sql, $m)) {
                $t[$m[1]]['pk'] = $this->listaColunas($m[2]);
            } elseif (preg_match('/^create (unique )?index "(\w+)" on "(\w+)" \((.*)\)/', $sql, $m)) {
                $t[$m[3]][$m[1] ? 'unicos' : 'indices'][$m[2]] = $this->listaColunas($m[4]);
            } elseif (preg_match('/^ALTER TABLE (\w+) ADD CONSTRAINT (\w+) CHECK \((.*)\)$/s', $sql, $m)) {
                $t[$m[1]]['checks'][$m[2]] = $m[3];
            }
        }
        ksort($t);

        return $t;
    }

    private function coluna(array &$tabela, string $def): void
    {
        if (! preg_match('/^"(\w+)" (\S+)( not null| null)?(.*)$/s', trim($def), $m)) {
            return;
        }
        $resto = $m[4];
        $tabela['colunas'][$m[1]] = [
            'tipo' => $m[2],
            'nulo' => trim($m[3]) !== 'not null',
            'padrao' => preg_match("/default ('[^']*'|\S+)/", $resto, $p) ? $p[1] : null,
            'identidade' => str_contains($resto, 'identity'),
        ];
        if (str_contains($resto, 'primary key')) {
            $tabela['pk'] = [$m[1]];
        }
    }

    private function listaColunas(string $lista): array
    {
        preg_match_all('/"(\w+)"/', $lista, $m);

        return $m[1];
    }

    /** @return array{tipo: string, texto: string} */
    private function origem(string $tabela): array
    {
        foreach (config('protheus.sincronizacoes', []) as $item) {
            if ($item['tabela_destino'] === $tabela) {
                $de = '`'.config('database.connections.protheus.database').'.'.$item['tabela_origem'].'`';

                return ($item['modo'] ?? 'espelho') === 'mista'
                    ? ['tipo' => 'Mista', 'texto' => "Mista — parte dos campos vem de {$de} (Protheus), o resto é do Sistema OEE"]
                    : ['tipo' => 'Protheus', 'texto' => "Protheus — sincronizada de {$de}"];
            }
        }

        return in_array($tabela, self::INFRA, true)
            ? ['tipo' => 'Infra', 'texto' => 'Infraestrutura do Laravel']
            : ['tipo' => 'Sistema', 'texto' => 'Sistema OEE'];
    }

    private function markdown(array $tabelas, string $manual): string
    {
        $dominio = array_filter($tabelas, fn ($_, $nome) => ! in_array($nome, self::INFRA, true), ARRAY_FILTER_USE_BOTH);
        $md = [];
        $md[] = '# Banco de dados — '.config('oee.nome');
        $md[] = '';
        $md[] = '> Gerado por `php artisan banco:documentar` a partir das migrações (tipos do **SQL Server**). Não edite as';
        $md[] = '> seções geradas: mude a migração e gere de novo. O trecho "Decisões e sugestões de modelagem" é mantido.';
        $md[] = '';
        $md[] = '- **'.count($dominio).'** tabelas do sistema e **'.(count($tabelas) - count($dominio)).'** de infraestrutura do Laravel.';
        $md[] = '- Origem: **Sistema OEE** (criada e mantida pelo portal), **Protheus** (copiada pela sincronização, só leitura no Protheus) ou
  **Mista** (parte dos campos vem do Protheus na primeira sincronização e o resto é do portal).';
        $md[] = '- Todas as datas são gravadas em UTC. Cadastros têm auditoria (`criado_em`, `criado_por_id`, `atualizado_em`, `atualizado_por_id` → `usuarios`).';
        $md[] = '';
        $md[] = '## Diagrama';
        $md[] = '';
        $md[] = 'Tabelas de infraestrutura e as ligações de auditoria (`criado_por_id`/`atualizado_por_id` → `usuarios`) ficam fora do';
        $md[] = 'diagrama para ele ficar legível. Linha tracejada = ligação por código, sem chave estrangeira no banco.';
        $md[] = '';
        $md[] = '```mermaid';
        $md[] = 'erDiagram';
        foreach ($dominio as $nome => $tab) {
            $md[] = "    {$nome} {";
            foreach ($tab['colunas'] as $col => $c) {
                $chaves = [];
                if (in_array($col, $tab['pk'], true)) {
                    $chaves[] = 'PK';
                }
                if (isset($tab['fks'][$col])) {
                    $chaves[] = 'FK';
                }
                foreach ($tab['unicos'] as $u) {
                    if ($u === [$col]) {
                        $chaves[] = 'UK';
                    }
                }
                $md[] = '        '.str_replace(' ', '_', $c['tipo'])." {$col}".($chaves ? ' '.implode(', ', array_unique($chaves)) : '');
            }
            $md[] = '    }';
        }
        foreach ($dominio as $nome => $tab) {
            foreach ($tab['fks'] as $col => [$alvo]) {
                if (in_array($col, self::AUDITORIA, true) || ! isset($dominio[$alvo])) {
                    continue;
                }
                $pai = $tab['colunas'][$col]['nulo'] ? '|o' : '||';
                $md[] = "    {$alvo} {$pai}--o{ {$nome} : \"{$col}\"";
            }
        }
        foreach (self::LIGACOES_LOGICAS as $de => $para) {
            [$tDe, $cDe] = explode('.', $de);
            [$tPara] = explode('.', $para);
            if (isset($dominio[$tDe], $dominio[$tPara])) {
                $md[] = "    {$tPara} |o..o{ {$tDe} : \"{$cDe} (código)\"";
            }
        }
        $md[] = '```';
        $md[] = '';
        $md[] = '## Decisões e sugestões de modelagem';
        $md[] = '';
        $md[] = self::MARCA_INICIO;
        $md[] = $manual !== '' ? $manual : '_Mantido pelo agente `arquiteto-banco-dados`._';
        $md[] = self::MARCA_FIM;
        $md[] = '';
        $md[] = '## Tabelas';
        $md[] = '';
        $md[] = '| Tabela | Origem | Chave primária | Colunas | Ligações (FK) |';
        $md[] = '|---|---|---|---|---|';
        foreach ($tabelas as $nome => $tab) {
            $md[] = "| [`{$nome}`](#{$nome}) | ".$this->origem($nome)['tipo'].' | `'.implode(', ', $tab['pk'] ?: ['—']).'` | '.count($tab['colunas']).' | '.count($tab['fks']).' |';
        }
        foreach ($tabelas as $nome => $tab) {
            $md[] = '';
            $md[] = "### {$nome}";
            $md[] = '';
            $md[] = '**Origem:** '.$this->origem($nome)['texto'].' · **Chave primária:** `'.implode(', ', $tab['pk'] ?: ['—']).'`';
            $md[] = '';
            $md[] = '| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |';
            $md[] = '|---|---|---|---|---|';
            foreach ($tab['colunas'] as $col => $c) {
                $chave = [];
                if (in_array($col, $tab['pk'], true)) {
                    $chave[] = 'PK'.($c['identidade'] ? ' (identity)' : '');
                }
                if (isset($tab['fks'][$col])) {
                    $chave[] = 'FK → `'.$tab['fks'][$col][0].'.'.$tab['fks'][$col][1].'`';
                }
                if (isset(self::LIGACOES_LOGICAS["{$nome}.{$col}"])) {
                    $chave[] = 'código → `'.self::LIGACOES_LOGICAS["{$nome}.{$col}"].'` (sem FK)';
                }
                $md[] = "| `{$col}` | `{$c['tipo']}` | ".($c['nulo'] ? 'sim' : 'não').' | '.($c['padrao'] !== null ? '`'.$c['padrao'].'`' : '').' | '.implode('; ', $chave).' |';
            }
            if ($tab['unicos'] || $tab['indices']) {
                $md[] = '';
                $md[] = '**Índices:** '.implode(' · ', array_merge(
                    array_map(fn ($cols, $n) => "`{$n}` único (".implode(', ', $cols).')', $tab['unicos'], array_keys($tab['unicos'])),
                    array_map(fn ($cols, $n) => "`{$n}` (".implode(', ', $cols).')', $tab['indices'], array_keys($tab['indices'])),
                ));
            }
            if ($tab['checks']) {
                $md[] = '';
                $md[] = '**Regras (CHECK, só no SQL Server):** '.implode(' · ', array_map(fn ($expr, $n) => "`{$n}`: `{$expr}`", $tab['checks'], array_keys($tab['checks'])));
            }
        }
        $md[] = '';

        return implode("\n", $md);
    }

    private function trechoManual(string $arquivo): string
    {
        if (! is_file($arquivo)) {
            return '';
        }
        $texto = file_get_contents($arquivo);
        $i = strpos($texto, self::MARCA_INICIO);
        $f = strpos($texto, self::MARCA_FIM);

        return $i !== false && $f !== false && $f > $i ? trim(substr($texto, $i + strlen(self::MARCA_INICIO), $f - $i - strlen(self::MARCA_INICIO))) : '';
    }
}
