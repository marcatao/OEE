# OEE Alumbra — instruções para o Claude

Portal Laravel 13 (PHP 8.3, versão do servidor) de dashboards de produção e cálculos de OEE. Login pelo Microsoft Entra ID, dados no
SQL Server. Escreva código, textos de tela, comentários e mensagens de commit em português do Brasil.

## Regras do projeto

- O **Entra ID só autentica** a entrada (`app/Services/EntraId.php`). O usuário é cadastrado no banco no primeiro
  login (`usuarios`, chave `entra_oid`, nunca o e-mail) e nasce **sem acesso**; só os e-mails de `config('oee.admins')`
  (padrão `marcato@alumbra.com.br`) nascem `admin`. Todo login vai para `registros_login`.
- Três áreas (unidades): **Manutenção** (produção), **Tecnologia** e **Ferramentaria**. Acesso por **perfis** (vários
  por usuário, tabela `usuario_perfis`, definição em `config('oee.perfis_acesso')`: operador; solicitante, mantenedor e
  administrador de cada área — `solicitante_manutencao|tecnologia|ferramentaria`, `mantenedor_producao|tecnologia|
  ferramentaria`, `admin_producao|tecnologia|ferramentaria`; mapas `oee.admins_area` e `oee.mantenedores_area`; cada
  perfil com unidades e permissões de página) + **grupos** (somam páginas, `grupo_permissoes`, catálogo em
  `config('oee.permissoes')`). Usuário sem linhas em `usuario_perfis` segue a coluna antiga `perfil` (admin = todos os
  perfis, operador = operador). Administrador de Tecnologia abre todas as PÁGINAS e é o único na aba **Segurança**
  (usuários, grupos, acessos, banco: `perfil:admin_tecnologia`), mas DADOS só da Tecnologia: ninguém vê unidade que
  não está habilitada nos perfis dele (cadastros, filtros, listas, SS/OS, app; `AcessoOs::unidadesHabilitadas` =
  unidades dos perfis + as em que é Solicitante; `AcessoOs::filtrar` nunca passa delas); cadastros = `perfil:admin_producao,admin_tecnologia,
  admin_ferramentaria`. Use `Usuario::pode()`, `ehAdmin()`, `podeSeguranca()`, `temPerfil()`, `areasAdministradas()` e
  `App\Support\Unidades::areas()` (nunca escreva a lista de áreas fixa no código).
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
  `public/css/portal.css` (tema claro e escuro). Nunca use cores soltas nas telas.
- Telas em Blade com `layouts.portal` e o CSS de `public/css/portal.css`. Sem etapa de build com Node.
  Listas longas (centro de custo, máquina, usuário, mantenedor): use `<select data-busca>` (lista com busca) e, para
  marcar vários, o partial `partials.escolha-multipla` (abre/fecha, com busca) — ambos em `public/js/componentes.js`.
- Toda rota interna fica no grupo `auth`. Página nova: registre a chave em `config/oee.php` (`permissoes`), proteja a
  rota com `->middleware('permissao:chave')` e inclua o link no menu lateral (`layouts/portal.blade.php`) dentro de
  `@if ($u->pode('chave'))`. Páginas só de admin usam `->middleware('perfil:admin')`.
- **Dados existentes são intocáveis**: migrações NUNCA apagam nem alteram registros (nada de `DELETE`, `UPDATE`,
  `truncate`, `migrate:fresh`, nem trocar o significado de uma coluna com dados). Mudança de estrutura só ACRESCENTA
  (coluna nova, tabela nova). Se for preciso mexer em registros (converter, copiar, limpar), **pare e avise o
  usuário**, explicando o que mudaria, e só faça depois que ele decidir (de preferência num comando separado, nunca
  escondido numa migração).
- Mudou uma migração? Rode `php artisan banco:documentar` e faça commit de `docs/banco-de-dados.md` junto.
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
- `empenhos_op` é cópia da SD4 (`ImportadorEmpenhos`, por R_E_C_N_O_, lida inteira: o saldo D4_QUANT muda; tela
  Produção › Empenhos OP, permissão `empenhos_op`). A tela da OP é em ABAS (componente `[data-abas]` de
  `componentes.js`, aba no endereço `#aba-x`): OP (dados + custo), Apontamentos reais, Kardex, Empenhos, Estrutura,
  Roteiro e Ferramenta (cavidades ativas editáveis pelos administradores, volta para a OP), pelos relacionamentos do Protheus em `App\Services\EngenhariaOrdemProducao` (D4_OP = número da OP;
  SG1 por C2_PRODUTO vigente hoje e C2_REVISAO; SG2 por C2_PRODUTO + C2_ROTEIRO, em branco = "01"; G2_CTRAB = máquina;
  G2_FERRAM = SH4; SG1/SG2/SH4 na filial da OP ou em branco). `ordens_producao.c2_roteiro|c2_revisao` vêm da SC2.
- `estruturas_produto` (SG1) e `roteiros_operacao` (SG2) são cópias (só consulta). `ferramentas` (SH4) é **mista**:
  campos `h4_*` vêm do Protheus a cada sincronização; `h4_qtdcavativas` (nasce = `h4_qtdcav`, nunca maior, editável
  pelos administradores) e `unidade` são do portal e a sincronização NÃO os sobrepõe (`ImportadorFerramentas`).
- Máquina tem **situação** (`produzindo` | `parada`) e **OP atual** (`ordem_producao_id`) no cadastro; a Timeline lê
  daqui. O apontamento real MAIS RECENTE atualiza as duas (`App\Services\SituacaoMaquina`: tipo da legenda produzindo =
  produzindo, os outros = parada; apontamento sem OP desvincula); editar um antigo não muda; excluir o mais recente volta
  ao anterior. Apontamento novo já vem com a OP da máquina.
  OP fechada (ou excluída) no Protheus sai da máquina (`SituacaoMaquina::desvincularOpsFechadas`: a cada lote da
  sincronização das OPs e ao abrir a Timeline; os apontamentos ficam com a OP). Na Timeline, apontamento cuja legenda
  exige OP e está sem pisca "INFORMAR OP": abre a escolha (só OPs abertas) e grava no apontamento e na máquina
  (rota `apontamentos.informar-op`, JSON; `SituacaoMaquina::informarOp`).
- Horário do apontamento real (`App\Services\HorarioApontamento`): campos Dia + horário inicial/final (com segundos);
  um apontamento não passa de um dia (máx. 23:59:59). Se a máquina já tem apontamento no dia, o novo começa
  OBRIGATORIAMENTE 1 segundo depois do fim do último (campo travado; rota `apontamentos.proximo-inicio`); o primeiro do
  dia é livre. Em aberto é encerrado 1 s antes do novo, sem passar do dia dele.
- Custo das OPs (`App\Services\CustoOrdemProducao`, com `ResumoCustoOp` e `TiposMovimento`): `movimentos_producao`
  (SD3 com OP, campo `protheus.sd3_campo_op` = D3_ORDEM) e `notas_fiscais_op` (SD2 com OP, `protheus.sd2_campo_op`)
  são cópias por R_E_C_N_O_; as telas só usam os não excluídos e, na SD3, não estornados (`D3_ESTORNO = 'S'`). O
  `tipo` vem do D3_CF (PR produção, RE baixa/consumo, DE devolução; legendas em `config/protheus.php`); SD2 = "Nota
  fiscal". O objetivo é o custo de **1 peça**. Valor do movimento = D3_CUSTO1 / D2_CUSTO1 quando > 0; senão quantidade ×
  custo médio de estoque (`custo_medio`, B2_CM1 da SB2, gravado a cada sincronização; a SB2 NÃO é copiada). Custo unit.
  atual = (baixas + notas SD2 − devoluções) ÷ produzido na OP; `ordens_producao.custo_meta` = custo unitário do
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
  SD3/SD2 são incrementais (`ImportadorEspelho::primeiroRecno`: novos + últimos `protheus.reler_dias` dias);
  `--completa` (e `protheus.agenda_completa`, semanal) relê tudo.
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
  Custo da OS (card na tela da OS): `App\Services\Manutencao\CustoOrdemServico`.
- **Abertura de SS**: em etapas (unidade → área executante → máquina/CC → o que precisa → descrição); a unidade só
  lista as liberadas ao login (`SolicitacaoController::unidadesPermitidas`) e filtra áreas e máquinas; flags "máquina
  parada" e **"oferece risco à integridade"**; prioridade só **Alta | Baixa** (SS, OS e planos de preventiva;
  `SolicitacaoServico::PRIORIDADES`; "urgente" e "média" antigas só aparecem com "(antiga)", plano antigo gera OS
  pela `prioridadeAtual`; painéis contam `PRIORIDADES_ALTAS`) (`risco_integridade`, copiado para a OS). **SS automática**: legenda com
  "Abre SS" (`legendas.abre_ss_unidade` + `abre_ss_area_executante_id`) abre a SS ao ser apontada (portal, app ou IoT;
  evento `saved` de `ApontamentoEstado` → `App\Services\Manutencao\SsAutomatica`): título "OS automática — …", CC da
  máquina, solicitante = usuário técnico inativo "IoT (automático)" + `origem_iot` (dispositivo/canal); uma por
  apontamento e nenhuma nova com outra automática em aberto da mesma máquina e área; OS automática é avaliada pelo
  administrador da unidade.
- Filtros salvos das telas de relatório ficam em `filtros_usuarios` (por usuário e tela, com um "padrão"). Na
  Timeline: `App\Services\Filtros\FiltroTimeline` (De/Até padrão hoje 01:00–23:59; plantas, linhas e máquinas) e a
  área de resultado é trocada sem recarregar a página (`?parcial=1`). Os filtros podem ser escondidos (botão +
  linha de resumo; escolha lembrada no navegador) e se recolhem sozinhos após 3 minutos sem mexer na tela. A Timeline
  só mostra máquinas ATIVAS e com IoT cadastrado (dispositivo + canal, `Maquina::comIot()`; nos testes,
  `Tests\Concerns\IotDeTeste`). Novos
  relatórios seguem o mesmo padrão.
- **Dashboard por máquina** (`DashboardController`): só máquinas ativas com IoT ATIVO (`Maquina::comIotAtivo()`).
  Panorama pelos APONTAMENTOS REAIS (`App\Services\PanoramaMaquinas`, sobre a `Timeline`): situação agora, OP,
  produto, molde e cavidades, tempos por tipo e disponibilidade (partial `dashboard._cartao-maquina`). Legenda do tipo
  `desligada` = "Fora de turno": nunca conta como parada (nem na disponibilidade). O OEE completo
  (performance e qualidade) ainda vem das tabelas antigas de peças (`apontamentos`, `paradas`) e só aparece com dados.
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
  usuário (só assina o APK) e nunca deve ser trocada. Plano e regras em `app-movel/README.md`. Função Máquinas
  (operador): `App\Services\Aplicativo\MaquinasAplicativo` — máquinas ativas com IoT ativo, apontamento com legenda
  `oee.app.legenda_sem_motivo` (98, do IoT) obriga escolher um motivo do tipo parada (troca a legenda só dele), máquina
  sem OP obriga escolher a OP, troca de OP divide o apontamento em aberto, cavidades ativas editáveis pelo operador.
  Função Ordens de serviço (mantenedor): `App\Services\Aplicativo\OrdensServicoAplicativo` sobre `AcessoOs` e
  `FluxoOrdemServico` — "assumir" SS = aprovar com o mantenedor como principal (só o primeiro leva), apontamento de
  horas com a tela travada no cronômetro, avaliação do solicitante no celular do mantenedor com assinatura
  (`os_assinaturas`, PNG base64).

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
