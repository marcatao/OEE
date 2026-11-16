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
1. API no portal e login (o app entra pelo portal; o portal fala com o Entra ID).
2. Banco local e sincronização (fila offline; vale a alteração mais recente pelo horário; o servidor confere as
   regras e devolve "pendente de correção" quando não passar). Bloqueio após 48 h sem sincronizar.
3. Operador: máquinas ativas → timeline da máquina; troca de OP (com leitura do código de barras); cavidades ativas;
   parada 98 (IoT, sem motivo) apita e obriga escolher o motivo (só legendas de parada), trocando a legenda daquele
   apontamento.
4. Mantenedor: SS/OS (abrir, aprovar, concluir); apontamento com relógio (tela travada, botão deslizante "Encerrar
   apontamento", pergunta se fecha a OS e se a máquina estava/está parada); avaliação da OS concluída com assinatura.
5. Alertas: parada 98 da máquina aberta; SS nova toca em todos os mantenedores (vermelho se máquina parada) até o
   primeiro assumir — a SS é aprovada e a OS atribuída a ele.
