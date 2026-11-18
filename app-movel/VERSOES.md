# Versões do aplicativo

## 0.9.0 (build 14)
- **Trocar OP** (tela da máquina): depois de escolher e confirmar a OP, o app pergunta como lançar —
  **"Trocar no apontamento atual"** (todo o apontamento em andamento passa para a OP nova) ou **"Novo apontamento a
  partir de agora"** (encerra o atual com a OP anterior até agora e começa outro na mesma legenda com a OP nova).
  Máquina sem OP (janela obrigatória) grava direto no apontamento atual. Mesma regra da Timeline do portal
  (`App\Services\TrocaOpMaquina`).

## 0.8.1 (build 13)
- Correção (portal): **Recusar** não funcionava no servidor — usava `insertOrIgnore`, que o SQL Server não tem (erro
  no portal). Agora confere e grava; o teste falha se voltar a usar "insert or ignore".
- Alarme com a **tela apagada e o app fechado** mais firme:
  - a vigia não morre com uma resposta inesperada do portal;
  - "despertador" do Android (a cada ~15 min, mesmo em economia) religa o serviço se o sistema o matar; o app
    também religa ao abrir;
  - cartão **"Alarme com a tela apagada"** na tela inicial: mostra o que falta liberar (notificações, fora da economia
    de bateria, tela cheia sobre o bloqueio, volume de alarme) e abre o ajuste de cada item;
  - botão **"Testar com a tela apagada"**: toca um alarme de teste 15 s depois (dá tempo de apagar a tela).

## 0.8.0 (build 12)
- **Alerta de SS**: a CADA toque o app confere no portal se a SS ainda está pendente para quem está tocando
  (`GET /api/app/os/alerta`, consulta leve). Se outro mantenedor assumiu, para de tocar na hora — com o app aberto ou
  fechado.
- Botão **Recusar** no alerta: só aquela SS deixa de tocar para quem recusou (as próximas SS tocam normalmente); a SS
  continua pendente para os outros e na lista dele, marcada "Você recusou" (ele ainda pode assumir). O histórico da SS
  registra **"SS não aceita por <nome> (aplicativo)"**. Portal: tabela nova `os_recusas_aplicativo`.

## 0.7.0 (build 11)
- **Máquinas**: com a máquina **parada** e uma SS aberta pelo apontamento de uma legenda que "abre SS", a lista e a
  tela da máquina mostram o **número da SS (ou da OS, depois de aprovada) e a situação dela** (aguardando análise,
  aberta, em execução, concluída…), para o operador acompanhar o desfecho. Some quando a máquina volta a produzir.
- Portal: a SS aberta pela legenda tem como solicitante o **usuário logado** que apontou (no app ou no portal).

## 0.6.1 (build 10)
- Correção (portal): encerrar o apontamento de horas respondia **403** no servidor. No SQL Server os ids chegam como
  texto e a conferência "o apontamento é deste mantenedor?" comparava texto com número. O mesmo cuidado foi aplicado
  ao "meu apontamento" da OS e à troca de OP da máquina. Teste novo simula os ids em texto.
- App: mostra a mensagem que o portal devolve (antes só "erro 403") e, se o portal recusar o encerramento, oferece
  "Desfazer o encerramento e voltar ao cronômetro" para não ficar preso na tela travada.

## 0.6.0 (build 9)
- **Alertas com o app fechado e a tela desligada**: serviço em primeiro plano (notificação fixa "OEE Alumbra")
  que consulta o portal a cada 20 s, mantendo CPU e Wi-Fi ligados:
  - mantenedor/aprovador: SS esperando alguém assumir — toca até o primeiro assumir; **SS de máquina parada** toca
    o alarme forte, acende a tela e abre o app por cima da tela de bloqueio;
  - operador: **parada sem motivo (98)** da máquina que ficou aberta no app.
  - Notificação com "Silenciar 3 min"; volta a vigiar sozinho quando o celular é religado.
- Na primeira vez o app pede: permitir notificações, ficar fora da economia de bateria e (Android 14+) abrir em tela
  cheia sobre o bloqueio.

## 0.5.1 (build 8)
- Correção: em aparelhos com Android mais antigo o app dizia "Portal sem resposta" mesmo com o portal abrindo no
  Chrome. O certificado do portal (Sectigo) termina na raiz "Sectigo Public Server Authentication Root R46", que esses
  Androids não têm de fábrica (o Chrome tem a lista própria). O app agora confia nessa raiz só para alumbra.com.br
  (`res/raw/sectigo_root_r46.pem` + `res/xml/network_security_config.xml`).
- Quando o portal não responde, o app mostra o motivo (certificado, DNS, tempo esgotado, conexão recusada).

## 0.5.0 (build 7)
- Função **Ordens de serviço** (mantenedor):
  - **Solicitações**: SS aguardando um mantenedor; **Assumir** = aprovar a SS e ficar como mantenedor principal da OS
    (o primeiro que assume leva; os outros veem "já assumida por …"). Também mostra as SS que o usuário abriu.
  - **Alerta de SS nova**: aviso no topo e toque em todos os mantenedores da área até alguém assumir; **vermelho e
    mais insistente** quando a máquina está parada; "Silenciar 3 min". (Por enquanto com o app aberto.)
  - **Abrir SS** (quem tem o perfil Solicitante): área executante, máquina da área, tipo, prioridade, máquina parada.
  - **Minhas OS** → detalhe com setor, centro de custo, solicitante, apontamentos; aguardando material / retomar /
    concluir.
  - **Apontamento com cronômetro**: ao iniciar, o app TRAVA na tela do cronômetro (voltar não sai) mostrando a OS, o
    setor, o centro de custo e o solicitante; só o botão deslizante **Encerrar apontamento** libera, pedindo o que foi
    feito, se conclui a OS e se a máquina estava/está parada. Sem rede, o fim fica guardado e é reenviado.
  - **Avaliar**: OS concluídas pelo mantenedor; o solicitante vê o resumo (pedido e o que foi feito), dá a nota
    (Excelente / Boa / Ruim — Ruim devolve a OS) e **assina com o dedo**.
- Tela da máquina: depois de 1 min sem toque a tela **escurece** (economia de bateria e menos calor); toque acende,
  e qualquer alerta (parada sem motivo, máquina sem OP) volta ao brilho total.

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
