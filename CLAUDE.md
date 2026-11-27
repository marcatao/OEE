# OEE Alumbra — instruções para o Claude

Portal Laravel 13 (PHP 8.3, versão do servidor) de dashboards de produção e cálculos de OEE. Login pelo Microsoft Entra ID, dados no
SQL Server. Escreva código, textos de tela, comentários e mensagens de commit em português do Brasil.

## Regras do projeto

- O **Entra ID só autentica** a entrada (`app/Services/EntraId.php`). O usuário é cadastrado no banco no primeiro
  login (`usuarios`, chave `entra_oid`, nunca o e-mail) e nasce **sem acesso** (sem perfil não vê nada: tela "Sem perfil cadastrado", `unidadesVisiveis()` = []); só os e-mails de `config('oee.admins')`
  (padrão `marcato@alumbra.com.br`) nascem `admin`. Todo login vai para `registros_login`. Importação de todas as contas do Entra ID
  (`App\Services\ImportadorUsuariosEntra`, botão em Segurança › Usuários e `usuarios:importar-entra` diário; permissão de
  APLICATIVO `User.Read.All`): só membros habilitados, novos nascem INATIVOS (bloqueados) e sem perfil; nunca altera quem existe.
- Três áreas (unidades): **Manutenção** (produção), **Tecnologia** e **Ferramentaria**. Acesso por **perfis** (vários
  por usuário, tabela `usuario_perfis`, definição em `config('oee.perfis_acesso')`: operador; solicitante, mantenedor e
  administrador de cada área — `solicitante_manutencao|tecnologia|ferramentaria`, `mantenedor_producao|tecnologia|
  ferramentaria`, `admin_producao|tecnologia|ferramentaria`; mapas `oee.admins_area` e `oee.mantenedores_area`; cada
  perfil com unidades e permissões de página) + **grupos** (somam páginas, `grupo_permissoes`, catálogo em
  `config('oee.permissoes')`). Usuário sem linhas em `usuario_perfis` segue a coluna antiga `perfil` (admin = todos os
  perfis, operador = operador). Administrador de Tecnologia abre todas as PÁGINAS e é o único na aba **Segurança**
  (usuários, grupos, banco, aplicativo: `perfil:admin_tecnologia`; controle de acessos por permissão restrita, ver abaixo), mas DADOS só da Tecnologia: ninguém vê unidade que
  não está habilitada nos perfis dele (cadastros, filtros, listas, SS/OS, app; `AcessoOs::unidadesHabilitadas` =
  unidades dos perfis + as em que é Solicitante; `AcessoOs::filtrar` nunca passa delas); quem é APENAS solicitante vê só as SS/OS
  que abriu e as do seu centro de custo (`AcessoOs::centroCustoDo`, do Entra ID; sem centro de custo, só as suas), nas
  unidades em que é solicitante; cadastros = `perfil:admin_producao,admin_tecnologia,
  admin_ferramentaria`. Use `Usuario::pode()`, `ehAdmin()`, `podeSeguranca()`, `temPerfil()`, `areasAdministradas()` e
  `App\Support\Unidades::areas()` (nunca escreva a lista de áreas fixa no código).
- **Controle de logins e acessos** (Segurança, pedido do usuário em 26/11/2026; dados PESSOAIS): Painel de logins
  (`cadastros.painel-acessos`, `App\Services\Seguranca\PainelAcessos`: entradas por dia/hora no fuso local, recusas por
  motivo, ativos hoje = login, página ou app, páginas mais abertas, último uso de cada usuário ativo com "nunca entrou" e
  "sem uso há N dias" para bloquear), Histórico de logins (`cadastros.acessos`, filtros período/usuário/e-mail/resultado/
  motivo/IP, sem CSV), Acesso às páginas (`cadastros.acessos-paginas`) e Sessões ativas (`cadastros.sessoes`,
  `SessoesAtivas`: tabela `sessions` com SESSION_DRIVER=database + tokens válidos do app; "Desconectar" apaga a sessão ou
  revoga o token; o id da sessão nunca vai para a tela, só o SHA-256). Permissões `seguranca_painel|historico|paginas|
  sessoes` são RESTRITAS (`oee.permissoes_restritas`): o `*` dos administradores de área NÃO as cobre; só o Administrador
  de Tecnologia ou quem as recebe num grupo ou DIRETO no usuário. Usuários e Grupos continuam `perfil:admin_tecnologia`.
  **Liberação direta por usuário**: `usuario_permissoes` (Segurança › Usuários, "Páginas liberadas só para este usuário";
  `Usuario::permissoesDiretas`/`definirPermissoesDiretas`; `permissoes()` = grupos ativos + diretas; exige perfil, como
  os grupos). Middleware `RegistrarAcessoPagina` (grupo web) grava em `acessos_paginas` todo GET HTML 200 de usuário
  logado (não JSON/AJAX/`?parcial=1`/API) e `usuarios.ultimo_acesso_em` (no máx. a cada 5 min, direto na tabela);
  retenção `oee.acessos_paginas.dias_retencao` (180; 0 = para sempre) pelo `acessos:limpar-paginas` (diário 03:23, em
  lotes, apaga SÓ dessa tabela — `registros_login` nunca é apagado). Tela nova de Segurança com dados de pessoas: use
  `partials.usuario-seguranca` (link para o cadastro só para quem tem `podeSeguranca()`).
- Todo cadastro tem **unidade** (`manutencao` | `tecnologia` | `ferramentaria` | `ambos` = "Todas", trait
  `App\Models\Concerns\TemUnidade`): escopo global mostra ao usuário logado só as unidades dele + "ambos" (quem tem
  perfis das três unidades vê tudo; sem usuário logado, sem filtro). No formulário use `@include('partials.campo-unidade')` e
  `App\Support\Unidades::regras()` (cada área só para o administrador dela; "Todas" para quem administra mais de uma).
  Cadastro novo de qualquer tipo deve ganhar a coluna `unidade` (padrão `manutencao`) e o trait.
- Banco principal: **SQL Server** (`DB_CONNECTION=sqlsrv`, configurado no `.env`). Testes rodam em SQLite em memória:
  tudo precisa funcionar nos dois. Recursos só do SQL Server ficam atrás de `DB::getDriverName() === 'sqlsrv'`.
- Datas gravadas em **UTC** (`config/app.php` usa `UTC`) e exibidas no fuso local com `@dataHora($valor)`.
- Tabelas e colunas em português. Modelos de cadastro usam o trait `App\Models\Concerns\Auditavel`
  (`criado_em`, `criado_por_id`, `atualizado_em`, `atualizado_por_id`). Cadastros são desativados, não excluídos.
- Nome do portal: **OEE Alumbra**, em `config('oee.nome')` (nunca escreva o nome direto nas telas).
- Identidade visual em **tons de verde** (estilo da empresa): as cores ficam só nas variáveis do topo de
  `public/css/portal.css` (tema claro e escuro). Nunca use cores soltas nas telas. Contraste WCAG 2.2 AA (revisado em 26/11/2026): texto ≥ 4,5:1 e componentes/gráficos ≥ 3:1 nos dois
  temas (verde = identidade e ações; vermelho = erro; âmbar = alerta; azul = informação; cinza = neutro); campos de
  formulário usam `--borda-campo`; área com rolagem própria leva `tabindex="0"` + `role="region"` + `aria-label`.
  **Tema e notificações no topo** (26/11/2026; `layouts.partials.usuario`): botão claro/escuro (`[data-alternar-tema]`;
  sem escolha segue o sistema; a escolha fica em `localStorage` `oee-tema` e vira `data-tema` no `<html>` antes de
  desenhar; o tema escuro está em `@media (prefers-color-scheme: dark) :root:not([data-tema="claro"])` E em
  `:root[data-tema="escuro"]` — mude os DOIS; gráficos em canvas redesenham no evento `oee-tema`). Sino
  (`layouts.partials.notificacoes`, `[data-notificacoes]`): as notificações do usuário ficam SÓ nele, nunca em faixas no
  topo das páginas (mensagens de retorno de uma ação, `session('ok'|'erro')`, continuam na página). Fonte única
  `App\Services\Notificacoes\NotificacoesUsuario` (SS para atender, OS para avaliar, termos para assinar; id estável
  por item); rota `notificacoes` (JSON, SO_LOGIN) consultada a cada minuto; item novo com o navegador minimizado ou sem
  foco = pop-up do Windows (Notification API, permissão pelo botão "Avisar no Windows"; só com o portal aberto em
  alguma aba). Notificação nova no sistema? Acrescente em `NotificacoesUsuario`.
- Telas em Blade com `layouts.portal` e o CSS de `public/css/portal.css`. Sem etapa de build com Node.
  Listas longas (centro de custo, máquina, usuário, mantenedor): use `<select data-busca>` (lista com busca) e, para
  marcar vários, o partial `partials.escolha-multipla` (abre/fecha, com busca) — ambos em `public/js/componentes.js`.
- Toda rota interna fica no grupo `auth`. `tests/Feature/AcessoDiretoRotasTest.php` tenta TODAS as rotas sem a permissão (rota
  só com login precisa estar justificada em `SO_LOGIN`). Página nova: registre a chave em `config/oee.php` (`permissoes`), proteja a
  rota com `->middleware('permissao:chave')` e inclua o link no menu lateral (`layouts/portal.blade.php`) dentro de
  `@if ($u->pode('chave'))`. Páginas só de admin usam `->middleware('perfil:admin')`.
- **Dados existentes são intocáveis**: migrações NUNCA apagam nem alteram registros (nada de `DELETE`, `UPDATE`,
  `truncate`, `migrate:fresh`, nem trocar o significado de uma coluna com dados). Mudança de estrutura só ACRESCENTA
  (coluna nova, tabela nova). Se for preciso mexer em registros (converter, copiar, limpar), **pare e avise o
  usuário**, explicando o que mudaria, e só faça depois que ele decidir (de preferência num comando separado, nunca
  escondido numa migração).
- Mudou uma migração? Rode `php artisan banco:documentar` e faça commit de `docs/banco-de-dados.md` junto.
- **Código novo NUNCA acessa o Protheus direto** (pedido do usuário em 26/11/2026): cópias novas são feitas por
  procedure do banco do portal (MERGE; o PHP só chama). Os importadores PHP antigos abaixo continuam até serem migrados.
- Dados do Protheus são só **lidos** (conexão `protheus`, banco `Compras`): tabelas sincronizadas e importadores ficam
  em `config/protheus.php`; a sincronização (`App\Services\Protheus\SincronizadorProtheus`) grava o histórico em
  `sincronizacoes`. `centros_custo` é cópia da CTT; `maquinas` é **mista** com a SHB (código, nome e centro de custo
  vêm do Protheus só na primeira vez, chegam inativas; depois só a exclusão no Protheus altera — ver
  `ImportadorMaquinas`). Nas máquinas do Protheus esses três campos não se editam no portal. `produtos_protheus`
  (SB1) e `ordens_producao` (SC2) são cópias (`ImportadorEspelho`: lê o Protheus em páginas por R_E_C_N_O_ e grava
  em lotes de `protheus.lote` linhas — nunca carregue uma tabela do Protheus inteira na memória); a OP tem o campo
  `status` (aberta | andamento | parada | fechada) calculado em `App\Services\StatusOrdemProducao` (fechada SÓ pelo
  `C2_DATRF` preenchido; vazio = aberta/andamento/parada). Toda sincronização atualiza qualquer campo da OP que mudou
  no Protheus e recalcula o status de TODAS as OPs, por lote (`StatusOrdemProducao::atualizarLote`, autorizado pelo
  usuário); recalcule também ao gravar/excluir apontamentos com OP. A tabela antiga `produtos` (Sistema OEE) não é a do Protheus.
- `empenhos_op` é cópia da SD4 (procedure `sp_sincronizar_empenhos_op`; PHP: `ImportadorEmpenhos`, por R_E_C_N_O_, lida inteira: o saldo D4_QUANT muda; tela
  Produção › Empenhos OP, permissão `empenhos_op`). A tela da OP é em ABAS (componente `[data-abas]` de
  `componentes.js`, aba no endereço `#aba-x`): OP (dados + custo), Apontamentos reais, Kardex, Empenhos, Estrutura,
  Roteiro e Ferramenta (cavidades ativas editáveis pelos administradores, volta para a OP), pelos relacionamentos do Protheus em `App\Services\EngenhariaOrdemProducao` (D4_OP = número da OP;
  SG1 por C2_PRODUTO vigente hoje e C2_REVISAO; SG2 por C2_PRODUTO + C2_ROTEIRO, em branco = "01"; G2_CTRAB = máquina;
  G2_FERRAM = SH4; SG1/SG2/SH4 na filial da OP ou em branco). `ordens_producao.c2_roteiro|c2_revisao` vêm da SC2.
- **Falta de produtos** (Produção › Falta de produtos, permissão `falta_produtos`): `faltas_produto` é cópia da ZB5
  (tabela customizada, só campos REAIS; `ZB5_DESCPR`/`ZB5_NOME` são virtuais), por `dbo.sp_sincronizar_faltas_produto`
  (MERGE da `Compras.dbo.ZB5` inteira pelo R_E_C_N_O_; PHP: `ImportadorFaltasProduto`); `solicitado_em`/`atendido_em` =
  data + hora do Protheus (Brasília) em UTC. Legendas `FaltaProduto::STATUS` (P pendente, A parcial, C concluída,
  D cancelada). `App\Services\FaltaProdutos\FaltaProdutos` cruza com a produção: em produção (apontamento real em
  aberto "produzindo" com OP do produto) > direcionada (OP atual de máquina) > com OP > sem OP. Filtro
  `FiltroFaltaProdutos` (padrão: P + A, sem limite de data; salvos em `filtros_usuarios`, tela `falta_produtos`).
- **Faturamento › Pedidos de venda** (pedido do usuário em 26/11/2026; permissões `faturamento_pedidos` e
  `faturamento_etapas`; seção "Faturamento" no menu): o portal **NUNCA acessa o Protheus direto** — tudo vem da MESMA
  procedure `dbo.sp_sincronizar_pedidos_venda` (item `pedidos_venda`, sem importador PHP): `pedidos_venda` (SC5 + SC6,
  um item por linha, ver abaixo) e `pedidos_venda_liberacoes` (SC9010 dos pedidos da janela de 6 meses, com o
  C5_EMISSAO; MERGE pelo R_E_C_N_O_; apaga as da janela que não vieram; `extras` JSON = campos do WMS
  `EtapasPedidoVenda::CAMPOS_WMS` + os das regras ativas, só os que existem na SC9010; FOR JSON = SQL Server 2016+).
  No fim, a própria procedure chama `dbo.sp_calcular_etapas_pedidos_venda` (pedido do usuário: "o portal só chama a
  procedure"; o passo em PHP estourava o IIS), que calcula em SQL, de uma vez, TODOS os pedidos e grava
  `pedidos_venda_etapas` (uma linha por pedido: etapa, `etapa_desde`, valores, entrega prevista, faturado_em, cliente;
  MERGE só do que mudou). Salvar regra na tela = `EXEC` da mesma procedure. `App\Services\Faturamento\EtapasPedidoVenda`
  é o ESPELHO em PHP (testes em SQLite) e guarda as constantes: mudou a regra, mude OS DOIS. Regra: liberação bloqueada
  (C9_BLCRED/C9_BLEST fora de vazio e "10") = carteira; senão a etapa do WMS da 1ª regra que vale (aguardando
  faturamento > logística > conferência > separação; `regras_etapa_pedido`, campo da SC9, tela Faturamento › Etapas
  dos pedidos, desativadas e não excluídas, sem unidade; salvar recalcula os em aberto); nenhuma = em expedição; item
  com saldo sem liberação suficiente = carteira; pedido = etapa do item MAIS ATRASADO; sem saldo = faturado (C6_DATFAT)
  ou encerrado (null, resíduo sem entrega). Dias na etapa: faturado = C6_DATFAT, expedição = C9_DATALIB, as outras
  desde que o portal viu a mudança. Tela (`PedidosVendaDashboard`, `FiltroPedidosVenda`): SEMPRE abre no mês atual,
  mês a mês (o mês não vai no filtro salvo); faturados = faturamento no mês; em aberto = entrega prevista no mês +
  atrasados (padrão), só no mês ou todos; cartões das 7 etapas (quantidade e valor; aberto = saldo a faturar) filtram
  a lista; cores `--pv-*` (claro, escuro e `data-tema`). Assistente: consulta `pedidos_venda`. Sem unidade.
- `estruturas_produto` (SG1) e `roteiros_operacao` (SG2) são cópias (só consulta). `ferramentas` (SH4) é **mista**:
  campos `h4_*` vêm do Protheus a cada sincronização; `h4_qtdcavativas` (nasce = `h4_qtdcav`, nunca maior, editável
  pelos administradores), `unidade` e `disponivel` (nasce true; filtro Disponíveis/Indisponíveis na lista) são do portal
  e a sincronização NÃO os sobrepõe (`ImportadorFerramentas`).
- Máquina tem **situação** (`produzindo` | `parada`) e **OP atual** (`ordem_producao_id`) no cadastro; a Timeline lê
  daqui. O apontamento real MAIS RECENTE atualiza as duas (`App\Services\SituacaoMaquina`: tipo da legenda produzindo =
  produzindo, os outros = parada; apontamento sem OP desvincula); editar um antigo não muda; excluir o mais recente volta
  ao anterior. Apontamento novo já vem com a OP da máquina.
  OP fechada (ou excluída) no Protheus sai da máquina (`SituacaoMaquina::desvincularOpsFechadas`: a cada lote da
  sincronização das OPs e ao abrir a Timeline; os apontamentos ficam com a OP). Na Timeline, apontamento cuja legenda
  exige OP e está sem pisca "INFORMAR OP": abre a escolha (só OPs abertas) e grava no apontamento e na máquina
  (rota `apontamentos.informar-op`, JSON; `SituacaoMaquina::informarOp`). "Trocar OP" (texto ao lado da OP na
  Timeline e botão no app; rota `apontamentos.trocar-op`, JSON): escolhe a OP aberta e o modo — `atual` (o apontamento
  em andamento inteiro passa para a OP nova) ou `novo` (encerra agora com a OP anterior e começa outro na mesma
  legenda) — regra única em `App\Services\TrocaOpMaquina`. Parada sem motivo (legenda 98 do IoT): aviso no topo da
  Timeline no mesmo formato do de OP, 2 por vez (as mais antigas primeiro), e bloco piscando "INFORMAR MOTIVO"; troca a
  legenda só daquele apontamento por uma do tipo parada (rota `apontamentos.informar-motivo`, JSON) — regra única em
  `App\Services\MotivoParada` (a mesma do app).
- Horário do apontamento real (`App\Services\HorarioApontamento`): campos Dia + horário inicial/final (com segundos);
  um apontamento não passa de um dia (máx. 23:59:59). Se a máquina já tem apontamento no dia, o novo começa
  OBRIGATORIAMENTE 1 segundo depois do fim do último (campo travado; rota `apontamentos.proximo-inicio`); o primeiro do
  dia é livre. Em aberto é encerrado 1 s antes do novo, sem passar do dia dele; se o novo é de outro dia, os dias seguintes
  ganham CONTINUAÇÕES (mesma legenda/OP, `HorarioApontamento::encerrarAberto`, sem SS automática) — a madrugada não
  some. Buraco entre apontamentos (encurtar o fim para dividir uma parada, excluir um do meio) vira 98 "Parada sem
  motivo" (`HorarioApontamento::preencherBuracos`, ao salvar/excluir) para ser editado; o integrador mantém essas
  divisões manuais dentro das paradas do IoT (ponto de corte, como a "Troca de OP"). Só o apontamento MAIS RECENTE da máquina fica
  em aberto (`HorarioApontamento::fecharAbertosAnteriores`, ao salvar, no agendador `apontamentos:conferir-abertos`, 5 min, a cada aviso do integrador e ao abrir a Timeline e o Dashboard da máquina; o integrador também encerra os abertos anteriores a cada ciclo). VALIDAÇÃO: em aberto só o ÚLTIMO apontamento da máquina — criar, reabrir ou mover para trás um em aberto com outro depois é recusado (`HorarioApontamento::exigirUltimoSeAberto`, evento `saving` do modelo + formulário). Apontamento real só de máquina que o usuário enxerga (editar/excluir/próximo início: 404); a legenda do
  apontamento e da situação da máquina é lida sem escopo de unidade (`withoutGlobalScopes` na relação).
- **Desempenho e segurança para produção** (auditoria antes da publicação): apontamentos por período SEMPRE com o
  escopo `ApontamentoEstado::tocando($inicio, $fim)` (usa o índice; folga `DIAS_FOLGA_PERIODO`); o último apontamento de
  várias máquinas com `ApontamentoEstado::ultimosPorMaquina` (nada de NOT EXISTS correlacionado); chamados das máquinas
  com `ChamadoDaParada::dasMaquinas` (em lote). Listas de ids que podem passar de 2.100 (limite de parâmetros do SQL
  Server): `whereIntegerInRaw` com `(int)` ou subconsulta, nunca `whereIn` com milhares de ids. Cabeçalhos de segurança no
  middleware `CabecalhosSeguranca` (global); limites: `throttle` em `auth/*` (por IP), API do app (por usuário) e
  assistente (+ trava por usuário no limite diário). Token do app vale `oee.app.dias_validade_token` (30) dias desde o
  login; o administrador desconecta celulares em Segurança › Usuários ou › Sessões ativas. Locatário do Entra ID que não é GUID recusa todo
  login. Exportação CSV escapa células que começam com = + - @. Agendador (`routes/console.php`) roda no fuso LOCAL
  (`app.schedule_timezone`). Apontamento real que abriu SS/OS automática: ao excluir, o chamado vai junto
  (`App\Services\Manutencao\ExclusaoApontamento`, pedido do usuário em 23/11/2026): SS pendente e OS aberta sem
  atendimento são CANCELADAS, as que já tiveram atendimento só perdem o vínculo; histórico com o motivo; a tela de edição
  avisa antes (texto + confirmação); o integrador pede a exclusão dos trechos do IoT que corrigiu (campo `excluir` do
  `POST /api/iot/apontamentos/processar`, só apontamentos `[IoT]`). Troca de OP e
  continuações levam o chamado (`chamadoParaContinuar`) sem abrir outra SS.
- **Peças produzidas pelo IoT** (`App\Services\PecasProduzidas`): o Integrador MQTT grava em `apontamentos_estado.ciclos`
  os pulsos do contador nos apontamentos de PRODUÇÃO e avisa o portal (`producao` e `ops` no `POST /api/iot/apontamentos/
  processar`); peças = ciclos × cavidades ativas do molde da OP na máquina (`EngenhariaOrdemProducao::moldesDaMaquina`;
  sem molde = 1), cavidades congeladas no apontamento (`cavidades`; trocar a OP recalcula, evento `saved`);
  `ordens_producao.quantidade_real` = soma das peças da OP. Peças por minuto = 60 ÷ ciclo médio × cavidades (Timeline,
  no lugar do "Meta"; app, campo `producao` do detalhe da máquina com `por_hora` do turno, repartido pelo tempo de
  cada apontamento). Meta em pç/min: ver **Meta de produção** (`meta_por_minuto`). Toda peça conta, em qualquer legenda (ciclo lento cai num 99);
  `pecas:conferir` (agendador, 5 min) refaz as peças desatualizadas (aviso perdido). Card "Contagem IoT" na tela da OP e
  `contagem_iot` nos detalhes da OP do app (`PecasProduzidas::contagemDaOp`: total, ciclos, hoje, por máquina, última
  contagem — as mesmas peças da Produção por hora; acerta `quantidade_real` se estiver diferente). `quantidade_real`
  ("Contagem IoT" nas telas) é campo SÓ do portal: os campos da OP vindos do Protheus (C2_QUANT, C2_QUJE...) nunca mudam. A troca de OP no modo `novo` grava
  o apontamento com observação começando por "Troca de OP": o integrador corta o trecho ali e mantém a OP nova.
- **Meta de produção** (pç/min, `App\Services\MetaProducao`, scoped por requisição): vem do item do TIPO MO (B1_TIPO
  = 'MO' na SB1) da estrutura vigente da OP (`EngenhariaOrdemProducao::linhasVigentes`): G1_QUANT = horas CENTESIMAIS
  por peça para a quantidade base B1_QB, + o **fator de serviço** do MO (Produção › Mão de obra, `maos_de_obra` /
  `MaoDeObra`, 0 a 100%, nasce 0; `MaoDeObraController`, permissão `mao_obra`, administradores editam; MO novos entram
  com 0 ao abrir a tela). Fórmula do usuário (`MetaProducao::calcular`): horas por peça = ((G1_QUANT ÷ B1_QB) × (1 +
  fator%)) × 100 ÷ 1,6667; segundos por peça = × 60; meta = 60 ÷ segundos (0,00034 com 5% = 46,69 pç/min; com 0% =
  49,02). Mudar o fator = novo registro em `metas_producao` (coluna `fator_servico`), vale só dali para frente. Só um MO: com mais de um vale o PRIMEIRO (TRT, código), nunca soma; sem MO = sem meta. A meta é FIXA (não
  cai com cavidades desativadas): molde com ativas < H4_QTDCAV e pç/min abaixo da meta = "indicar para conserto"
  (`MetaProducao::moldeParaConserto`; Produção por hora, Dashboard por máquina e app `molde_conserto`; na Timeline SEM o texto: mostra a meta e o cartão fica amarelo, `tl-meta-amarelo`).
  REGISTRADA em `metas_producao` (modelo `MetaRegistrada`, por produto + C2_REVISAO): cada mudança da meta calculada
  ganha uma linha `vigente_desde` agora (estrutura sem MO = linha sem meta); o passado usa a meta vigente na época
  (`porMinuto($op, $em)`; antes do 1º registro, o 1º) — mudar a estrutura NUNCA muda a meta para trás. Registra ao
  calcular e pelo agendador `metas:registrar` (5 min, OPs atuais das máquinas). **Meta de cada HORA** pela OP de
  cada momento (`App\Services\MetaPorHora`): o turno é dividido nos trechos dos apontamentos (sem OP = a do trecho
  anterior; depois do último até o fim do turno = OP atual), meta do trecho = meta da OP no início do trecho × minutos;
  trocar a OP no meio da hora = proporcional, e só muda dali para frente. Meta e peças de cada hora por OP gravadas em
  `metas_producao_horas` (`MetaHora`; horas já começadas, só o que mudou; também pelo `metas:registrar`). App:
  `producao.por_hora[]` com `meta`, `meta_ate_agora`, `nivel` e `ops`, e `producao.meta_turno`. Produção por hora: barras pela meta
  até agora (`ProducaoPorHora::nivelMeta`: ≥ 90% verde, ≥ 50% amarela, abaixo vermelha; variáveis `--meta-*`).
  Performance (IoT) = peças reais ÷ (meta da época × tempo produzindo), só dos apontamentos cuja OP tem meta
  (`CalculadoraOee::performancePelaMeta`, mostrada real, no OEE limitada a 100%). **Qualidade** (24/11/2026) = (peças do
  IoT − perdas apontadas) ÷ peças do IoT (`CalculadoraOee::qualidadePelasPerdas`; o contador conta TAMBÉM as peças
  perdidas, confirmado pelo usuário): perdas não excluídas da máquina com o dia no período (a perda tem só o dia; máquina
  obrigatória na perda). OEE (IoT) = D × P × Q (`CalculadoraOee::oeeIot`, `PanoramaMaquinas`); OEE da FÁBRICA pelas
  SOMAS de tempos e peças de todas as máquinas (`geral`), nunca a média dos OEEs. Telas: medidor do OEE em cada cartão do
  Dashboard por máquina (`pm-oee`), OEE da fábrica no topo dele e na página inicial ("OEE de hoje"); app: campo `oee` do
  detalhe da máquina (turno de hoje; `MaquinasAplicativo::oee`, cache 30 s).
- **Milissegundos** (23/11/2026, máquinas com mais de uma peça por segundo): micro/macro parada do cadastro em segundos
  com até 3 casas (0.5 = 500 ms) → `maquinas.micro_parada_ms|macro_parada_ms` (valem mais; `Maquina::microParadaSegundos()`)
  + as antigas `*_s` arredondadas (mín. 1, macro > micro) para integradores antigos (`MaquinaController::temposDeParada`).
  Ciclo médio: `apontamentos_estado.ciclo_medio_ms` (do integrador) vale mais que `ciclo_medio` (s inteiros):
  `ApontamentoEstado::cicloEmSegundos`; exibir com `App\Support\Segundos::ciclo` ("0,040 s"); pç/min =
  `PecasProduzidas::porMinuto` com segundos fracionados; API do app: `ciclo_medio` (int, antigo), `ciclo_medio_s` e
  `ciclo_medio_texto`. O integrador usa micro ≥ 1,5 × o intervalo entre leituras do aparelho (o `ts_unix` é de segundos).
- **Apontamento de perdas** (pedido do usuário em 24/11/2026; aba "Apontamento de perdas" da tela da OP e botão no app;
  permissão `perdas_op`, no perfil operador): regra única em `App\Services\PerdasOrdemProducao` — PU = `B1_PESO` da
  SB1 (`produtos_protheus.b1_peso`, lido pela sincronização se existir); quantidade ⇄ peso pelo PU (só peso sem PU =
  recusa); dia nunca no futuro; perdas em `perdas_op` (`PerdaOp`, PU usado gravado); total em
  `ordens_producao.quantidade_perda` (campo SÓ do portal; `C2_PERDA` nunca muda). Excluir NÃO apaga: `excluido_em/por`,
  sai do total e a lista mostra "Excluído por Fulano". Calculadora da tela: `[data-calculadora-pu]` em `componentes.js`.
  API do app: `GET|POST /api/app/ops/{id}/perdas`, `DELETE /api/app/perdas/{id}` (`PerdasAplicativoController`).
- **Link da OP**: onde aparece o número da OP (Timeline, Produção por hora, Dashboard por máquina) use
  `@include('partials.link-op', ['op' => $op])` — abre a tela da OP em NOVA aba (só para quem tem `ordens_producao`).
- **Busca de OP** (todas: lista de OPs, campo de OP do apontamento/máquina/Timeline e app): escopo
  `OrdemProducao::busca($termo)` — num campo só, cada palavra em qualquer parte do número, do SKU ou da descrição (SB1).
- **Ids no SQL Server chegam como TEXTO** (pdo_sqlsrv): compare chaves estrangeiras e `value('id')` sempre com
  `(int)` dos dois lados — `===` entre texto e número dá falso só no servidor (os testes em SQLite não pegam).
- Custo das OPs (`App\Services\CustoOrdemProducao`, com `ResumoCustoOp` e `TiposMovimento`): `movimentos_producao`
  (SD3 com OP, campo `protheus.sd3_campo_op` = D3_ORDEM) e `notas_fiscais_op` (SD2 com OP, `protheus.sd2_campo_op`)
  são cópias por R_E_C_N_O_; as telas só usam os não excluídos e, na SD3, não estornados (`D3_ESTORNO = 'S'`). O
  `tipo` vem do D3_CF (PR produção, RE baixa/consumo, DE devolução; legendas em `config/protheus.php`); SD2 = "Nota
  fiscal". O objetivo é o custo de **1 peça**. Valor do movimento = D3_CUSTO1 / D2_CUSTO1 quando > 0; senão quantidade ×
  custo médio de estoque (`custo_medio`, B2_CM1 da SB2, gravado a cada sincronização; a SB2 NÃO é copiada). Custo
  Protheus = valor das produções (PR) ÷ produzido; custo SIMULADO (OEE) = baixas (RE) da OP rateadas nas produções pela
  quantidade de peças (por peça: baixas ÷ produzido; `ResumoCustoOp::custoSimulado`/`rateioDaProducao`, coluna no
  kardex); DIVERGÊNCIA = (simulado − meta) ÷ meta; consumos líquidos (baixas + notas SD2 − devoluções) ÷ produzido
  ficam só de referência; `ordens_producao.custo_meta` = custo unitário do
  fechamento mais recente do produto na SB9, gravado a cada sincronização das OPs (a SB9 NÃO é copiada).
  A tela "Apontamento real" é a antiga "Apontamentos" (permissão `apontamentos`, a chave não muda); "Apontamentos OP" é a
  tela dos movimentos do Protheus (permissão `apontamentos_producao`).
- Sincronização sem estourar o tempo do IIS: o botão da tela Banco de dados usa `SincronizadorProtheus::iniciar()` +
  `continuar($registro, segundos)` em etapas (rota `admin.banco.sincronizar.etapa`, JSON; `protheus.segundos_por_etapa`),
  retomando de `sincronizacoes.ultimo_recno` (gravado a cada lote; a 1ª chamada só abre ou retoma a execução e devolve o
  id; etapa travada sem sinal há 75 s é assumida). Nunca rode uma sincronização inteira dentro de uma requisição web.
  Consultas ao Protheus devem usar os índices dele (ex.: SB9 sempre com `B9_FILIAL` na condição, sem subconsulta
  correlacionada, e com cache por produto durante a etapa). Meça partes lentas com `App\Support\Cronometro`: a
  etapa devolve os tempos e a tela mostra.
  Cada tabela de `config/protheus.php` tem `via` = `php` (importador) ou `procedure` (o PHP só chama a procedure do
  banco do portal; coluna "Sincronização" na tela). `ordens_producao` usa `dbo.sp_sincronizar_ordens_producao`, SEM
  parâmetros: MERGE da tabela inteira `Compras.dbo.SC2` (pelo R_E_C_N_O_; sem ele, pela chave da OP) com a mesma
  lógica do `ImportadorOrdensProducao` (status, custo meta da `Compras.dbo.SB9`, OPs fechadas fora das máquinas); pode
  rodar direto no banco. Texto em `database/procedures/*.sql` (começa com `ALTER PROCEDURE`; instalação por
  `App\Support\ProceduresBanco`). Mudou a lógica das OPs? Mude nos DOIS (o SQLite dos testes usa o PHP). Mudou um
  `.sql`? Crie uma migração que chame `ProceduresBanco::instalar()` (ou rode `php artisan banco:procedures`).
  **Periodicidade** por tabela (coluna da tela Banco de dados; `agendas_sincronizacao`, `App\Services\Protheus\
  AgendaSincronizacoes`): "a cada HH:MM a partir das HH:MM" (cada dia recomeça no início; roda enquanto couber um
  intervalo inteiro: 01:00 = 24x, 23:59 = 1x ao dia). O comando `protheus:sincronizar-agendadas` roda a cada minuto
  (agendador do Laravel, em segundo plano); no servidor, a tarefa do Windows de `scripts/windows/instalar-agendador.ps1`
  roda `schedule:run` a cada minuto, e a tela mostra se o agendador está vivo (cache `agendador.sinal`).
  `movimentos_producao` (Apontamentos OP) usa `dbo.sp_sincronizar_movimentos_producao`, SEM parâmetros: lê a
  `SD3010` do servidor vinculado `[ALUMBRAPRD].[CMT8F4_145045_PR_PD]` por OPENQUERY, só com OP (`D3_ORDEM`) e
  `D3_EMISSAO` > PENÚLTIMO fechamento da `SB9010` não excluída (relê o mês que acabou de fechar: recálculo do custo
  e virada), leitura vazia com dados no portal = erro sem apagar, MERGE pelo R_E_C_N_O_, custo médio da
  `SB2010`, e APAGA do portal os excluídos no Protheus e os do período aberto que sumiram (pedido do usuário).
  `empenhos_op` usa `dbo.sp_sincronizar_empenhos_op`, SEM parâmetros: a `SD4010` INTEIRA do mesmo servidor vinculado
  (só com `D4_OP`), sem o corte da SB9, MERGE pelo R_E_C_N_O_, apagando os excluídos e os que sumiram (para com erro
  se a leitura vier vazia e o portal tiver empenhos).
  `pedidos_venda` = SC5010 (cabeçalho) + SC6010 (itens) + nome do cliente (SA1010) numa tabela só, UMA linha por item
  (chave = R_E_C_N_O_ da SC6; `c5_recno` = do cabeçalho; `situacao` aberto | parcial | faturado | eliminado), SÓ pela
  procedure `dbo.sp_sincronizar_pedidos_venda` (junção no Protheus por OPENQUERY, MERGE, apaga os que não vieram; só os pedidos com C5_EMISSAO nos últimos 6 MESES, o resto fica como histórico; portal vazio = carga geral; sem
  importador em PHP: fora do SQL Server a sincronização é pulada com aviso). Só os itens com `C6_OPER` (tipo de operação,
  DJ da SX5) num tipo ATIVO de `tipos_operacao_pedido` (`App\Models\Faturamento\TipoOperacaoPedido`, código sem zeros à
  esquerda: "1" = "01"; tela Faturamento › Etapas dos pedidos, incluir/excluir = desativar; sem tipo ativo a procedure
  para com erro); a SC9 só dos itens que vieram; itens de tipo excluído saem do portal (só os da janela); itens do portal sem `c6_oper` (gravados antes do filtro) = a execução seguinte relê TUDO uma vez com o filtro (o histórico fica só com os tipos aceitos).
  `clientes` = cópia da SA1010 do mesmo servidor vinculado, SÓ pela procedure `dbo.sp_sincronizar_clientes` (inteira,
  MERGE pelo R_E_C_N_O_, apaga os excluídos soltando os pedidos; leitura vazia = erro) — roda ANTES dos pedidos e liga
  `pedidos_venda.cliente_id` (C5_CLIENTE + C5_LOJACLI, filial do pedido ou compartilhada); a dos pedidos liga os da janela.
  SD2 (e a SD3 pelo PHP) são incrementais (`ImportadorEspelho::primeiroRecno`: novos + últimos `protheus.reler_dias` dias);
  `--completa` (e `protheus.agenda_completa`, semanal) relê tudo.
- **Regras de proteção da manutenção**: minutos normais + extras lançados à mão têm de somar a duração; cronômetro
  esquecido conta no máximo `oee.cronometro_max_horas` (12 h); concluir ou cancelar a OS encerra os cronômetros abertos
  (cada um com a sua descrição); concluir exige o checklist respondido; SS só se edita PENDENTE; material da OS só o
  almoxarifado ou quem aprova muda; aprovar SS relê com `lockForUpdate` (corrida portal × app); pedido de compra igual
  e ativo (mesma origem, tipo e itens, 10 min) não é gerado de novo. Almoço (`intervalo_min` do mantenedor): trabalhado dentro da jornada
  é hora NORMAL (nunca extra), mas sai das horas disponíveis. Horas extras e disponível seguem o calendário do
  mantenedor (feriado = tudo extra; compensação = dia com jornada).
- Estrutura da OP: por peça = G1_QUANT ÷ `produtos_protheus.b1_qb` (B1_QB, quantidade base; lido só se existir na SB1);
  necessidade × C2_QUANT ÷ (1 − G1_PERDA%); empenho casa pelo TRT. Custo Protheus = só o D3_CUSTO1 das produções
  (sem custo médio; `ResumoCustoOp::producaoSemCusto` avisa).
- **Pedidos e solicitações de compra** (Ordens de serviço): o administrador da unidade gera um pedido de compra (SC7,
  MATA120) ou uma solicitação de compra (SC1, MATA110) — `pedidos_compra.tipo` = pedido | solicitacao, `ZP1_TIPO` = PC |
  SC — dos itens APROVADOS de uma solicitação de material ou direto numa OS aberta (CC de origem e de destino). Vai para o Protheus por TABELAS MURO
  NATIVAS **ZP1** (cabeçalho) + **ZP2** (itens), criadas no dicionário pelo compatibilizador `U_OEEUPDPC` (conexão
  `muro` / `config/compras.php`): ÚNICA exceção ao "Protheus só leitura" — o portal grava SÓ nelas, como o Protheus
  (campos com prefixo do alias, `D_E_L_E_T_`, próximo `R_E_C_N_O_`, datas AAAAMMDD + hora). Fontes TLPP
  (`OEEUPDPC` dicionário; `OEEPCJOB` schedule, MATA120 por ExecAuto) em `integracao-protheus/pedido-compra/`.
  `App\Services\Compras\PedidoCompraMuro` grava e lê de volta (status, número, aprovador, conclusão, valores; horário
  local `compras.fuso_muro`); cópia no portal em `pedidos_compra` + `pedidos_compra_itens`. Mudou a muro? Mude os dois
  `.tlpp`, o LEIAME e `Tests\Concerns\TabelaMuroDeCompras`.
  Geração de preventivas (agendador × botão "Gerar agora") roda sob a trava `os-gerar-preventivas` e não duplica OS.
  Custo da OS (card na tela da OS): `App\Services\Manutencao\CustoOrdemServico`.
- **Abertura de SS**: em etapas (unidade → área executante → máquina/CC → o que precisa → descrição); a unidade só
  lista as liberadas ao login (`SolicitacaoController::unidadesPermitidas`) e filtra áreas e máquinas; recurso = MÁQUINA ou FERRAMENTA/MOLDE (SH4, `ferramenta_id` na SS e na OS, só nas unidades de
  `oee.os_unidades_ferramenta` = Manutenção e Ferramentaria, da mesma unidade; nunca os dois; `AcessoOs::ferramentas()`); flags "máquina
  parada" e **"oferece risco à integridade"**; prioridade só **Alta | Baixa** (SS, OS e planos de preventiva;
  `SolicitacaoServico::PRIORIDADES`; "urgente" e "média" antigas só aparecem com "(antiga)", plano antigo gera OS
  pela `prioridadeAtual`; painéis contam `PRIORIDADES_ALTAS`) (`risco_integridade`, copiado para a OS). **SS automática**: legenda com
  "Abre SS" (`legendas.abre_ss_unidade` + `abre_ss_area_executante_id`) abre a SS ao ser apontada (portal, app ou IoT;
  evento `saved` de `ApontamentoEstado` → `App\Services\Manutencao\SsAutomatica`): título "OS automática — …", CC da
  máquina, solicitante = QUEM APONTOU (usuário logado no portal ou no app: criado_por/atualizado_por do apontamento);
  só apontamento do IoT sem usuário usa o usuário técnico inativo "IoT (automático)" + `origem_iot` (e aí a OS é avaliada
  pelo administrador da unidade); uma por apontamento e nenhuma nova com outra automática em aberto da mesma MÁQUINA
  (de qualquer área; trava por máquina; decidido em 23/11/2026) — nesse caso a SS/OS que já está em aberto fica VINCULADA ao apontamento (`apontamentos_estado.
  solicitacao_vinculada_id`) e a tela avisa o porquê (`SsAutomatica::mensagem`). O Integrador MQTT (repositório `marcatao/MQTT`)
  grava os apontamentos do IoT DIRETO no banco (sem eventos) e depois chama `POST /api/iot/apontamentos/processar`
  (token `IOT_TOKEN`, middleware `integrador`; `IntegradorIotController`) com os criados/legenda trocada: o portal roda a
  `SsAutomatica` (idempotente). `ss:conferir-automaticas` (agendador, 5 min) abre/vincula a SS das paradas EM ABERTO com legenda
  "Abre SS" que ficaram sem chamado (aviso perdido ou anterior à configuração). Máquina PARADA com SS/OS da parada
  atual (a vinculada ao apontamento atual ou a aberta na parada): número e situação no lugar da meta (Timeline e app;
  `App\Services\Manutencao\ChamadoDaParada`, campo `chamado` da API).
- Filtros salvos das telas de relatório ficam em `filtros_usuarios` (por usuário e tela, com um "padrão"). Na
  Timeline: `App\Services\Filtros\FiltroTimeline` (De/Até padrão hoje 01:00–23:59; plantas, linhas e máquinas) e a
  área de resultado é trocada sem recarregar a página (`?parcial=1`). Os filtros podem ser escondidos (botão +
  linha de resumo; na Timeline SEMPRE começam escondidos) e se recolhem sozinhos após 3 minutos sem mexer na tela. A Timeline
  só mostra máquinas ATIVAS e com IoT cadastrado (dispositivo + canal, `Maquina::comIot()`; nos testes,
  `Tests\Concerns\IotDeTeste`). Novos
  relatórios seguem o mesmo padrão.
- **Produção por hora** (Dashboards › Produção por hora, permissão `producao_hora`, `ProducaoPorHoraController` +
  `App\Services\ProducaoPorHora`): máquina a máquina (as da Timeline), peças do IoT por hora do TURNO da máquina no dia
  (`App\Services\TurnoDaMaquina`, o mesmo do app; `PecasProduzidas::porHora`), total, média e melhor hora, OP atual e
  produto, OPs do turno e gráfico em SVG (`dashboard.producao-hora._grafico`); o operador vê (só as máquinas das unidades
  dos perfis dele, como no app); meta de cada hora pela OP de cada momento (`MetaPorHora`: linha tracejada, meta do turno e % por hora). Filtro `FiltroProducaoHora` (dia, plantas, linhas, máquinas; salvos na tela `producao_hora`).
- **Dashboard por máquina** (`DashboardController`): só máquinas ativas com IoT ATIVO (`Maquina::comIotAtivo()`).
  Panorama pelos APONTAMENTOS REAIS (`App\Services\PanoramaMaquinas`, sobre a `Timeline`): situação agora, OP,
  produto, molde e cavidades, tempos por tipo e disponibilidade (partial `dashboard._cartao-maquina`) + a timeline da máquina no período do filtro (todas as legendas; `timelineMaquina`) + engrenagem para o cadastro da máquina (só `ehAdmin()`) + o cartão da Produção por hora da máquina (partial `dashboard.producao-hora._maquina`, o mesmo da tela Produção por hora; turno de hoje ou do último dia do período). Legenda do tipo
  `desligada` = "Fora de turno": nunca conta como parada (nem na disponibilidade). Legenda com **Exibir em dashboards**
  = Não (`legendas.exibir_dashboards`, nasce Sim; pedido do usuário em 24/11/2026): fora do Dashboard por máquina —
  tempo por legenda, barra, maior parada, disponibilidade, performance e lista de apontamentos (`PanoramaMaquinas::exibe`);
  a Timeline continua mostrando tudo. A tela da máquina mostra o OEE (IoT) D × P × Q do período (medidor + barras e
  tempos/peças/perdas; sem OEE explica o que falta: apontamentos ou meta). O "OEE completo" antigo das tabelas de peças
  do sistema anterior (`apontamentos`, `paradas`) saiu da tela da máquina (continua só como bloco "tabelas antigas" na lista).
  **Ciclos da máquina** (26/11/2026, cartão acima de "Dispositivos IoT"; partial `dashboard._ciclos-iot`): ciclo a ciclo
  de UM dia lido DIRETO da `MQTT.dbo.IOT001` (só leitura; `config('oee.iot_leituras')`, o login do portal precisa de
  SELECT: `database/scripts/permissao_leitura_iot001.sql`; sem acesso = aviso, a tela não quebra) pelo 1º canal de
  produção da máquina (`App\Services\CiclosIot`): cada mudança do contador = barra; intervalo desde a mudança anterior
  (ts_unix), ciclo por peça = intervalo ÷ peças; micro efetiva ≥ 1,5 × passo das leituras (como o integrador), ≥ macro
  = parada grande (vermelha, cortada com ▲; escala pelo percentil 98 dos ciclos normais). Dia escolhido entre os do
  período (`?ciclos_dia=`); filtro de hora, dica e CSV no navegador (`[data-grafico-ciclos]` em `componentes.js`).
- Canais IoT da máquina usam `iotN_dispositivo_id` (FK para `dispositivos_iot`). As colunas antigas `iotN_id`
  (texto) só são lidas, para mostrar o valor antigo; não grave nelas. O canal é um dos 6 de `config('oee.iot_canais')`
  (chd1_value…chd6_value; dados virão de MQTT.dbo.IOT001). Dispositivo + canal só em UMA máquina (todas as unidades).
  Máquina da MANUTENÇÃO só fica ATIVA com pelo menos um canal com dispositivo ativo (`Maquina::exigeIotECalendario`;
  Tecnologia e Ferramentaria não exigem); sem canal ela é inativada (ao apagar os canais
  ou desativar o dispositivo: `App\Services\MaquinasSemIot`; em massa: `maquinas:desativar-sem-iot --confirmar`).
  A linha da máquina tem de ser de planta da MESMA unidade da máquina (ou planta "Todas"; máquina "Todas" aceita
  qualquer uma): a tela só lista essas e o servidor confere (`MaquinaController::conferirLinhaDaUnidade`).
- **Calendários** (Administração › Calendários, `calendarios` + `calendario_dias`, `App\Services\Calendario\DiasUteis` e
  `Feriados`): anuais, por unidade (TemUnidade), vários por unidade. Nascem com os feriados nacionais, de SP e de São
  Bernardo do Campo (Carnaval como ponto facultativo); só as EXCEÇÕES são gravadas (feriado, ponte, folga = não útil;
  compensação, útil = útil); o resto segue os dias da semana de quem usa. Máquina da Manutenção e mantenedor
  (`calendario_id`) só ficam ATIVOS com calendário e dias de trabalho, CADA DIA com o horário em que começa e termina
  (`App\Support\JornadaSemanal` + partial `partials.jornada-semanal`; coluna `horarios_semana` JSON; `dias_semana` e
  `jornada_inicio|fim` = dias marcados e horário do 1º dia, lidos quando não há `horarios_semana`). As horas normais ×
  extras do mantenedor (`HorasManutencao`) usam o horário de cada dia. Para saber se trabalha num dia: `DiasUteis::trabalha($maquinaOuMantenedor, $data)`.
  **Máquinas: até 3 turnos por dia** (25/11/2026; partial `partials.turnos-semana`, campos `turno[dia][n][inicio|fim]`,
  turno 1 obrigatório, sem sobreposição): `maquinas.turnos_semana` (JSON; `JornadaSemanal::turnos`, `turnosDoFormulario`,
  `colunasDosTurnos`), horas disponíveis = soma dos turnos; `horarios_semana` continua gravada como o ENVELOPE do dia
  (início do T1 ao fim do último) para quem só lê a jornada antiga. `TurnoDaMaquina`: período do dia = envelope,
  `intervalos()` = cada turno, `turnoEm()` = turno num instante; a meta da hora só conta DENTRO dos turnos
  (`MetaPorHora::completar(..., $turnos)`); o integrador lê `turnos_semana` e o intervalo entre turnos vira 96. Mantenedor
  continua com 1 horário por dia (`partials.jornada-semanal`). Perda tem `turno` (portal: escolhido; app: `turnoEm` no
  horário do lançamento).
- **Inventário de TI** (Tecnologia › Inventário de TI, permissão `inventario_ti`, no perfil `mantenedor_tecnologia`;
  pedido do usuário em 25/11/2026): tudo cadastrado no portal (sem SN1). `ti_itens` (`App\Models\InventarioTi\ItemTi`,
  TemUnidade + Auditavel, unidade padrão `tecnologia`) em 3 categorias FIXAS — `hardware` (equipamento), `licenca`
  (licença de software, `ti_licenca_atribuicoes`: em um computador e/ou para uma pessoa, sem passar de
  `quantidade_licencas`) e `linha` (linha/chip, `vinculado_item_id` = aparelho) — e tipos editáveis em `ti_tipos`
  (categoria do tipo não muda). Situação `em_uso | estoque | manutencao | baixado` (nunca exclui; baixar exige motivo,
  tira o responsável e libera as licenças instaladas). Só o administrador da unidade do item edita (`ItemTi::podeEditar`).
  Toda gravação pela regra única `App\Services\InventarioTi\MovimentacaoInventario` (histórico em `ti_movimentacoes`:
  responsável, local, centro de custo, situação, aparelho, licença, termo). **Termo de responsabilidade** (`ti_termos`,
  `App\Services\InventarioTi\TermosResponsabilidade`; regras do usuário em 27/11/2026): SÓ o Administrador de TI
  (`TermosResponsabilidade::podeGerar`, perfil `admin_tecnologia`; rotas com `perfil:admin_tecnologia`) GERA o termo, na
  tela do item, escolhendo as DUAS testemunhas (usuários ativos, diferentes, nenhuma o comodatário; o formulário sugere as
  do último termo) e ANULA (cancelado com motivo, nada apagado; termo novo = gerar de novo). Trocar o responsável só
  cancela o pendente (não gera sozinho). CADA PESSOA ASSINA SÓ POR ELA MESMA, com o próprio login: o comodatário no lugar
  dele, cada testemunha (`ti_termo_testemunhas`) no dela; NÃO existe assinatura presencial no aparelho de outro (colunas
  `presencial` ficam só para registros antigos). Dados do item congelados em `conteudo`; o termo é o CONTRATO PARTICULAR
  DE COMODATO da empresa (texto em `App\Support\ContratoComodato` — NÃO no config, para não depender do `config:cache` do
  servidor: comodante, cláusulas — as de `telefonia` só para celular/tablet/linha —; objeto por
  `TermosResponsabilidade::descricaoComodato`); testemunha assina com RG; `completo()` = comodatário + 2. Descrição do
  item só das DESCRIÇÕES PADRÃO (`ti_descricoes`, `DescricaoItemTi`, tela Inventário › Descrições; com tipo = só daquele
  tipo, sem tipo = qualquer um; o item grava `descricao_id` + cópia do texto em `descricao`, renomear não muda itens;
  item antigo sem `descricao_id` pode manter a dele). Atributos
  livres do item (nome + valor, `ti_item_atributos`, `ItemTi::gravarAtributos`, busca da lista procura neles). Ao assinar
  o comodatário informa cargo, nacionalidade, RG, CPF (dígitos conferidos) e endereço (`conteudo.comodatario`, só no
  termo; o próximo termo dele já vem preenchido); o responsável assina no
  portal (celular ou computador, quadro `[data-assinatura]` de `componentes.js`; PNG conferido por `App\Support\AssinaturaPng`,
  o mesmo do app). Aviso no topo de todas as telas
  (`inventario-ti._aviso-termos`) e "Meus equipamentos": rotas só com login (SO_LOGIN; quem recebe pode não ter perfil;
  o termo só abre para o responsável ou quem vê o inventário da unidade, o resto 404). **SS/OS para item de TI**:
  `item_ti_id` na SS e na OS, só nas unidades de `oee.os_unidades_item_ti` (Tecnologia), nunca junto com máquina ou
  ferramenta; sem CC informado vale o do item; "Abrir SS para este item" na tela do item (`?item_ti=`); a tela do item
  lista os chamados (`AcessoOs::filtrar`). Listas em `AcessoOs::itensTi()`.
- **Pergunte para Alumbra** (box da página inicial, `partials.assistente` + `public/js/assistente.js`, permissão
  `assistente`): IA com a Claude (SDK `anthropic-ai/sdk`, `config/assistente.php`, chave `ANTHROPIC_API_KEY` só no
  `.env`). Só responde sobre o portal e só com os dados do usuário logado: as consultas ficam em
  `App\Services\Assistente\FerramentasAssistente` (SÓ LEITURA; cada uma exige a permissão da tela e passa pelos mesmos
  filtros das telas: escopo de unidade, `AcessoOs::filtrar`). Roda em ETAPAS curtas (uma chamada à Claude por
  requisição, rota `assistente.etapa`), como a sincronização. Histórico e tokens em `assistente_conversas` e
  `assistente_perguntas`. Tela nova com dados? Avalie criar a consulta correspondente no assistente.
- Fórmulas de OEE só em `app/Services/CalculadoraOee.php` (ou serviços irmãos), com testes.

- **Aplicativo Android** (`app-movel/`, Kotlin + Compose, pacote `br.com.alumbra.oee`): nativo, offline com banco
  local, sincroniza só na Wi-Fi `Wlan_alumbra`, distribuído só por APK. Usa uma API do portal (`/api/app/...`) com os
  MESMOS serviços, permissões e escopo de unidade das telas; conflito = vale a alteração mais recente pelo horário.
  A cada mudança no app: suba `codigo` (e ajuste `nome`) em `app-movel/android/versao.properties`, registre em
  `app-movel/VERSOES.md` e gere o APK. A chave em `app-movel/android/keystore/` fica no repositório com autorização do
  usuário (só assina o APK) e nunca deve ser trocada. Plano e regras em `app-movel/README.md`. Acesso no app (e na
  API): Máquinas SÓ com o perfil `operador`; Ordens de serviço SÓ com um perfil de `oee.mantenedores_area`. Alertas com
  o app fechado: só SS para mantenedores; os das máquinas só com a tela daquela máquina aberta. Função Máquinas
  (operador): `App\Services\Aplicativo\MaquinasAplicativo` — máquinas ativas com IoT ativo, apontamento com legenda
  `oee.app.legenda_sem_motivo` (98, do IoT) obriga escolher um motivo do tipo parada (troca a legenda só dele), máquina
  sem OP obriga escolher a OP, troca de OP divide o apontamento em aberto, cavidades ativas editáveis pelo operador.
  "Detalhes da OP" (botão no cartão da OP; `GET /api/app/ops/{id}/detalhes`, permissão `ordens_producao`,
  `App\Services\Aplicativo\DetalhesOpAplicativo` sobre `CustoOrdemProducao::kardex` e `EngenhariaOrdemProducao::daOp`):
  abas OP (e custo), Kardex, Empenhos e Engenharia (estrutura e roteiro).
  Toque num apontamento real da tela da máquina = detalhe (`GET /api/app/apontamentos/{id}`,
  `MaquinasAplicativo::detalheApontamento`); o operador altera SÓ a OP dele (`POST /api/app/apontamentos/{id}/op`;
  regra única `App\Services\OpDoApontamento`, a mesma do "INFORMAR OP" da Timeline).
  Máquina PARADA: "Trocar motivo" (`POST /api/app/maquinas/{id}/dividir-parada`, regra única `App\Services\DivisaoParada`):
  encerra a parada em andamento 1 s antes de agora e abre um 98 (observação "Divisão da parada (aplicativo)", ponto de
  corte para o integrador; leva o chamado da parada) para escolher o motivo; recusado se o apontamento atual é de produção.
  Função Ordens de serviço (mantenedor): `App\Services\Aplicativo\OrdensServicoAplicativo` sobre `AcessoOs` e
  `FluxoOrdemServico` — "assumir" SS = aprovar com o mantenedor como principal (só o primeiro leva), apontamento de
  horas com a tela travada no cronômetro, avaliação do solicitante no celular do mantenedor com assinatura
  (`os_assinaturas`, PNG base64). Checklist da preventiva no detalhe da OS e na tela travada do cronômetro
  (`POST /api/app/os/ordens/{id}/checklist`) e "Adicionar mantenedor" em toda OS aberta (`POST .../mantenedores`: só
  mantenedor ATIVO da área da OS; entra em "Minhas OS" dele) — regras únicas em `FluxoOrdemServico::responderChecklist`
  e `adicionarMantenedor` (o checklist do portal usa a mesma). Plano de preventiva (pedido do usuário em 27/11/2026):
  responsável OBRIGATÓRIO; "Excluir" não apaga (`excluido_em/por`, inativa, some da lista e não gera OS; as OS já
  geradas ficam). Alerta de SS: a cada toque o app consulta `/api/app/os/alerta` (para quando outro
  assume); "Recusar" grava em `os_recusas_aplicativo` (só aquela SS para de tocar para ele) e no histórico
  (`nao_aceita`, "SS não aceita por …").
  **Atualização pelo portal** (app 0.13.0+): Segurança › Aplicativo (`perfil:admin_tecnologia`,
  `Admin\AplicativoController`) publica o APK em `versoes_aplicativo` (versão lida do arquivo por `App\Support\LeitorApk`;
  arquivo no disco `local`, pasta `aplicativo`; obrigatória/ativa; versão DESATIVADA pode ser EXCLUÍDA — apaga o .apk do servidor e a linha fica como histórico com `excluido_em/por`, sem baixar nem reativar, pedido do usuário em 27/11/2026); o app consulta `GET /api/app/versao` e baixa
  `GET /api/app/versao/apk` (`App\Services\Aplicativo\VersoesAplicativo`), confere o SHA-256 e abre o instalador.

## Git

- Trabalhe **sempre direto na branch `main`**: não crie outras branches nem pull requests. Faça commit e push em
  `main` (`git push origin main`). Isso vale para todos os repositórios do usuário.
- Nunca commite `.env` nem cópias dele (`.env.bak*`): têm segredos.

## Ambiente

- O servidor usa **PHP 8.3** e o `composer.lock` é gerado nele: não atualize pacotes para versões que exijam PHP 8.4+.
- Publicação: aplicação do IIS na subpasta `/oee` de `https://www.alumbra.com.br` (`public/web.config` é do
  servidor; não troque as regras sem pedido). Com subpasta no `APP_URL`, `AppServiceProvider::aplicarEnderecoPublico()`
  força esse endereço em links e no login; teste rotas na subpasta como em `tests/Feature/EnderecoPublicoTest.php`.
- Nunca deixe em `public/` arquivos de diagnóstico como `phpinfo()`: o portal é público.
- A pasta `php8.3/` (PHP do servidor, php.ini e DLLs do SQL Server) fica versionada de propósito, para a equipe
  compartilhar a configuração do PHP. Nunca coloque segredos nela.

## Comandos

```sh
php artisan test           # testes (sempre antes de terminar)
vendor/bin/pint            # formatação do código
php artisan migrate        # aplica migrações
php artisan banco:testar   # confere a conexão com o banco
php artisan banco:documentar      # gera docs/banco-de-dados.md (diagrama e tabelas)
php artisan protheus:sincronizar  # sincroniza os dados do Protheus (centros de custo)
```

## Agentes especialistas (`.claude/agents/`)

- `especialista-portal-oee`: telas, dashboards, gráficos, relatórios, cadastros e tempo real.
- `especialista-sql-server`: tabelas, migrações, consultas, índices, permissões e desempenho no SQL Server.
- `especialista-entra-id`: login, sessão, segredos e segurança da integração com o Entra ID.
- `especialista-dados-oee`: regras de cálculo, agregações e análises de OEE.
- `especialista-custos-producao`: custo por OP e por peça (SD3, SD2, SB2, SB9), custo real × meta, divergências.
- `arquiteto-banco-dados`: estrutura do banco (tabelas, relacionamentos, PK/FK, índices), sugestões de modelagem e
  diagrama do banco (`docs/banco-de-dados.md`).
