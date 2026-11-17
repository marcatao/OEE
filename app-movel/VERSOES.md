# Versões do aplicativo

## 0.4.1 (build 6)
- Tela da máquina mostra só **HOJE** e só **dentro do turno da máquina** (horário de início e fim do dia de hoje na
  jornada do cadastro da máquina): a barra vai do início ao fim do turno, com a marca de "agora", e a lista traz só
  os apontamentos do turno de hoje. Sem jornada para hoje (ou fora do calendário), usa o padrão da Timeline do
  portal (01:00 às 23:59).

## 0.4.0 (build 5)
- Função **Máquinas** (operador):
  - lista das máquinas ATIVAS com IoT ATIVO (as que pedem ação primeiro), com busca, situação, legenda atual e OP;
    atualiza a cada 30 s;
  - tela da máquina sempre acesa, atualizada a cada 10 s: situação, barra das últimas 24 h com as cores das
    legendas, OP (produto e quantidade produzida), molde com cavidades ativas, roteiro (operação desta máquina em
    destaque) e os apontamentos reais das últimas 24 h;
  - apontamento com legenda **98 (sem motivo)**: faixa piscando, apito + vibração a cada 3 s e janela obrigatória
    para escolher o motivo (só legendas do tipo parada); troca a legenda só daquele apontamento;
  - máquina **sem OP**: faixa piscando, aviso sonoro e janela obrigatória para escolher a OP (só OPs abertas), por
    busca ou **leitura do código de barras**;
  - botão **Trocar OP** (produção mudou) e **Alterar cavidades ativas** do molde (0 até as cavidades do Protheus).

## 0.3.1 (build 4)
- Correção: o nome da rede da fábrica é `Wlan_alumbra` (com W maiúsculo). A comparação agora ignora maiúsculas e
  minúsculas, então o app reconhece a rede.

## 0.3.0 (build 3)
- Visual novo, mais limpo e amigável, no verde do portal (tema claro e escuro):
  - entrada com o logo no topo em degradê e um cartão com a situação da rede Wi-Fi e do portal (ícone, selo
    colorido e explicação), avisos coloridos e botão grande "Entrar pelo portal";
  - tela principal com as iniciais, o nome e os perfis no topo, cartão da sincronização ("há 5 min", rede, botão
    "Sincronizar agora" e aviso de bloqueio) e atalhos grandes para Máquinas e Ordens de serviço;
  - botões de 48–56 dp, bons para o chão de fábrica; ícones claros na barra de status.
- Capturas das telas sem celular (Paparazzi) em `android/app/src/test/snapshots/images/`.

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
