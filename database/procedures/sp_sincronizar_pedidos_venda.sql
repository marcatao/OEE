/*
 * Sincronização dos pedidos de venda: SC5010 (cabeçalho) + SC6010 (itens) + SA1010 (nome do cliente) do Protheus, no
 * servidor vinculado [ALUMBRAPRD].[CMT8F4_145045_PR_PD] (só LEITURA) → dbo.pedidos_venda (portal), UMA linha por item.
 * Sem parâmetros: EXEC dbo.sp_sincronizar_pedidos_venda
 * Pode ser rodada no SSMS, por um job do SQL Server Agent ou pelo portal (tela Banco de dados / protheus:sincronizar /
 * periodicidade). Instalada pelas migrações (só SQL Server); depois de mudar este arquivo, crie uma migração que chame
 * ProceduresBanco::instalar() ou rode "php artisan banco:procedures".
 *
 * - Janela de 6 meses: só os pedidos com C5_EMISSAO nos últimos 6 meses são lidos, incluídos, alterados e apagados;
 *   os mais antigos ficam no portal como histórico, sem mexer. Com a tabela do portal VAZIA, faz a carga geral (tudo).
 * - A junção (item × cabeçalho × cliente) roda no servidor do Protheus (OPENQUERY): só vêm pela rede os itens não
 *   excluídos de pedidos não excluídos da janela, já com o cabeçalho;
 * - cliente: SA1 pelo C5_CLIENTE + C5_LOJACLI, na filial do pedido ou compartilhada (em branco);
 * - MERGE pelo R_E_C_N_O_ do item: inclui os novos e altera só os que mudaram algum campo;
 * - APAGA do portal os itens DA JANELA que não vieram (excluídos no Protheus, item ou cabeçalho, ou apagados de vez);
 * - situacao: eliminado (C6_BLQ = 'R', resíduo) > faturado (entregue >= vendido) > parcial (entregue > 0) > aberto.
 * Por segurança, leitura vazia com pedidos da janela no portal = erro (nada é apagado).
 * LIBERAÇÕES (SC9010 → dbo.pedidos_venda_liberacoes, Faturamento › Pedidos de venda, 26/11/2026), no MESMO sincronismo:
 * - as liberações não excluídas dos pedidos da janela (mesma filial e número, C5 não excluído), com o C5_EMISSAO;
 * - MERGE pelo R_E_C_N_O_ da SC9; APAGA do portal as liberações DA JANELA que não vieram (estornadas/excluídas);
 * - "extras" (JSON, FOR JSON: SQL Server 2016+): campos do WMS (C9_ORDSEP, C9_CARGA, C9_SEQCAR, C9_SERVIC, C9_STSERV,
 *   C9_ENDPAD = EtapasPedidoVenda::CAMPOS_WMS) e os das regras ativas de dbo.regras_etapa_pedido, só os que EXISTEM na
 *   SC9010 (nome validado: letras, números e _). As etapas são calculadas depois pelo PHP, só com as tabelas do portal.
 * ETAPAS: no fim, chama dbo.sp_calcular_etapas_pedidos_venda (etapa de cada pedido em dbo.pedidos_venda_etapas, só com
 * as tabelas do portal). O portal NUNCA lê o Protheus direto e só faz EXEC desta procedure: tudo acontece aqui.
 * Devolve uma linha: lidos, novos, atualizados, excluidos, ignorados, ultimo_recno, corte (AAAAMMDD; 00000000 = tudo),
 * etapas (pedidos que mudaram de etapa, valor ou data).
 */
ALTER PROCEDURE dbo.sp_sincronizar_pedidos_venda
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @agora datetime = GETUTCDATE();
    DECLARE @sql nvarchar(max), @remota nvarchar(max);
    -- Janela: pedidos emitidos a partir de hoje − 6 meses (AAAAMMDD, como o C5_EMISSAO). Portal vazio = carga geral.
    DECLARE @corte varchar(8) = CONVERT(varchar(8), DATEADD(MONTH, -6, CAST(GETDATE() AS date)), 112);
    IF NOT EXISTS (SELECT 1 FROM dbo.pedidos_venda) SET @corte = '00000000';
    DECLARE @lidos int, @ultimo int, @excluidos int = 0, @lidos9 int = 0;
    DECLARE @extras9 nvarchar(max), @rem9 nvarchar(max), @etapas int = 0;
    DECLARE @acoes TABLE (acao nvarchar(10));

    IF NOT EXISTS (SELECT 1 FROM sys.servers WHERE name = N'ALUMBRAPRD')
    BEGIN
        RAISERROR(N'O servidor vinculado ALUMBRAPRD não existe neste SQL Server (sys.servers). Peça ao TI para criá-lo, com acesso de leitura ao banco CMT8F4_145045_PR_PD.', 16, 1);
        RETURN;
    END;

    CREATE TABLE #pv (
        c5_filial nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        c5_num nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        c5_tipo nvarchar(1) COLLATE DATABASE_DEFAULT NOT NULL,
        c5_cliente nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        c5_lojacli nvarchar(4) COLLATE DATABASE_DEFAULT NOT NULL,
        cliente_nome nvarchar(60) COLLATE DATABASE_DEFAULT NOT NULL,
        c5_condpag nvarchar(3) COLLATE DATABASE_DEFAULT NOT NULL,
        c5_emissao nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        c5_vend1 nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        c5_transp nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        c5_nota nvarchar(9) COLLATE DATABASE_DEFAULT NOT NULL,
        c5_serie nvarchar(3) COLLATE DATABASE_DEFAULT NOT NULL,
        c5_liberok nvarchar(1) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_item nvarchar(4) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_produto nvarchar(30) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_descri nvarchar(60) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_um nvarchar(2) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_qtdven float NOT NULL,
        c6_prcven float NOT NULL,
        c6_valor float NOT NULL,
        c6_qtdent float NOT NULL,
        c6_entreg nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_tes nvarchar(3) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_local nvarchar(4) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_nota nvarchar(9) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_serie nvarchar(3) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_datfat nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_blq nvarchar(2) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_numop nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_itemop nvarchar(4) COLLATE DATABASE_DEFAULT NOT NULL,
        c6_pedcli nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        situacao nvarchar(12) COLLATE DATABASE_DEFAULT NOT NULL,
        c5_recno int NULL,
        r_e_c_n_o_ int NOT NULL PRIMARY KEY
    );

    -- 1) Itens + cabeçalho + cliente, já juntos no servidor do Protheus. SQL dinâmico: a procedure instala mesmo onde
    --    o servidor vinculado não existe (ex.: outro ambiente).
    SET @remota = N'SELECT C5.C5_FILIAL, C5.C5_NUM, C5.C5_TIPO, C5.C5_CLIENTE, C5.C5_LOJACLI, ISNULL(A1.A1_NOME, '''') AS A1_NOME, '
        + N'C5.C5_CONDPAG, C5.C5_EMISSAO, C5.C5_VEND1, C5.C5_TRANSP, C5.C5_NOTA, C5.C5_SERIE, C5.C5_LIBEROK, '
        + N'C6.C6_ITEM, C6.C6_PRODUTO, C6.C6_DESCRI, C6.C6_UM, C6.C6_QTDVEN, C6.C6_PRCVEN, C6.C6_VALOR, C6.C6_QTDENT, '
        + N'C6.C6_ENTREG, C6.C6_TES, C6.C6_LOCAL, C6.C6_NOTA, C6.C6_SERIE, C6.C6_DATFAT, C6.C6_BLQ, C6.C6_NUMOP, '
        + N'C6.C6_ITEMOP, C6.C6_PEDCLI, C5.R_E_C_N_O_ AS C5_RECNO, C6.R_E_C_N_O_ AS C6_RECNO '
        + N'FROM CMT8F4_145045_PR_PD.dbo.SC6010 C6 '
        + N'JOIN CMT8F4_145045_PR_PD.dbo.SC5010 C5 ON C5.C5_FILIAL = C6.C6_FILIAL AND C5.C5_NUM = C6.C6_NUM AND C5.D_E_L_E_T_ = '' '' '
        + N'OUTER APPLY (SELECT TOP 1 A1_NOME FROM CMT8F4_145045_PR_PD.dbo.SA1010 SA1 '
        + N'WHERE SA1.A1_COD = C5.C5_CLIENTE AND SA1.A1_LOJA = C5.C5_LOJACLI AND SA1.D_E_L_E_T_ = '' '' '
        + N'AND (SA1.A1_FILIAL = C5.C5_FILIAL OR SA1.A1_FILIAL = '' '') ORDER BY SA1.A1_FILIAL DESC) A1 '
        + N'WHERE C6.D_E_L_E_T_ = '' '' AND C5.C5_EMISSAO >= ''' + @corte + N'''';
    SET @sql = N'INSERT INTO #pv (c5_filial, c5_num, c5_tipo, c5_cliente, c5_lojacli, cliente_nome, c5_condpag, c5_emissao, '
        + N'c5_vend1, c5_transp, c5_nota, c5_serie, c5_liberok, c6_item, c6_produto, c6_descri, c6_um, c6_qtdven, c6_prcven, '
        + N'c6_valor, c6_qtdent, c6_entreg, c6_tes, c6_local, c6_nota, c6_serie, c6_datfat, c6_blq, c6_numop, c6_itemop, '
        + N'c6_pedcli, situacao, c5_recno, r_e_c_n_o_) '
        + N'SELECT LEFT(RTRIM(C5_FILIAL), 8), LEFT(RTRIM(C5_NUM), 10), LEFT(RTRIM(C5_TIPO), 1), LEFT(RTRIM(C5_CLIENTE), 10), '
        + N'LEFT(RTRIM(C5_LOJACLI), 4), LEFT(RTRIM(A1_NOME), 60), LEFT(RTRIM(C5_CONDPAG), 3), LEFT(RTRIM(C5_EMISSAO), 8), '
        + N'LEFT(RTRIM(C5_VEND1), 10), LEFT(RTRIM(C5_TRANSP), 10), LEFT(RTRIM(C5_NOTA), 9), LEFT(RTRIM(C5_SERIE), 3), '
        + N'LEFT(RTRIM(C5_LIBEROK), 1), LEFT(RTRIM(C6_ITEM), 4), LEFT(RTRIM(C6_PRODUTO), 30), LEFT(RTRIM(C6_DESCRI), 60), '
        + N'LEFT(RTRIM(C6_UM), 2), ISNULL(C6_QTDVEN, 0), ISNULL(C6_PRCVEN, 0), ISNULL(C6_VALOR, 0), ISNULL(C6_QTDENT, 0), '
        + N'LEFT(RTRIM(C6_ENTREG), 8), LEFT(RTRIM(C6_TES), 3), LEFT(RTRIM(C6_LOCAL), 4), LEFT(RTRIM(C6_NOTA), 9), '
        + N'LEFT(RTRIM(C6_SERIE), 3), LEFT(RTRIM(C6_DATFAT), 8), LEFT(RTRIM(C6_BLQ), 2), LEFT(RTRIM(C6_NUMOP), 10), '
        + N'LEFT(RTRIM(C6_ITEMOP), 4), LEFT(RTRIM(C6_PEDCLI), 20), '
        + N'CASE WHEN UPPER(RTRIM(C6_BLQ)) = ''R'' THEN ''eliminado'' '
        + N'WHEN ISNULL(C6_QTDVEN, 0) > 0 AND ISNULL(C6_QTDENT, 0) >= C6_QTDVEN THEN ''faturado'' '
        + N'WHEN ISNULL(C6_QTDENT, 0) > 0 THEN ''parcial'' ELSE ''aberto'' END, '
        + N'C5_RECNO, C6_RECNO '
        + N'FROM OPENQUERY(ALUMBRAPRD, ''' + REPLACE(@remota, N'''', N'''''') + N''')';
    EXEC sp_executesql @sql;

    SELECT @lidos = COUNT(*), @ultimo = MAX(r_e_c_n_o_) FROM #pv;

    -- Leitura vazia com pedidos da janela no portal: algo errado no servidor vinculado; não apaga nada.
    IF @lidos = 0 AND EXISTS (SELECT 1 FROM dbo.pedidos_venda WHERE c5_emissao >= @corte)
    BEGIN
        RAISERROR(N'A leitura da SC5010/SC6010 no ALUMBRAPRD não trouxe nenhum item dos últimos 6 meses, mas o portal tem: nada foi apagado. Confira o acesso ao servidor vinculado.', 16, 1);
        RETURN;
    END;

    -- 1b) Liberações (SC9010) dos pedidos da janela. Campos extras do WMS: os fixos + os das regras, se existirem.
    CREATE TABLE #colunas9 (coluna nvarchar(30) COLLATE DATABASE_DEFAULT);
    SET @remota = N'SELECT COLUMN_NAME FROM CMT8F4_145045_PR_PD.INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ''SC9010''';
    SET @sql = N'INSERT INTO #colunas9 (coluna) SELECT UPPER(COLUMN_NAME) FROM OPENQUERY(ALUMBRAPRD, '''
        + REPLACE(@remota, N'''', N'''''') + N''')';
    EXEC sp_executesql @sql;

    CREATE TABLE #extras9 (campo nvarchar(10) COLLATE DATABASE_DEFAULT PRIMARY KEY);
    INSERT INTO #extras9 (campo)
    SELECT DISTINCT c.campo FROM (
        SELECT v.campo FROM (VALUES (N'C9_ORDSEP'), (N'C9_CARGA'), (N'C9_SEQCAR'), (N'C9_SERVIC'), (N'C9_STSERV'), (N'C9_ENDPAD')) v (campo)
        UNION
        SELECT UPPER(LTRIM(RTRIM(r.campo))) FROM dbo.regras_etapa_pedido r WHERE r.ativo = 1
    ) c
    WHERE c.campo LIKE N'C9[_]%' AND c.campo NOT LIKE N'%[^A-Za-z0-9_]%'
      AND EXISTS (SELECT 1 FROM #colunas9 k WHERE k.coluna = c.campo);
    SET @rem9 = ISNULL((SELECT N', C9.' + campo FROM #extras9 ORDER BY campo FOR XML PATH(''), TYPE).value('.', 'nvarchar(max)'), N'');
    SET @extras9 = STUFF((SELECT N', RTRIM(CONVERT(nvarchar(60), q.' + campo + N')) AS ' + campo FROM #extras9 ORDER BY campo
        FOR XML PATH(''), TYPE).value('.', 'nvarchar(max)'), 1, 2, N'');

    CREATE TABLE #sc9 (
        c9_filial nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        c9_pedido nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        c9_item nvarchar(4) COLLATE DATABASE_DEFAULT NOT NULL,
        c9_sequen nvarchar(3) COLLATE DATABASE_DEFAULT NOT NULL,
        c9_produto nvarchar(30) COLLATE DATABASE_DEFAULT NOT NULL,
        c9_qtdlib float NOT NULL,
        c9_prcven float NOT NULL,
        c9_datalib nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        c9_blest nvarchar(2) COLLATE DATABASE_DEFAULT NOT NULL,
        c9_blcred nvarchar(2) COLLATE DATABASE_DEFAULT NOT NULL,
        c9_nfiscal nvarchar(9) COLLATE DATABASE_DEFAULT NOT NULL,
        c9_serienf nvarchar(3) COLLATE DATABASE_DEFAULT NOT NULL,
        extras nvarchar(max) COLLATE DATABASE_DEFAULT NULL,
        c5_emissao nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        r_e_c_n_o_ int NOT NULL PRIMARY KEY
    );
    SET @remota = N'SELECT C9.C9_FILIAL, C9.C9_PEDIDO, C9.C9_ITEM, C9.C9_SEQUEN, C9.C9_PRODUTO, C9.C9_QTDLIB, C9.C9_PRCVEN, '
        + N'C9.C9_DATALIB, C9.C9_BLEST, C9.C9_BLCRED, C9.C9_NFISCAL, C9.C9_SERIENF, C5.C5_EMISSAO, C9.R_E_C_N_O_ AS C9_RECNO' + @rem9 + N' '
        + N'FROM CMT8F4_145045_PR_PD.dbo.SC9010 C9 '
        + N'JOIN CMT8F4_145045_PR_PD.dbo.SC5010 C5 ON C5.C5_FILIAL = C9.C9_FILIAL AND C5.C5_NUM = C9.C9_PEDIDO AND C5.D_E_L_E_T_ = '' '' '
        + N'WHERE C9.D_E_L_E_T_ = '' '' AND C5.C5_EMISSAO >= ''' + @corte + N'''';
    SET @sql = N'INSERT INTO #sc9 (c9_filial, c9_pedido, c9_item, c9_sequen, c9_produto, c9_qtdlib, c9_prcven, c9_datalib, '
        + N'c9_blest, c9_blcred, c9_nfiscal, c9_serienf, extras, c5_emissao, r_e_c_n_o_) '
        + N'SELECT LEFT(RTRIM(q.C9_FILIAL), 8), LEFT(RTRIM(q.C9_PEDIDO), 10), LEFT(RTRIM(q.C9_ITEM), 4), LEFT(RTRIM(q.C9_SEQUEN), 3), '
        + N'LEFT(RTRIM(q.C9_PRODUTO), 30), ISNULL(q.C9_QTDLIB, 0), ISNULL(q.C9_PRCVEN, 0), LEFT(RTRIM(q.C9_DATALIB), 8), '
        + N'LEFT(RTRIM(q.C9_BLEST), 2), LEFT(RTRIM(q.C9_BLCRED), 2), LEFT(RTRIM(q.C9_NFISCAL), 9), LEFT(RTRIM(q.C9_SERIENF), 3), '
        + CASE WHEN @extras9 IS NULL THEN N'NULL' ELSE N'(SELECT ' + @extras9 + N' FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)' END + N', '
        + N'LEFT(RTRIM(q.C5_EMISSAO), 8), q.C9_RECNO '
        + N'FROM OPENQUERY(ALUMBRAPRD, ''' + REPLACE(@remota, N'''', N'''''') + N''') q';
    EXEC sp_executesql @sql;
    SELECT @lidos9 = COUNT(*) FROM #sc9;

    BEGIN TRANSACTION;

    -- 2) Itens DA JANELA que não vieram (excluídos no Protheus, item ou cabeçalho, ou apagados de vez): apaga do portal.
    --    O histórico (antes do corte) não é tocado.
    DELETE t FROM dbo.pedidos_venda t
    WHERE t.c5_emissao >= @corte
      AND NOT EXISTS (SELECT 1 FROM #pv s WHERE s.r_e_c_n_o_ = t.r_e_c_n_o_);
    SET @excluidos = @@ROWCOUNT;

    -- 3) MERGE pelo R_E_C_N_O_ do item: novos e alterados (só os que mudaram algum campo).
    MERGE dbo.pedidos_venda WITH (HOLDLOCK) AS t
    USING #pv AS s ON t.r_e_c_n_o_ = s.r_e_c_n_o_
    WHEN MATCHED AND EXISTS (
        SELECT s.c5_filial, s.c5_num, s.c5_tipo, s.c5_cliente, s.c5_lojacli, s.cliente_nome, s.c5_condpag, s.c5_emissao,
               s.c5_vend1, s.c5_transp, s.c5_nota, s.c5_serie, s.c5_liberok, s.c6_item, s.c6_produto, s.c6_descri, s.c6_um,
               s.c6_qtdven, s.c6_prcven, s.c6_valor, s.c6_qtdent, s.c6_entreg, s.c6_tes, s.c6_local, s.c6_nota, s.c6_serie,
               s.c6_datfat, s.c6_blq, s.c6_numop, s.c6_itemop, s.c6_pedcli, s.situacao, s.c5_recno
        EXCEPT
        SELECT t.c5_filial, t.c5_num, t.c5_tipo, t.c5_cliente, t.c5_lojacli, t.cliente_nome, t.c5_condpag, t.c5_emissao,
               t.c5_vend1, t.c5_transp, t.c5_nota, t.c5_serie, t.c5_liberok, t.c6_item, t.c6_produto, t.c6_descri, t.c6_um,
               t.c6_qtdven, t.c6_prcven, t.c6_valor, t.c6_qtdent, t.c6_entreg, t.c6_tes, t.c6_local, t.c6_nota, t.c6_serie,
               t.c6_datfat, t.c6_blq, t.c6_numop, t.c6_itemop, t.c6_pedcli, t.situacao, t.c5_recno
    ) THEN UPDATE SET
        c5_filial = s.c5_filial, c5_num = s.c5_num, c5_tipo = s.c5_tipo, c5_cliente = s.c5_cliente, c5_lojacli = s.c5_lojacli,
        cliente_nome = s.cliente_nome, c5_condpag = s.c5_condpag, c5_emissao = s.c5_emissao, c5_vend1 = s.c5_vend1,
        c5_transp = s.c5_transp, c5_nota = s.c5_nota, c5_serie = s.c5_serie, c5_liberok = s.c5_liberok, c6_item = s.c6_item,
        c6_produto = s.c6_produto, c6_descri = s.c6_descri, c6_um = s.c6_um, c6_qtdven = s.c6_qtdven, c6_prcven = s.c6_prcven,
        c6_valor = s.c6_valor, c6_qtdent = s.c6_qtdent, c6_entreg = s.c6_entreg, c6_tes = s.c6_tes, c6_local = s.c6_local,
        c6_nota = s.c6_nota, c6_serie = s.c6_serie, c6_datfat = s.c6_datfat, c6_blq = s.c6_blq, c6_numop = s.c6_numop,
        c6_itemop = s.c6_itemop, c6_pedcli = s.c6_pedcli, situacao = s.situacao, c5_recno = s.c5_recno, sincronizado_em = @agora
    WHEN NOT MATCHED BY TARGET THEN INSERT (
        c5_filial, c5_num, c5_tipo, c5_cliente, c5_lojacli, cliente_nome, c5_condpag, c5_emissao, c5_vend1, c5_transp,
        c5_nota, c5_serie, c5_liberok, c6_item, c6_produto, c6_descri, c6_um, c6_qtdven, c6_prcven, c6_valor, c6_qtdent,
        c6_entreg, c6_tes, c6_local, c6_nota, c6_serie, c6_datfat, c6_blq, c6_numop, c6_itemop, c6_pedcli, situacao,
        c5_recno, r_e_c_n_o_, sincronizado_em
    ) VALUES (
        s.c5_filial, s.c5_num, s.c5_tipo, s.c5_cliente, s.c5_lojacli, s.cliente_nome, s.c5_condpag, s.c5_emissao, s.c5_vend1,
        s.c5_transp, s.c5_nota, s.c5_serie, s.c5_liberok, s.c6_item, s.c6_produto, s.c6_descri, s.c6_um, s.c6_qtdven,
        s.c6_prcven, s.c6_valor, s.c6_qtdent, s.c6_entreg, s.c6_tes, s.c6_local, s.c6_nota, s.c6_serie, s.c6_datfat,
        s.c6_blq, s.c6_numop, s.c6_itemop, s.c6_pedcli, s.situacao, s.c5_recno, s.r_e_c_n_o_, @agora
    )
    OUTPUT $action INTO @acoes (acao);

    -- 4) Liberações DA JANELA que não vieram (estornadas ou excluídas no Protheus): apaga do portal.
    DELETE t FROM dbo.pedidos_venda_liberacoes t
    WHERE t.c5_emissao >= @corte
      AND NOT EXISTS (SELECT 1 FROM #sc9 s WHERE s.r_e_c_n_o_ = t.r_e_c_n_o_);
    SET @excluidos += @@ROWCOUNT;

    -- 5) MERGE das liberações pelo R_E_C_N_O_ da SC9 (só as que mudaram).
    MERGE dbo.pedidos_venda_liberacoes WITH (HOLDLOCK) AS t
    USING #sc9 AS s ON t.r_e_c_n_o_ = s.r_e_c_n_o_
    WHEN MATCHED AND EXISTS (
        SELECT s.c9_filial, s.c9_pedido, s.c9_item, s.c9_sequen, s.c9_produto, s.c9_qtdlib, s.c9_prcven, s.c9_datalib,
               s.c9_blest, s.c9_blcred, s.c9_nfiscal, s.c9_serienf, s.extras, s.c5_emissao
        EXCEPT
        SELECT t.c9_filial, t.c9_pedido, t.c9_item, t.c9_sequen, t.c9_produto, t.c9_qtdlib, t.c9_prcven, t.c9_datalib,
               t.c9_blest, t.c9_blcred, t.c9_nfiscal, t.c9_serienf, t.extras, t.c5_emissao
    ) THEN UPDATE SET
        c9_filial = s.c9_filial, c9_pedido = s.c9_pedido, c9_item = s.c9_item, c9_sequen = s.c9_sequen, c9_produto = s.c9_produto,
        c9_qtdlib = s.c9_qtdlib, c9_prcven = s.c9_prcven, c9_datalib = s.c9_datalib, c9_blest = s.c9_blest, c9_blcred = s.c9_blcred,
        c9_nfiscal = s.c9_nfiscal, c9_serienf = s.c9_serienf, extras = s.extras, c5_emissao = s.c5_emissao, sincronizado_em = @agora
    WHEN NOT MATCHED BY TARGET THEN INSERT (
        c9_filial, c9_pedido, c9_item, c9_sequen, c9_produto, c9_qtdlib, c9_prcven, c9_datalib, c9_blest, c9_blcred, c9_nfiscal,
        c9_serienf, extras, c5_emissao, r_e_c_n_o_, sincronizado_em
    ) VALUES (
        s.c9_filial, s.c9_pedido, s.c9_item, s.c9_sequen, s.c9_produto, s.c9_qtdlib, s.c9_prcven, s.c9_datalib, s.c9_blest,
        s.c9_blcred, s.c9_nfiscal, s.c9_serienf, s.extras, s.c5_emissao, s.r_e_c_n_o_, @agora
    )
    OUTPUT $action INTO @acoes (acao);

    COMMIT TRANSACTION;

    -- 6) Etapas dos pedidos (Faturamento), em SQL, sem devolver linha (o resumo abaixo é a única linha da procedure).
    EXEC dbo.sp_calcular_etapas_pedidos_venda @devolver = 0, @alterados = @etapas OUTPUT;

    SELECT ISNULL(@lidos, 0) + @lidos9 AS lidos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'INSERT') AS novos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'UPDATE') AS atualizados,
           @excluidos AS excluidos,
           0 AS ignorados,
           @ultimo AS ultimo_recno,
           @corte AS corte,
           @etapas AS etapas;
END
