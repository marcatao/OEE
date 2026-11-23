# Implantação em produção — o que fazer, na ordem

Lista única para colocar no ar o portal OEE Alumbra, o Integrador MQTT e o aplicativo Android com tudo o que foi feito
até 23/11/2026 (auditorias de segurança e desempenho, SS automática pelo IoT, peças produzidas, produção por hora,
importação de usuários do Entra ID). Marque cada item ao concluir.

## 1. Segurança (antes de tudo)

Os repositórios ficaram públicos por um tempo e o histórico tem um `.env` antigo com segredos. Tornar privado não
apaga o que já foi copiado: **troque os segredos**.

- [ ] Repositórios `marcatao/OEE` e `marcatao/MQTT` privados (GitHub › Settings › Danger Zone › Change visibility).
- [ ] **Entra ID**: portal do Azure › Registros de aplicativo › OEE Alumbra › Certificados e segredos: **excluir o
      segredo antigo** e criar um novo. Anotar o valor para o `.env` (`ENTRA_CLIENT_SECRET`).
- [ ] **SQL Server**: trocar a senha do `sa`. O portal NÃO deve usar o `sa`: criar (ou conferir) o login `oee_app` com
      acesso só ao banco do portal e leitura no Protheus (README, seção "Preparar o SQL Server").
- [ ] **APP_KEY nova** (passo 3): todos precisarão entrar de novo.
- [ ] Chave do APK (`app-movel/android/keystore/`): com o repositório privado, manter. Trocar obriga reinstalar o app
      em todos os celulares — só se decidirem que o risco não é aceitável.

## 2. Microsoft Entra ID (registro do aplicativo)

- [ ] Permissão de **aplicativo** `User.Read.All` (Microsoft Graph › Permissões de aplicativo) + **Conceder
      consentimento do administrador** — para o botão "Importar usuários do Entra ID" e a importação diária (04:47).
- [ ] URI de redirecionamento: `https://www.alumbra.com.br/oee/auth/callback`.
- [ ] `ENTRA_TENANT_ID` no `.env` = o ID do locatário (GUID). Com "common" ou domínio, **ninguém entra** (proteção).

## 3. Servidor do portal (IIS, pasta do OEE)

1. [ ] Atualizar o código (`git pull` na `main`). Com o repositório privado, o servidor precisa de credencial:
       token de acesso pessoal ou chave de implantação (GitHub › Settings › Deploy keys, só leitura).
2. [ ] `composer install --no-dev --optimize-autoloader`
3. [ ] Conferir o **`.env`** (modelo em `.env.example`):

   | Variável | Valor |
   | --- | --- |
   | `APP_ENV` | `production` |
   | `APP_DEBUG` | `false` |
   | `APP_URL` | `https://www.alumbra.com.br/oee` |
   | `LOG_LEVEL` | `warning` |
   | `ENTRA_CLIENT_SECRET` | o segredo NOVO (passo 1) |
   | `DB_USERNAME` / `DB_PASSWORD` | `oee_app` e a senha dele (não o `sa`) |
   | `CACHE_STORE` | `file` (alivia o SQL Server) |
   | `SESSION_SECURE_COOKIE` | `true` (ou deixar vazio: segue o https do `APP_URL`) |
   | `SESSION_PATH` | `/oee` (ou deixar vazio: segue o `APP_URL`) |
   | `SESSION_COOKIE` | `oee-alumbra-sessao` (nome próprio, não colide com o outro site do servidor) |
   | `IOT_TOKEN` | token do Integrador MQTT, **32+ caracteres** (gerar: `php -r "echo bin2hex(random_bytes(32));"`) |
   | `ANTHROPIC_API_KEY` | chave do assistente "Pergunte para Alumbra" (se for usar) |

4. [ ] `php artisan key:generate --force` (APP_KEY nova; derruba as sessões abertas).
5. [ ] **Fora do horário de produção**: `php artisan migrate --force` (cria colunas e índices em
       `apontamentos_estado`, tabela grande) e `php artisan banco:procedures` (procedures das sincronizações).
6. [ ] `php artisan optimize` (cache de configuração, rotas e telas). Rodar de novo a cada atualização do `.env` ou do código.
7. [ ] **PHP** (`php8.3/php.ini` versionado): OPcache ligado e `expose_php = Off`. Copiar para o PHP do servidor se
       ele não usa esta pasta. **Reciclar o pool do IIS** para valer.
8. [ ] **Agendador**: a tarefa do Windows de `scripts/windows/instalar-agendador.ps1` (roda `schedule:run` a cada
       minuto). Na tela Segurança › Banco de dados aparece se ele está vivo. Ele roda: sincronizações do Protheus,
       preventivas (05:10), importação do Entra ID (04:47), conferência das peças (a cada 5 min), pedidos de compra.
9. [ ] `public/web.config` (só com o seu OK): trocar `errorMode="Detailed"` por `DetailedLocalOnly` (hoje um erro
       mostra caminhos e código a qualquer visitante).
10. [ ] `public/default.php` (arquivo de diagnóstico "DEFAULT OK"): apagar, se não for usado pelo IIS.
11. [ ] Opcional, no SQL Server: `ALTER DATABASE [OEE_Alumbra] SET READ_COMMITTED_SNAPSHOT ON` (a Timeline e o app
        não esperam as gravações do IoT). Fora do horário, com o banco sem conexões.

## 4. Integrador MQTT (computador do Mosquitto)

- [ ] Instalar a **v0.1.27** (`MQTT-SQLServer-windows.zip`, release do repositório `marcatao/MQTT`).
- [ ] `config.toml`, seção `[oee]`:
      `portal_url = "https://www.alumbra.com.br/oee"` e `portal_token = "<o mesmo IOT_TOKEN do portal>"`
      (ou a variável de ambiente `OEE_PORTAL_TOKEN`). Sem isso: sem SS automática do IoT e sem peças.
- [ ] O computador precisa acessar `https://www.alumbra.com.br/oee` (se não, aparece um AVISO no log do integrador).
- [ ] Permissões do login `mqtt` no banco do portal: `sql/permissoes_oee.sql` (já cobre as colunas novas).

## 5. Aplicativo Android

- [ ] Instalar o APK **0.12.0 (build 17)** nos celulares (operadores e mantenedores) — tem o cartão "Produção do turno" e os "Detalhes da OP" em abas (precisa do portal atualizado e do `php artisan optimize`, senão o botão abre com erro). O token do app agora vale **30 dias**:
      depois disso, o app pede login de novo. Celular perdido: Segurança › Usuários › Editar › "Desconectar".

## 6. Usuários

- [ ] Segurança › Usuários › **Importar usuários do Entra ID**: as contas chegam **inativas e sem perfil**. Para
      liberar: Editar › Ativo + perfis. Quem não tem perfil vê só "Sem perfil cadastrado".

- [ ] Telas novas para liberar nos grupos/perfis, se quiser: **Dashboards › Produção por hora** (permissão
      `producao_hora`; o operador já vê as máquinas das unidades dele).

## 7. Conferência depois de publicar (5 minutos)

- [ ] Login pelo Entra ID funciona; `https://www.alumbra.com.br/OEE` (maiúsculas) leva para `/oee`.
- [ ] Timeline abre e atualiza; cartão da máquina mostra "pç/min".
- [ ] Integrador: na aba Apontamentos reais, sem AVISO de portal; numa OP em produção, "Quantidade real (IoT)" sobe.
- [ ] Legenda com "Abre SS" apontada pelo IoT abre a SS (log do integrador: "o portal abriu a SS ...").
- [ ] Trocar OP (modo novo) numa máquina com IoT: depois de 3 min a OP nova continua.
- [ ] App: tela da máquina com o cartão "Produção do turno".
- [ ] Segurança › Banco de dados: agendador vivo e sincronizações em dia.
