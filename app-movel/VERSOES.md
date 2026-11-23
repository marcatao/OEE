# Versões do aplicativo

## 0.12.4 (build 21)

- Máquinas rápidas (mais de uma peça por segundo): o ciclo médio aparece com milissegundos ("Ciclo 0,040 s"; acima de
  10 s, "32,0 s"), pelo `ciclo_medio_texto` do portal. Peças por minuto, meta e peças no turno com separador de milhar
  ("12.000,0 pç/min"); números grandes usam letra um pouco menor para não quebrar o cartão de produção.

## 0.12.3 (build 20)

- As peças do IoT passam a se chamar **Contagem IoT** em todo o app (cartão da OP na tela da máquina e Detalhes da
  OP), um campo a mais da OP. "Quantidade" (C2_QUANT) e "Produzido" (Protheus) continuam como vêm do Protheus,
  separados.

## 0.12.2 (build 19)

- Detalhes da OP › OP: cartão em destaque **Contagem IoT** — peças contadas pelo IoT na OP (as mesmas da Produção por
  hora), % da quantidade da OP com barra, peças de hoje, horário da última contagem e, com mais de uma máquina, as
  peças de cada máquina.

## 0.12.1 (build 18)

- Detalhes da OP › Engenharia: a estrutura do produto mostra a **vigência** (ex.: "Vigência: 15/03/2026 até
  31/12/2026", "sem limite") ao lado da revisão, e cada componente com data mostra a dele ("Vale de … até …").

## 0.12.0 (build 17)

- Máquinas: botão **Detalhes da OP** no cartão da OP (tela da máquina). Abre a OP em abas, com os mesmos dados da tela
  da OP no portal:
  - **OP**: situação, produzido × quantidade, quantidade real (IoT), datas, roteiro, revisão, armazém, centro de custo
    e custo por peça (simulado, meta, Protheus, divergência) com o consumo por componente;
  - **Kardex**: produções, baixas, devoluções e notas, do mais recente para o mais antigo (até 400);
  - **Empenhos**: empenhado, baixado, saldo e % atendido de cada componente;
  - **Engenharia**: estrutura padrão (por peça, necessidade, empenhado, diferença) e roteiro (máquina, molde e
    cavidades, tempos padrão).
- Sem rede, os detalhes da OP abrem com o último dado guardado no celular.

## 0.11.0 (build 16)

- Máquinas: novo cartão "Produção do turno" na tela da máquina, abaixo da linha do tempo — peças por minuto agora
  (em destaque), total de peças do turno, ciclo médio e cavidades, e gráfico de barras de peças por hora do turno
  (hora atual destacada). Quando o portal mandar a meta (pç/min), aparece a linha tracejada da meta por hora.
- Resumo da OP mostra a "Quantidade real (IoT)" e cada apontamento mostra as peças contadas.
- Sem rede ao abrir a máquina: mostra o último dado guardado no celular (apagado ao sair ou perder o acesso).

## 0.10.0 (build 15)
- **Acesso por perfil** (o app e a API do portal conferem igual):
  - **Máquinas**: só quem tem o perfil **Operador** no login;
  - **Ordens de serviço**: só quem tem um perfil de **Mantenedor** (de Produção, Tecnologia ou Ferramentaria).
- **Máquinas não alertam com o app fechado**: o alerta de parada sem motivo e de OP pendente só acontece com a tela
  daquela máquina aberta (como já fazia). O serviço em segundo plano fica só para as SS dos mantenedores (e só é
  ligado para eles); o cartão "Alarme com a tela apagada" aparece só para mantenedores.

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
