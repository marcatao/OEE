# OEE Alumbra — instruções para o Claude

Portal Laravel 13 (PHP 8.3, versão do servidor) de dashboards de produção e cálculos de OEE. Login pelo Microsoft Entra ID, dados no
SQL Server. Escreva código, textos de tela, comentários e mensagens de commit em português do Brasil.

## Regras do projeto

- O **Entra ID só autentica** a entrada (`app/Services/EntraId.php`). O usuário é cadastrado no banco no primeiro
  login (`usuarios`, chave `entra_oid`, nunca o e-mail) e nasce **sem acesso**; só os e-mails de `config('oee.admins')`
  (padrão `marcato@alumbra.com.br`) nascem `admin`. Todo login vai para `registros_login`.
- Acesso: perfil (`sem_acesso` | `operador` | `admin`) + **grupos**. Os grupos dão permissões de página
  (`grupo_permissoes`, catálogo em `config('oee.permissoes')`). Admin pode tudo. Só admin cria grupos e altera
  cadastros.
- Banco principal: **SQL Server** (`DB_CONNECTION=sqlsrv`, configurado no `.env`). Testes rodam em SQLite em memória:
  tudo precisa funcionar nos dois. Recursos só do SQL Server ficam atrás de `DB::getDriverName() === 'sqlsrv'`.
- Datas gravadas em **UTC** (`config/app.php` usa `UTC`) e exibidas no fuso local com `@dataHora($valor)`.
- Tabelas e colunas em português. Modelos de cadastro usam o trait `App\Models\Concerns\Auditavel`
  (`criado_em`, `criado_por_id`, `atualizado_em`, `atualizado_por_id`). Cadastros são desativados, não excluídos.
- Nome do portal: **OEE Alumbra**, em `config('oee.nome')` (nunca escreva o nome direto nas telas).
- Identidade visual em **tons de verde** (estilo da empresa): as cores ficam só nas variáveis do topo de
  `public/css/portal.css` (tema claro e escuro). Nunca use cores soltas nas telas.
- Telas em Blade com `layouts.portal` e o CSS de `public/css/portal.css`. Sem etapa de build com Node.
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
  SD3/SD2 são incrementais (`ImportadorEspelho::primeiroRecno`: novos + últimos `protheus.reler_dias` dias);
  `--completa` (e `protheus.agenda_completa`, semanal) relê tudo.
- Filtros salvos das telas de relatório ficam em `filtros_usuarios` (por usuário e tela, com um "padrão"). Na
  Timeline: `App\Services\Filtros\FiltroTimeline` (De/Até padrão hoje 01:00–23:59; plantas, linhas e máquinas) e a
  área de resultado é trocada sem recarregar a página (`?parcial=1`). Os filtros podem ser escondidos (botão +
  linha de resumo; escolha lembrada no navegador) e se recolhem sozinhos após 3 minutos sem mexer na tela. Novos
  relatórios seguem o mesmo padrão.
- Canais IoT da máquina usam `iotN_dispositivo_id` (FK para `dispositivos_iot`). As colunas antigas `iotN_id`
  (texto) só são lidas, para mostrar o valor antigo; não grave nelas.
- Fórmulas de OEE só em `app/Services/CalculadoraOee.php` (ou serviços irmãos), com testes.

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
