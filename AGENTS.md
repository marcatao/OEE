# OEE Alumbra — instruções para o Claude

Portal Laravel 13 (PHP 8.4.1+; a equipe usa PHP 8.5) de dashboards de produção e cálculos de OEE. Login pelo Microsoft Entra ID, dados no
SQL Server. Escreva código, textos de tela, comentários e mensagens de commit em português do Brasil.

## Regras do projeto

- O **Entra ID só autentica** a entrada (`app/Services/EntraId.php`). O usuário é cadastrado no banco no primeiro
  login (`usuarios`, chave `entra_oid`, nunca o e-mail) e nasce **sem acesso**; só os e-mails de `config('oee.admins')`
  (padrão `marcato@alumbra.com.br`) nascem `admin`. Todo login vai para `registros_login`.
- Acesso: perfil (`sem_acesso` | `operador` | `admin`) + **grupos**. Os grupos dão permissões de página
  (`grupo_permissoes`, catálogo em `config('oee.permissoes')`). Admin pode tudo. Só admin cria grupos e altera
  cadastros.
- Banco principal: **SQL Server** (`DB_CONNECTION=sqlsrv`, configurado no `.env`). Testes rodam em SQLite em memória:
  tudo precisa funcionar nos dois. Recursos só do SQL Server ficam atrás de `DB::getDriverName() === 'sqlsrv'`.
- Datas gravadas em **UTC** (`config/app.php` usa `UTC`) e exibidas no fuso local com `@dataHora($valor)`.
- Tabelas e colunas em português. Modelos de cadastro usam o trait `App\Models\Concerns\Auditavel`
  (`criado_em`, `criado_por_id`, `atualizado_em`, `atualizado_por_id`). Cadastros são desativados, não excluídos.
- Nome do portal: **OEE Alumbra**, em `config('oee.nome')` (nunca escreva o nome direto nas telas).
- Identidade visual em **tons de verde** (estilo da empresa): as cores ficam só nas variáveis do topo de
  `public/css/portal.css` (tema claro e escuro). Nunca use cores soltas nas telas.
- Telas em Blade com `layouts.portal` e o CSS de `public/css/portal.css`. Sem etapa de build com Node.
- Toda rota interna fica no grupo `auth`. Página nova: registre a chave em `config/oee.php` (`permissoes`), proteja a
  rota com `->middleware('permissao:chave')` e inclua o link no menu lateral (`layouts/portal.blade.php`) dentro de
  `@if ($u->pode('chave'))`. Páginas só de admin usam `->middleware('perfil:admin')`.
- Fórmulas de OEE só em `app/Services/CalculadoraOee.php` (ou serviços irmãos), com testes.

## Git

- Trabalhe **sempre direto na branch `main`**: não crie outras branches nem pull requests. Faça commit e push em
  `main` (`git push origin main`). Isso vale para todos os repositórios do usuário.
- Nunca commite `.env` nem cópias dele (`.env.bak*`): têm segredos.

## Ambiente

- O `composer.lock` exige **PHP 8.4.1+**. Se o ambiente só tiver PHP 8.3, o `composer install` falha: diga isso ao
  usuário em vez de mexer no `composer.lock` ou forçar versões.
- A pasta `php8.5/` (php.ini e DLLs do SQL Server para Windows) fica versionada de propósito, para a equipe
  compartilhar a configuração do PHP. Nunca coloque segredos nela.

## Comandos

```sh
php artisan test           # testes (sempre antes de terminar)
vendor/bin/pint            # formatação do código
php artisan migrate        # aplica migrações
php artisan banco:testar   # confere a conexão com o banco
```

## Agentes especialistas (`.claude/agents/`)

- `especialista-portal-oee`: telas, dashboards, gráficos, relatórios, cadastros e tempo real.
- `especialista-sql-server`: tabelas, migrações, consultas, índices, permissões e desempenho no SQL Server.
- `especialista-entra-id`: login, sessão, segredos e segurança da integração com o Entra ID.
- `especialista-dados-oee`: regras de cálculo, agregações e análises de OEE.
