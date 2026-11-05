# OEE Alumbra

Portal web em **PHP / Laravel** para acompanhar o **OEE** (Overall Equipment Effectiveness / Eficiência Global dos
Equipamentos), com login pela conta corporativa no **Microsoft Entra ID** e dados no **SQL Server**.

OEE = Disponibilidade × Performance × Qualidade.

O Entra ID só autentica a entrada. Nome e e-mail do usuário, histórico de logins, perfis de acesso, cadastros e dados
das máquinas ficam no banco do portal.

## O que já existe

- Login e logout pelo Entra ID (OpenID Connect, authorization code + PKCE), com cada tentativa gravada no banco.
- Perfis `operador` < `supervisor` < `admin`, definidos no portal (menu **Usuários**).
- Página **Banco** (admin): mostra a conexão em uso, testa e conta os registros de cada tabela.
- Calculadora de OEE.
- Tabelas de cadastros (plantas, linhas, máquinas, turnos, produtos, motivos de parada) e de dados das máquinas
  (apontamentos de produção e paradas), com auditoria de quem criou/alterou.

## Estrutura

| Caminho | O que é |
|---|---|
| `routes/web.php` | Rotas do portal |
| `routes/console.php` | Comandos `php artisan banco:testar` e `usuarios:*` |
| `app/Http/Controllers/AutenticacaoController.php` | Login, retorno e logout do Entra ID |
| `app/Services/EntraId.php` | Conversa com o Entra ID (Socialite + provedor Azure) |
| `app/Services/RegistroDeLogin.php` | Grava usuário e logins no banco |
| `app/Services/CalculadoraOee.php` | Cálculo de OEE |
| `app/Services/InfoBanco.php` | Descrição e teste da conexão com o banco |
| `app/Http/Middleware/` | `UsuarioAtivo` (derruba sessão de quem foi desativado) e `ExigePerfil` (`perfil:admin`) |
| `app/Models/` | Modelos Eloquent (`Usuario`, `RegistroLogin`, `Planta`, `Linha`, `Maquina`, ...) |
| `database/migrations/` | Criação das tabelas |
| `database/seeders/DatabaseSeeder.php` | Motivos de parada padrão |
| `resources/views/` | Telas (Blade) |
| `public/css/portal.css` | Estilo (sem etapa de build com Node) |
| `tests/` | Testes (PHPUnit), com o Entra ID simulado |
| `.claude/agents/` | Agentes especialistas do Claude Code para este projeto |

## 1. Registrar o aplicativo no Entra ID

1. Em [portal.azure.com](https://portal.azure.com) > **Microsoft Entra ID** > **Registros de aplicativo** >
   **Novo registro**.
2. Nome: `OEE Alumbra`. Tipos de conta: **somente contas deste diretório organizacional**.
3. URI de redirecionamento: plataforma **Web**, `http://localhost:8000/auth/callback`
   (em produção, adicione `https://seu-dominio/auth/callback`).
4. Anote o **ID do aplicativo (cliente)** e o **ID do diretório (locatário)**.
5. Em **Certificados e segredos** > **Novo segredo do cliente**, copie o **Valor**.
6. A permissão `User.Read` (Microsoft Graph, delegada) já vem por padrão.
7. Recomendado: em **Aplicativos empresariais** > `OEE Alumbra` > **Propriedades**, ative **Atribuição necessária** e
   atribua os usuários ou grupos que podem entrar.

## 2. Preparar o SQL Server

1. Na máquina do portal, instale o
   [Microsoft ODBC Driver 18 for SQL Server](https://learn.microsoft.com/sql/connect/odbc/download-odbc-driver-for-sql-server)
   e as extensões PHP **`sqlsrv` e `pdo_sqlsrv`**
   ([Microsoft Drivers for PHP for SQL Server](https://learn.microsoft.com/sql/connect/php/download-drivers-php-sql-server)).
   No Windows, copie as DLLs da versão do seu PHP (ex.: `php_pdo_sqlsrv_83_ts_x64.dll`) para a pasta `ext` e ative
   no `php.ini`. Confira com `php -m` (deve listar `pdo_sqlsrv`).
2. No SQL Server (SSMS), crie o banco e um login só para o portal, com o mínimo de permissões:

   ```sql
   CREATE DATABASE OEE_Alumbra;
   GO
   CREATE LOGIN oee_app WITH PASSWORD = 'troque-por-uma-senha-forte', CHECK_POLICY = ON;
   GO
   USE OEE_Alumbra;
   CREATE USER oee_app FOR LOGIN oee_app;
   ALTER ROLE db_datareader ADD MEMBER oee_app;
   ALTER ROLE db_datawriter ADD MEMBER oee_app;
   ALTER ROLE db_ddladmin  ADD MEMBER oee_app;  -- para "php artisan migrate" criar as tabelas
   ```

   Para autenticação do Windows, crie o login da conta que roda o portal
   (`CREATE LOGIN [DOMINIO\conta] FROM WINDOWS`) e deixe `DB_USERNAME` e `DB_PASSWORD` vazios.
3. Preencha a seção **Banco de dados** do `.env` (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
   `DB_PASSWORD`, `DB_ENCRYPT`, `DB_TRUST_SERVER_CERTIFICATE`).

Para testar sem SQL Server, use `DB_CONNECTION=sqlite` e apague as outras linhas `DB_*` (o banco vira o arquivo
`database/database.sqlite`).

## 3. Rodar

Requisitos: PHP 8.3 ou mais novo e [Composer](https://getcomposer.org).

```sh
composer install
copy .env.example .env          # Linux/macOS: cp .env.example .env — e preencha
php artisan key:generate
php artisan banco:testar        # confere a conexão com o banco
php artisan migrate             # cria as tabelas
php artisan db:seed             # motivos de parada padrão
php artisan serve               # abra http://localhost:8000
```

Quem estiver em `PORTAL_ADMINS` entra como **admin** e vê os menus **Usuários** e **Banco**; os demais entram como
**operador**. O perfil também pode ser mudado pelo terminal:

```sh
php artisan usuarios:listar
php artisan usuarios:perfil fulano@alumbra.com.br supervisor
php artisan usuarios:ativar fulano@alumbra.com.br --desativar
```

Ao mudar o `.env` com o portal em produção, rode `php artisan config:clear` (ou `config:cache`).

## Testes

```sh
php artisan test
```

Os testes usam SQLite em memória e simulam o Entra ID; não precisam de SQL Server nem de internet.

## Produção

- Servidor web (IIS com FastCGI, Apache ou Nginx + PHP-FPM) apontando para a pasta `public/`.
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` com `https://`, e `php artisan config:cache route:cache view:cache`.
- HTTPS obrigatório. Atrás de proxy reverso, configure `TRUSTED_PROXIES`.
- Segredo do cliente e senha do banco só no `.env` do servidor (fora do git), com validade e renovação controladas.
