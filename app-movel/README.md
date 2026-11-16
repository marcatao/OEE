# Aplicativo OEE Alumbra (Android)

Aplicativo nativo (Kotlin + Jetpack Compose) do portal OEE Alumbra. Funciona offline com banco local e sincroniza
com o portal só na rede Wi-Fi da fábrica (`wlan_alumbra`). Todas as tabelas e regras de negócio são as do portal:
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

## Plano

0. Estrutura, tema verde do portal, verificação da rede Wi-Fi e do portal. ✔ (0.1.0)
1. API no portal e login. ✔ (0.2.0) O app abre `/app/entrar` no navegador; o portal faz o login pelo Entra ID
   (`prompt=login`), recusa quem nunca entrou no portal ou não tem acesso, e devolve um código de uso único
   (`br.com.alumbra.oee://login?codigo=`) que o app troca por um token em `POST /api/app/token` (segredo tipo PKCE).
   Token: só o hash em `tokens_aplicativo`; cai no "Sair", sem uso por `oee.app.dias_sem_uso` dias ou sem acesso.
   API: `routes/api.php` (`/api/app/...`, middleware `aplicativo`, token em `Authorization: Bearer` ou `X-Token-App`).
2. Banco local e sincronização (fila offline; vale a alteração mais recente pelo horário; o servidor confere as
   regras e devolve "pendente de correção" quando não passar). Bloqueio após 48 h sem sincronizar.
3. Operador: máquinas ativas → timeline da máquina; troca de OP (com leitura do código de barras); cavidades ativas;
   parada 98 (IoT, sem motivo) apita e obriga escolher o motivo (só legendas de parada), trocando a legenda daquele
   apontamento.
4. Mantenedor: SS/OS (abrir, aprovar, concluir); apontamento com cronômetro: o app TRAVA na tela (sem voltar nem
   sair; o cronômetro segue com o celular bloqueado) mostrando a OS atendida, o setor, o centro de custo e o
   solicitante que vai avaliar; só o botão deslizante "Encerrar apontamento" libera, gravando início e fim e
   perguntando se fecha a OS e se a máquina estava/está parada. Avaliação da OS concluída no celular do mantenedor,
   com o resumo da SS e do que foi feito, nota do solicitante e assinatura com o dedo.
5. Alertas: parada 98 da máquina aberta; SS nova toca em todos os mantenedores (vermelho se máquina parada) até o
   primeiro assumir — a SS é aprovada e a OS atribuída a ele.
