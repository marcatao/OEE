# Versões do aplicativo

## 0.2.0 (build 2)
- Login pelo portal: "Entrar pelo portal" abre o navegador do celular em /app/entrar; o portal entra pelo Entra ID
  (sempre pede a conta e a senha, porque o celular é compartilhado) e devolve um código de uso único ao app, que o
  troca por um token. Recusa quem nunca entrou no portal pelo computador e quem não tem acesso liberado.
- Só entra e sincroniza na rede `wlan_alumbra`.
- Tela principal com o nome, os perfis e os menus conforme as permissões do portal (Máquinas; Ordens de serviço — as
  telas chegam nas próximas versões).
- Bloqueio depois de 48 h sem sincronizar (ou se o relógio do celular voltar para trás); botão "Sincronizar agora".
- "Sair" apaga a sessão do celular e revoga o token no portal.

## 0.1.0 (build 1)
- Primeira versão para testar a instalação: tela inicial com o tema do portal, versão do app, verificação da rede
  Wi-Fi da fábrica (`wlan_alumbra`) e se o portal responde. O botão de entrar ainda está desativado.
