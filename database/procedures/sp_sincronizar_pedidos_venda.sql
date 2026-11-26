/*
 * Sincronização dos pedidos de venda: SC5010 (cabeçalho) + SC6010 (itens) + SA1010 (nome do cliente) do Protheus, no
 * servidor vinculado [ALUMBRAPRD].[CMT8F4_145045_PR_PD] (só LEITURA) → dbo.pedidos_venda (portal), UMA linha por item.
 * Sem parâmetros: EXEC dbo.sp_sincronizar_pedidos_venda
 * Pode ser rodada no SSMS, por um job do SQL Server Agent ou pelo portal (tela Banco de dados / protheus:sincronizar /
 * periodicidade). Instalada pelas migrações (só SQL Server); depois de mudar este arquivo, crie uma migração que chame
 * ProceduresBanco::instalar() ou rode "php artisan banco:procedures".
 *
 * - A junção (item × cabeçalho × cliente) roda no servidor do Protheus (OPENQUERY): só vêm pela rede os itens não
 *   excluídos de pedidos não excluídos, já com o cabeçalho;
 * - cliente: SA1 pelo C5_CLIENTE + C5_LOJACLI, na filial do pedido ou compartilhada (em branco);
 * - MERGE pelo R_E_C_N_O_ do item: inclui os novos e altera só os que mudaram algum campo;
 * - APAGA do portal os itens que não vieram (excluídos no Protheus, item ou cabeçalho, ou apagados de vez);
 * - situacao: eliminado (C6_BLQ = 'R', resíduo) > faturado (entregue >= vendido) > parcial (entregue > 0) > aberto.
 * Por segurança, leitura vazia com pedidos no portal = erro (nada é apagado).
 * Devolve uma linha: lidos, novos, atualizados, excluidos, ignorados, ultimo_recno.
 */
ALTER PROCEDURE dbo.sp_sincronizar_pedidos_venda
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @agora datetime = GETUTCDATE();
    DECLARE @sql nvarchar(max), @remota nvarchar(max);
    DECLARE @lidos int, @ultimo int, @excluidos int = 0;
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
        + N'WHERE C6.D_E_L_E_T_ = '' ''';
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

    -- Leitura vazia com pedidos no portal: algo errado no servidor vinculado; não apaga nada.
    IF @lidos = 0 AND EXISTS (SELECT 1 FROM dbo.pedidos_venda)
    BEGIN
        RAISERROR(N'A leitura da SC5010/SC6010 no ALUMBRAPRD não trouxe nenhum item, mas o portal tem pedidos: nada foi apagado. Confira o acesso ao servidor vinculado.', 16, 1);
        RETURN;
    END;

    BEGIN TRANSACTION;

    -- 2) Itens que não vieram (excluídos no Protheus, item ou cabeçalho, ou apagados de vez): apaga do portal.
    DELETE t FROM dbo.pedidos_venda t
    WHERE NOT EXISTS (SELECT 1 FROM #pv s WHERE s.r_e_c_n_o_ = t.r_e_c_n_o_);
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

    COMMIT TRANSACTION;

    SELECT ISNULL(@lidos, 0) AS lidos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'INSERT') AS novos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'UPDATE') AS atualizados,
           @excluidos AS excluidos,
           0 AS ignorados,
           @ultimo AS ultimo_recno;
END
