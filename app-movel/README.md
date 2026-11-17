# Aplicativo OEE Alumbra (Android)

Aplicativo nativo (Kotlin + Jetpack Compose) do portal OEE Alumbra. Funciona offline com banco local e sincroniza
com o portal só na rede Wi-Fi da fábrica (`Wlan_alumbra`). Todas as tabelas e regras de negócio são as do portal:
o app conversa com uma API do próprio portal (rotas `/api/app/...`, neste repositório), que usa os mesmos serviços
e permissões das telas.

- Pacote: `br.com.alumbra.oee` (Android 8.0 / API 26 ou mais novo).
- Distribuição: privada, só por arquivo APK.
- Versão em `android/versao.properties` (`codigo` sobe 1 a cada APK; `nome` = versão mostrada). Histórico em
  `VERSOES.md`.
- Assinatura: `android/keystore/` (chave de distribuição interna, mantida no repositório com autorização do usuário).
  Toda versão precisa da MESMA chave, senão o celular não atualiza por cima da anterior. Não troque a chave.

## Gerar o APK

Precisa de JDK 17+ e do Android SDK (plataforma 35). Crie `android/local.properties` com `sdk.dir=<pasta do SDK>`.

```sh
cd app-movel/android
./gradlew assembleRelease
# APK: app/build/outputs/apk/release/oee-alumbra-<versão>-<build>.apk
```

## Visual

- Cores só em `ui/Tema.kt` (as mesmas variáveis de `public/css/portal.css`, claro e escuro); componentes comuns
  (cartão, selo, linha de estado, aviso, botão principal, atalho) em `ui/Componentes.kt`.
- Capturas das telas sem celular: `./gradlew recordPaparazziDebug` grava os PNG em
  `app/src/test/snapshots/images/` (testes em `app/src/test/.../CapturasTelasTest.kt`). Tela nova: inclua uma captura.

## Plano

0. Estrutura, tema verde do portal, verificação da rede Wi-Fi e do portal. ✔ (0.1.0)
1. API no portal e login. ✔ (0.2.0) O app abre `/app/entrar` no navegador; o portal faz o login pelo Entra ID
   (`prompt=login`), recusa quem nunca entrou no portal ou não tem acesso, e devolve um código de uso único
   (`br.com.alumbra.oee://login?codigo=`) que o app troca por um token em `POST /api/app/token` (segredo tipo PKCE).
   Token: só o hash em `tokens_aplicativo`; cai no "Sair", sem uso por `oee.app.dias_sem_uso` dias ou sem acesso.
   API: `routes/api.php` (`/api/app/...`, middleware `aplicativo`, token em `Authorization: Bearer` ou `X-Token-App`).
2. Banco local e sincronização (fila offline; vale a alteração mais recente pelo horário; o servidor confere as
   regras e devolve "pendente de correção" quando não passar). Bloqueio após 48 h sem sincronizar.
3. Operador — Máquinas. ✔ (0.4.0) API `GET /api/app/maquinas` (ativas + `Maquina::comIotAtivo()`), `GET
   /api/app/maquinas/{id}` (apontamentos de HOJE dentro do turno da máquina — `MaquinasAplicativo::periodoDeHoje`:
   jornada do dia em `JornadaSemanal` + `DiasUteis::trabalha`; sem jornada, o padrão da Timeline 01:00–23:59 —, pendências "sem motivo" dos
   últimos `oee.app.dias_pendencia_sem_motivo` dias, OP com roteiro e molde, legendas de motivo), `POST
   /api/app/apontamentos/{id}/motivo`, `POST /api/app/maquinas/{id}/op`, `GET /api/app/ops`, `POST
   /api/app/ferramentas/{id}/cavidades` — regras em `App\Services\Aplicativo\MaquinasAplicativo`. Consultar:
   permissão `apontamentos` ou `timeline`; alterar: `apontamentos`.
   - Legenda "sem motivo" = `oee.app.legenda_sem_motivo` (98, gravada pelo IoT); motivos = legendas ativas dos tipos
     `oee.app.tipos_legenda_motivo` (parada). O motivo troca a legenda SÓ daquele apontamento.
   - Trocar OP: a máquina fica com a OP; o apontamento em aberto sem OP recebe a OP; com OUTRA OP, é encerrado 1 s
     antes de agora e um novo (mesma legenda, OP nova) começa agora.
   - Cavidades ativas: o operador também altera (pedido do usuário), de 0 até H4_QTDCAV.
   - Esta tela precisa da rede da fábrica (mostra os últimos dados recebidos se a rede cair).
4. Mantenedor — Ordens de serviço. ✔ (0.5.0) API `/api/app/os/...` (`routes/api.php`), regras em
   `App\Services\Aplicativo\OrdensServicoAplicativo` sobre `AcessoOs` e `FluxoOrdemServico` (as mesmas do portal):
   `resumo` (pendentes para assumir, minhas OS, para avaliar, apontamento rodando — consultado a cada 15 s), `assumir`
   (aprova a SS com o mantenedor como principal; trava a SS na transação: só o primeiro leva), `rejeitar`, abrir SS
   (chama `SolicitacaoController::store`, mesmas validações), `ordens`, `ordens/{id}`, `iniciar`, `encerrar` (fim
   informado pelo celular, entre o início e agora; grava "máquina parada" na OS; conclui se pedido), `acao`
   (aguardar material, retomar, concluir), `avaliacoes`, `avaliar` (avaliação do SOLICITANTE no celular do mantenedor:
   `FluxoOrdemServico::avaliar` com o solicitante + assinatura PNG em `os_assinaturas`).
   Pendências: alerta de SS com o app fechado (Etapa 5, serviço em segundo plano).
   Regras pedidas: SS/OS (abrir, aprovar, concluir); apontamento com cronômetro: o app TRAVA na tela (sem voltar nem
   sair; o cronômetro segue com o celular bloqueado) mostrando a OS atendida, o setor, o centro de custo e o
   solicitante que vai avaliar; só o botão deslizante "Encerrar apontamento" libera, gravando início e fim e
   perguntando se fecha a OS e se a máquina estava/está parada. Avaliação da OS concluída no celular do mantenedor,
   com o resumo da SS e do que foi feito, nota do solicitante e assinatura com o dedo.
5. Alertas: parada 98 da máquina aberta; SS nova toca em todos os mantenedores (vermelho se máquina parada) até o
   primeiro assumir — a SS é aprovada e a OS atribuída a ele.
