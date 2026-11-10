# OEE Alumbra

Portal web em **PHP / Laravel** para acompanhar o **OEE** (Overall Equipment Effectiveness / Eficiência Global dos
Equipamentos), com login pela conta corporativa no **Microsoft Entra ID** e dados no **SQL Server**.

OEE = Disponibilidade × Performance × Qualidade.

O Entra ID só autentica a entrada. Nome e e-mail do usuário, histórico de logins, perfis de acesso, cadastros e dados
das máquinas ficam no banco do portal.

## O que já existe

- Login e logout pelo Entra ID (OpenID Connect, authorization code + PKCE), com cada tentativa gravada no banco.
- Cadastro automático no primeiro login pelo Entra ID: o usuário nasce **sem acesso** e vê só a página
  "aguardando liberação" até um administrador liberar. Só `marcato@alumbra.com.br` (configurável em
  `PORTAL_ADMINS`) nasce **administrador**.
- Perfis: **Sem acesso**, **Operador** (vê as páginas que os grupos dele permitem) e **Administrador** (vê tudo).
- **Grupos**: o administrador cria grupos, escolhe as páginas que cada grupo pode abrir e quem faz parte dele.
- Menu lateral (recolhível: o botão do topo deixa só os ícones e o navegador lembra a escolha):
  Início · **Produção** (Dashboard por máquina, Calculadora de OEE) · **Administração** (Máquinas, Plantas e linhas,
  Dispositivos IoT, Logins › Usuários/Grupos/Histórico de acessos, Banco de dados). Cada usuário só vê o que pode abrir.
- **Máquinas** (Administração): código, nome, linha, **setor / centro de custo** (grava só o código), fabricante,
  modelo, ciclo ideal, **micro parada** e **macro parada** (segundos), **máquina ativa ou inativa** e três canais
  **IoT 1/2/3** (ID IoT, canal IoT e tipo do canal: produção ou refugo). O mesmo ID + canal não pode ser usado duas
  vezes (a produção seria contada em dobro).
- **Centros de custo** (Administração): tabela `centros_custo` no formato da **CTT do Protheus** (colunas `ctt_filial`,
  `ctt_custo`, `ctt_desc01`, `ctt_classe`, `ctt_bloq`, `ctt_ccsup`, `ctt_dtexis`, `ctt_dtexsf`, `d_e_l_e_t_`,
  `r_e_c_n_o_`). Para importar do Protheus, preencha `PROTHEUS_DB_*` no `.env` e rode
  `php artisan protheus:centros-custo` (lê a `CTT010`, ou a tabela de `PROTHEUS_TABELA_CTT`; pode rodar quantas vezes
  quiser: insere os novos e atualiza os existentes pela filial + código).
- **Timeline** (Dashboards): uma faixa por máquina ativa (do cadastro de Máquinas) com os estados do dia de produção
  (06:00 às 06:00, configurável em `TIMELINE_INICIO_DIA`) ou de um turno, pintados com a **cor da legenda**; mostra a
  disponibilidade e o tempo/percentual de cada legenda. Clicar numa barra abre o apontamento para corrigir.
- **Apontamentos** (Produção): máquina, horário inicial, horário final (vazio = ainda nesse estado), legenda e
  referência (OP/lote, aparece na barra). Ao registrar o próximo estado, o anterior em aberto é encerrado sozinho;
  horários sobrepostos são recusados. Tabela `apontamentos_estado`.
- **Legendas** (Administração): sigla, descrição, tipo (produzindo, parada não planejada, parada planejada,
  desligada) e **cor** da barra na timeline. A migração já carrega as legendas padrão: PROD, DT, SETUP, WAIT, MANUT,
  QUAL, CALIB, PREV, REF e OFF. Tabela `legendas`.
- **Dashboard por máquina**: OEE, disponibilidade, performance e qualidade de cada máquina por período (hoje, ontem,
  7 dias, 30 dias, mês), calculados dos apontamentos e paradas (`app/Services/IndicadoresOee.php`).
- **Meu perfil**: foto, setor, cargo, e-mail de contato e celular. No login, o portal lê esses dados no Microsoft
  Entra ID (Microsoft Graph `/me`, permissão delegada `User.Read`) e preenche os campos vazios; o botão
  "Atualizar com o Entra ID" troca tudo pelo que está lá. Matrícula (`employeeId`) e IDs são só leitura. As fotos
  ficam em `storage/app/private/fotos` e são servidas pelo próprio portal.
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
| `app/Http/Middleware/` | `UsuarioAtivo` (derruba sessão de quem foi desativado), `ExigePerfil` (`perfil:admin`) e `ExigePermissao` (`permissao:calculadora`) |
| `app/Http/Controllers/Cadastros/` | Administração › Logins: usuários, grupos e histórico de acessos |
| `config/oee.php` | Quem nasce admin e o catálogo de permissões (páginas) que os grupos podem dar |
| `app/Models/` | Modelos Eloquent (`Usuario`, `RegistroLogin`, `Planta`, `Linha`, `Maquina`, ...) |
| `database/migrations/` | Criação das tabelas |
| `docs/banco-de-dados.md` | Documentação e diagrama do banco (gerado por `php artisan banco:documentar`) |
| `config/protheus.php` / `app/Services/Protheus/` | Tabelas sincronizadas do Protheus e o serviço de sincronização |
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

   Se o `migrate` reclamar de permissão (ex.: `REFERENCES` ao criar chaves estrangeiras), use
   `ALTER ROLE db_owner ADD MEMBER oee_app;` só neste banco.

   Para autenticação do Windows, crie o login da conta que roda o portal
   (`CREATE LOGIN [DOMINIO\conta] FROM WINDOWS`) e deixe `DB_USERNAME` e `DB_PASSWORD` vazios.
3. Preencha a seção **Banco de dados** do `.env` (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
   `DB_PASSWORD`, `DB_ENCRYPT`, `DB_TRUST_SERVER_CERTIFICATE`).

### Trocar de servidor ou instância do SQL Server

O portal não guarda nada do servidor fora do `.env`. Para começar do zero num banco novo (ex.: instância `TERRA\SOLO`):

1. Na instância nova: TCP/IP habilitado (SQL Server Configuration Manager), serviço **SQL Server Browser** rodando
   (necessário para `SERVIDOR\INSTANCIA`), autenticação **SQL Server e Windows** e o firewall liberando a instância
   e a porta UDP 1434 do Browser. Crie o banco e o login como no passo 2 acima.
2. `php artisan down` (manutenção), ajuste no `.env` `DB_HOST=TERRA\SOLO`, `DB_PORT=` (vazio), `DB_DATABASE`,
   `DB_USERNAME`, `DB_PASSWORD`, `DB_ENCRYPT=yes` e `DB_TRUST_SERVER_CERTIFICATE=true`.
3. `php artisan config:clear` (senão o cache continua apontando para o banco antigo) e `php artisan banco:testar`.
4. `php artisan migrate --force` (cria todas as tabelas e as legendas padrão) e `php artisan db:seed --force`
   (motivos de parada).
5. `php artisan optimize:clear`, `php artisan optimize` e `php artisan up`.

Todos entram de novo pelo Entra ID: os usuários são recriados no primeiro login (sem acesso, menos os de
`PORTAL_ADMINS`), e grupos, máquinas e demais cadastros precisam ser refeitos. Para levar os dados junto, faça backup do
banco antigo e restaure na instância nova em vez do passo 4 (depois rode só `php artisan migrate --force`).

Para testar sem SQL Server, use `DB_CONNECTION=sqlite` e apague as outras linhas `DB_*` (o banco vira o arquivo
`database/database.sqlite`).

## 3. Rodar

Requisitos: **PHP 8.3** (versão do servidor; o `composer.lock` é gerado nela) e [Composer](https://getcomposer.org).
A pasta `php8.3/` tem o PHP do servidor, com o `php.ini` e as DLLs do SQL Server (`php_sqlsrv_83_nts_x64`,
`php_pdo_sqlsrv_83_nts_x64`).

```sh
composer install
copy .env.example .env          # Linux/macOS: cp .env.example .env — e preencha
php artisan key:generate
php artisan banco:testar        # confere a conexão com o banco
php artisan migrate             # cria as tabelas
php artisan db:seed             # motivos de parada padrão
php artisan serve               # abra http://localhost:8000
```

Entre primeiro com `marcato@alumbra.com.br`: ele nasce **administrador**. Os demais usuários aparecem em
**Administração › Logins › Usuários** depois do primeiro login, **sem acesso**. Para liberar alguém:

1. Em **Administração › Logins › Grupos**, crie os grupos (ex.: "Operadores Linha 1") e marque as páginas que eles podem
   abrir.
2. Em **Administração › Logins › Usuários**, clique em **Editar**, escolha o perfil (**Operador** ou **Administrador**) e
   marque os grupos.

O perfil também pode ser mudado pelo terminal (útil para recuperar o acesso de administrador):

```sh
php artisan usuarios:listar
php artisan usuarios:perfil fulano@alumbra.com.br admin
php artisan usuarios:ativar fulano@alumbra.com.br --desativar
```

Ao mudar o `.env` com o portal em produção, rode `php artisan config:clear` (ou `config:cache`).

### Atualizar o banco

Depois de baixar uma versão nova, rode só `php artisan migrate --force`: as migrações apenas **acrescentam** tabelas e
colunas, nunca apagam nem alteram os registros existentes. **Nunca use `php artisan migrate:fresh`** no servidor: ele
apaga o banco inteiro.

## Integração com o Protheus

O portal **lê** dados do Protheus e copia para as suas próprias tabelas; nunca altera nada no Protheus. Hoje são
sincronizadas duas tabelas (nesta ordem):

| Tabela do portal | Origem no Protheus | Tipo | O que é |
|---|---|---|---|
| `centros_custo` | `Compras.CTT` | **Protheus** (cópia) | Centros de custo; atualizados a cada sincronização |
| `maquinas` | `Compras.SHB` | **Mista** | Máquinas (centros de trabalho) |
| `produtos_protheus` | `Compras.SB1` | **Protheus** (cópia) | Produtos (itens das OPs) |
| `ordens_producao` | `Compras.SC2` | **Protheus** (cópia) + `status` e `custo_meta` do portal | Ordens de produção |
| `movimentos_producao` | `Compras.SD3` | **Protheus** (cópia, só com OP) | Produções, baixas/consumos e devoluções das OPs |
| `notas_fiscais_op` | `Compras.SD2` | **Protheus** (cópia, só com OP) | Itens de nota fiscal que citam uma OP |

**Ordens de produção**: cópia da SC2 com o campo **status**, calculado pelo portal: **fechada** (encerrada no Protheus,
`C2_DATRF` preenchido — é ele que diz se a OP está aberta ou fechada), **parada** (suspensa no Protheus, `C2_STATUS = 'U'`, ou com apontamento de parada
em aberto no portal), **andamento** (apontamento de produção em aberto, produção no Protheus `C2_QUJE > 0` ou
apontamentos) ou **aberta**. A cada sincronização, toda OP recebe do Protheus os campos que mudaram (quantidades,
datas, encerramento…) e tem o status recalculado. A tela **Produção › Ordens de produção** mostra as OPs com o produto (SB1); nos
**apontamentos** dá para informar a OP. Para não trazer anos de histórico, use `PROTHEUS_SC2_DESDE` (AAAAMMDD).
A tabela antiga `produtos` (do Sistema OEE) continua como estava.

**Apontamentos de produção e custo das OPs**: a tela **Produção › Apontamentos de produção** mostra os movimentos do
Protheus ligados a uma OP: da **SD3**, as linhas com o campo da OP preenchido (`D3_ORDEM`, ou o campo de
`PROTHEUS_SD3_CAMPO_OP`) que não estão excluídas nem estornadas (`D3_ESTORNO = 'S'`); da **SD2**, os itens de nota
fiscal com OP (`D2_OP`, ou `PROTHEUS_SD2_CAMPO_OP`), com a legenda **Nota fiscal**. O valor de cada movimento é o
`D3_CUSTO1` / `D2_CUSTO1`. As legendas do `D3_CF` (PR = produção, RE = baixa/consumo, DE = devolução, com a descrição
de cada código) ficam em `config/protheus.php` (`legendas_d3_cf`) e podem ser ajustadas. Na lista de OPs:
- **Custo atual** = (baixas − devoluções) ÷ quantidade produzida (PR);
- **Custo meta** = custo unitário do **fechamento mais recente** do produto na **SB9** (maior `B9_DATA`, na filial
  da OP ou, se não houver, na filial em branco/compartilhada; com vários armazéns, soma de `B9_VINI1` ÷ soma de `B9_QINI`; sem saldo, `B9_CM1`). A SB9 não é
  copiada: só esse valor é gravado em `ordens_producao.custo_meta`, a cada sincronização das OPs;
- **Divergência** = (atual − meta) ÷ meta. Acima da meta além de ±5% (`OEE_CUSTO_TOLERANCIA`) fica em vermelho, abaixo em verde.

Clicando na OP abre o **kardex**: resumo de custo, consumo por componente e todos os movimentos em ordem de data, com
produzido, custo e custo unitário acumulados. A tela antiga de apontamentos agora se chama **Apontamento real**.

**Botão em etapas**: o botão **Sincronizar com o Protheus** não roda tudo numa requisição só (o IIS corta o PHP
depois do `activityTimeout` do FastCGI, 70 s por padrão: "The FastCGI process exceeded configured activity timeout").
Cada etapa trabalha no máximo 25 s (`PROTHEUS_SEGUNDOS_POR_ETAPA`), grava até onde chegou (último `R_E_C_N_O_`, em
`sincronizacoes.ultimo_recno`) e a tela chama a próxima, mostrando o andamento de cada tabela. Mantenha a página
aberta até terminar. Se uma etapa for cortada (ou a página for fechada), clicar de novo **retoma** a execução em
andamento do último lote gravado, em vez de dar erro. Lotes que levam mais de 10 s ficam registrados em
`storage/logs/laravel.log`, para achar o que está lento. Se a SD3 ou a SD2 terminarem com 0 linhas, o resultado diz
o motivo (ex.: o campo da OP está vazio e qual outro campo de OP tem dados). O comando `php artisan protheus:sincronizar` e o agendamento rodam tudo de uma vez (no PHP de
linha de comando não há esse limite).

**Leitura incremental dos movimentos** (SD3 e SD2, que só crescem): depois da primeira vez, cada sincronização lê só os
registros novos e os dos últimos 45 dias (`PROTHEUS_RELER_DIAS`), para pegar estornos e exclusões recentes. Uma vez
por semana (domingo 03:17, `PROTHEUS_SINCRONIZAR_CRON_COMPLETA`, se a sincronização automática estiver ligada) roda a
leitura **completa**; à mão: `php artisan protheus:sincronizar --completa`.

**Sincronização em lotes**: as tabelas grandes (SB1, SC2, SD3, SD2) são lidas do Protheus e gravadas de 1.000 em 1.000 linhas
(`PROTHEUS_SINCRONIZAR_LOTE`), então a memória do PHP não cresce com o tamanho da tabela (60 mil OPs usam cerca de
35 MB). Cada lote é gravado à parte: se a sincronização parar no meio, o que já foi gravado fica. A execução em
andamento dá sinal de vida a cada lote; sem sinal por 3 minutos (`PROTHEUS_SINCRONIZAR_SEM_SINAL`), ela aparece no
histórico como "Interrompida" e libera a próxima sincronização da mesma tabela.

**Máquinas (mista)**: da SHB vêm só **código** (`HB_COD`), **descrição** (`HB_NOME`) e **centro de custo** (`HB_CC`),
na primeira sincronização. A máquina nova chega **inativa** e sem linha; em **Administração › Máquinas** (filtro
"Aguardando cadastro") o usuário escolhe a linha, preenche ciclo ideal, paradas e IoT e marca **Ativa**. Esses três
campos ficam travados na tela. Depois de sincronizada, a máquina **não é mais alterada** pela sincronização, a não ser
que seja **excluída no Protheus** (`D_E_L_E_T_ = '*'`): aí ela é marcada como excluída e desativada (e não pode ser
reativada). Se já existir no portal uma máquina cadastrada à mão com o mesmo código, ela é vinculada ao Protheus em vez
de duplicar.

Todas as outras tabelas são do **Sistema OEE**. A tela **Administração › Banco de dados** mostra a origem de cada
tabela, o botão **Sincronizar com o Protheus** e o histórico das últimas sincronizações (tabela `sincronizacoes`).

**Conexão**: por padrão o Protheus está no **mesmo servidor SQL** do portal e é acessado com o **mesmo usuário**
(`DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`), no banco **`Compras`**, tabela **`CTT`**. O usuário do portal precisa poder
ler essa tabela:

```sql
USE Compras;
CREATE USER oee_app FOR LOGIN oee_app;   -- se ainda não existir neste banco
GRANT SELECT ON dbo.CTT TO oee_app;      -- só leitura
GRANT SELECT ON dbo.SHB TO oee_app;
GRANT SELECT ON dbo.SB1 TO oee_app;
GRANT SELECT ON dbo.SC2 TO oee_app;
GRANT SELECT ON dbo.SD3 TO oee_app;      -- apontamentos de produção (movimentos com OP)
GRANT SELECT ON dbo.SD2 TO oee_app;      -- notas fiscais com OP
GRANT SELECT ON dbo.SB9 TO oee_app;      -- custo meta (fechamento mais recente)
```

Se um dia o Protheus estiver em outro servidor ou com outro usuário, preencha `PROTHEUS_DB_HOST`,
`PROTHEUS_DB_USERNAME` e `PROTHEUS_DB_PASSWORD` no `.env`. Os nomes das tabelas ficam em `PROTHEUS_TABELA_CTT`,
`PROTHEUS_TABELA_SHB`, `PROTHEUS_TABELA_SB1`, `PROTHEUS_TABELA_SC2`, `PROTHEUS_TABELA_SD3`, `PROTHEUS_TABELA_SD2` e
`PROTHEUS_TABELA_SB9`.

Pelo terminal:

```sh
php artisan protheus:sincronizar                 # todas as tabelas
php artisan protheus:sincronizar centros_custo   # só os centros de custo
php artisan protheus:sincronizar maquinas        # só as máquinas (SHB)
```

### Sincronização automática com o Protheus

1. No `.env`, defina quando sincronizar (formato cron) e rode `php artisan config:clear`:

   ```
   PROTHEUS_SINCRONIZAR_CRON="0 * * * *"     # de hora em hora (ex.: "*/30 * * * *" = a cada 30 min)
   ```

2. Deixe o **agendador do Laravel** rodando no servidor. No Windows, escolha um:
   - **Agendador de Tarefas**: crie uma tarefa que roda **a cada 1 minuto**, mesmo sem usuário logado:
     programa `C:\caminho\do\php.exe`, argumentos `artisan schedule:run`, iniciar em `C:\OEE`.
   - **Serviço do Windows** com o [NSSM](https://nssm.cc): `nssm install OEE-Agendador C:\caminho\do\php.exe artisan schedule:work`
     e, em *Startup directory*, `C:\OEE`.
3. Confira com `php artisan schedule:list` e acompanhe pelo histórico na tela Banco de dados (disparo "Automática").

Para acrescentar outra tabela do Protheus no futuro: crie um importador em `app/Services` que implemente
`App\Services\Protheus\ImportadorProtheus` e registre em `config/protheus.php`.

## Publicação no servidor (IIS, em https://www.alumbra.com.br/oee/)

O portal roda como **aplicação do IIS na subpasta `/oee`** do site, com PHP 8.3 próprio (`C:\OEE\php8.3`).

1. **IIS**: no site que atende `www.alumbra.com.br` (com o certificado HTTPS), **Adicionar Aplicativo** com alias
   `oee` e caminho físico `C:\OEE\public`. O `public/web.config` já traz a reescrita para o `index.php` (relativa à
   aplicação) e o handler do PHP 8.3; o `php-cgi.exe` precisa estar liberado em **Configurações do FastCGI** do
   servidor. A conta do pool de aplicativos precisa poder **gravar** em `C:\OEE\storage` e
   `C:\OEE\bootstrap\cache`.
2. **`.env`** do servidor:

   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://www.alumbra.com.br/oee
   ENTRA_REDIRECT_URI="${APP_URL}/auth/callback"
   SESSION_SECURE_COOKIE=true
   ```

   Com subpasta no `APP_URL`, todos os links, o retorno do login e a volta da saída usam esse endereço.
3. **Entra ID** (registro do aplicativo › **Autenticação** › plataforma **Web**), URIs de redirecionamento:
   - `https://www.alumbra.com.br/oee/auth/callback` (retorno do login — obrigatória);
   - `https://www.alumbra.com.br/oee/` (para onde a Microsoft volta depois de **Sair**).
4. No servidor: `php artisan config:clear` (ou `php artisan config:cache`), `php artisan migrate --force` e reinicie o
   pool de aplicativos.

## Acessar de outras máquinas

`http://localhost:8000` só funciona na própria máquina onde o portal roda: em qualquer outra, "localhost" é ela
mesma. Para a equipe acessar pela rede, o portal precisa de um **nome de rede** e de **HTTPS**, porque o Entra ID só
aceita endereço de retorno sem HTTPS quando é `localhost`.

Exemplo com o nome `oee.alumbra.local` e o [Caddy](https://caddyserver.com/download) (servidor web gratuito que cuida
do HTTPS) na frente do portal:

1. **Nome de rede**: peça à TI um registro de DNS `oee.alumbra.local` apontando para o IP do servidor do portal. Para
   um teste rápido, dá para acrescentar a linha `192.168.0.50 oee.alumbra.local` (com o IP do servidor) no arquivo
   `C:\Windows\System32\drivers\etc\hosts` de cada máquina.
2. **Caddy**: no servidor, crie um arquivo `Caddyfile` (pode ser na pasta do Caddy) com:

   ```
   oee.alumbra.local {
       tls internal
       reverse_proxy 127.0.0.1:8000
   }
   ```

   Se a TI fornecer um certificado da empresa, troque `tls internal` por `tls C:\certs\oee.crt C:\certs\oee.key`.
   Com `tls internal`, o navegador avisa que o certificado não é confiável até você instalar nas máquinas o
   certificado raiz do Caddy (no servidor: `%AppData%\Caddy\pki\authorities\local\root.crt`).
3. **Firewall** do servidor: libere a porta **443** (entrada).
4. **`.env`** do servidor:

   ```
   APP_URL=https://oee.alumbra.local
   ENTRA_REDIRECT_URI="${APP_URL}/auth/callback"
   TRUSTED_PROXIES=127.0.0.1
   SESSION_SECURE_COOKIE=true
   ```

5. **Entra ID**: no registro do aplicativo, em **Autenticação**, acrescente a URI de redirecionamento
   `https://oee.alumbra.local/auth/callback` (pode manter a de `localhost` para desenvolvimento).
6. Inicie o portal e o Caddy (num terminal como administrador, por causa da porta 443):

   ```sh
   php artisan config:clear
   php artisan serve
   caddy run
   ```

7. Em qualquer máquina da rede, abra **https://oee.alumbra.local**.

O `php artisan serve` serve para desenvolvimento e testes com poucos usuários. Para uso definitivo, instale o portal
num servidor web (IIS com PHP FastCGI, ou Apache/Nginx) apontando para a pasta `public/`, com o mesmo `.env`.

## Problemas no login

Quando o login falha, a tela mostra o motivo, e ele também fica em **Administração › Logins › Histórico de acessos** e
em `storage/logs/laravel.log`.

| Mensagem | O que fazer |
|---|---|
| "configurado para funcionar só no próprio servidor" | Você abriu o portal de outra máquina, mas `ENTRA_REDIRECT_URI` aponta para `localhost`. Veja "Acessar de outras máquinas". |
| "A sessão do login se perdeu" | Abra o portal pelo endereço de `ENTRA_REDIRECT_URI` (ex.: `http://localhost:8000`, não `127.0.0.1`) e confira se o navegador aceita cookies. |
| `AADSTS7000215` | Em `ENTRA_CLIENT_SECRET` use o **Valor** do segredo, não o "ID do segredo". |
| `AADSTS7000222` | O segredo expirou: crie outro e atualize o `.env`. |
| `AADSTS700016` / `AADSTS90002` | `ENTRA_CLIENT_ID` ou `ENTRA_TENANT_ID` errado (use os GUIDs da página do registro do aplicativo). |
| `AADSTS50011` | A URI de redirecionamento do registro do aplicativo precisa ser igual a `ENTRA_REDIRECT_URI`. |
| `cURL error 60` (certificado SSL) | O PHP não tem a lista de certificados. Baixe o [cacert.pem](https://curl.se/ca/cacert.pem) para `C:\OEE\php8.3\extras\ssl\cacert.pem` (o `php8.3/php.ini` já aponta `curl.cainfo` e `openssl.cafile` para esse arquivo) e rode `iisreset`. Se a rede da empresa inspeciona HTTPS (firewall/proxy), acrescente ao fim do `cacert.pem` o certificado raiz da empresa. |

Depois de mudar o `.env`, rode `php artisan config:clear` e reinicie o `php artisan serve`.

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
