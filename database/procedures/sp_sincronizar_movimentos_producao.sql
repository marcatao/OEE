/*
 * Sincronização dos Apontamentos OP (movimentos internos com OP): SD3010 do Protheus, no servidor vinculado
 * [ALUMBRAPRD].[CMT8F4_145045_PR_PD] (só LEITURA) → dbo.movimentos_producao (portal).
 * Sem parâmetros: EXEC dbo.sp_sincronizar_movimentos_producao
 * Pode ser rodada no SSMS, por um job do SQL Server Agent ou pelo portal (tela Banco de dados / protheus:sincronizar /
 * periodicidade). Instalada pelas migrações (só SQL Server); depois de mudar este arquivo, crie uma migração que chame
 * ProceduresBanco::instalar() ou rode "php artisan banco:procedures".
 *
 * Período aberto + o último mês FECHADO: lê da SD3010 os movimentos com D3_EMISSAO maior que o PENÚLTIMO fechamento
 * (a data da SB9010 não excluída antes da mais recente). Assim o recálculo do custo médio (MATA330) e a virada
 * (MATA280) do mês que acabou de fechar ainda chegam ao portal; o que fechou antes disso não muda mais. O filtro roda
 * no servidor do Protheus (OPENQUERY), então só essas linhas vêm pela rede. Leitura vazia com movimentos do período
 * no portal = erro (nada é apagado).
 * - só linhas com OP (D3_ORDEM preenchido, o campo de config protheus.sd3_campo_op);
 * - MERGE pelo R_E_C_N_O_: inclui as novas e altera só as que mudaram;
 * - APAGA do portal os movimentos excluídos no Protheus (D_E_L_E_T_ = '*'), os já marcados como excluídos por
 *   sincronizações antigas e os do período aberto que sumiram da SD3010 (ou ficaram sem OP);
 * - tipo pelo prefixo do D3_CF (PR produção, RE consumo, DE devolução; config protheus.prefixos_d3_cf);
 * - custo_medio: custo médio de estoque do item (SB2010; B2_CM1 do armazém, senão a média ponderada dos armazéns da
 *   filial, senão o maior B2_CM1), como o ImportadorMovimentosProducao.
 * Devolve uma linha: lidos, novos, atualizados, excluidos, ignorados, ultimo_recno, fechamento.
 */
ALTER PROCEDURE dbo.sp_sincronizar_movimentos_producao
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @agora datetime = GETUTCDATE();
    DECLARE @sql nvarchar(max), @remota nvarchar(max), @fechamento varchar(8);
    DECLARE @lidos int, @ultimo int, @ignorados int = 0, @excluidos int = 0, @sumiram int = 0;
    DECLARE @acoes TABLE (acao nvarchar(10));

    IF NOT EXISTS (SELECT 1 FROM sys.servers WHERE name = N'ALUMBRAPRD')
    BEGIN
        RAISERROR(N'O servidor vinculado ALUMBRAPRD não existe neste SQL Server (sys.servers). Peça ao TI para criá-lo, com acesso de leitura ao banco CMT8F4_145045_PR_PD.', 16, 1);
        RETURN;
    END;

    -- 1) Último fechamento do estoque (SB9010 não excluída). Tudo por SQL dinâmico: a procedure instala mesmo onde o
    --    servidor vinculado não existe (ex.: outro ambiente).
    CREATE TABLE #fechamento (ultimo varchar(8) NULL);
    --    Penúltimo fechamento: o mês que acabou de fechar ainda é relido (custo recalculado e virada).
    SET @remota = N'SELECT MAX(B9_DATA) AS ULTIMO FROM CMT8F4_145045_PR_PD.dbo.SB9010 WHERE D_E_L_E_T_ = '' '' '
        + N'AND B9_DATA < (SELECT MAX(B9_DATA) FROM CMT8F4_145045_PR_PD.dbo.SB9010 WHERE D_E_L_E_T_ = '' '')';
    SET @sql = N'INSERT INTO #fechamento (ultimo) SELECT ULTIMO FROM OPENQUERY(ALUMBRAPRD, '''
        + REPLACE(@remota, N'''', N'''''') + N''')';
    EXEC sp_executesql @sql;
    SET @fechamento = (SELECT RTRIM(ultimo) FROM #fechamento);
    IF @fechamento IS NULL OR @fechamento NOT LIKE '[1-2][0-9][0-9][0-9][0-1][0-9][0-3][0-9]'
        SET @fechamento = '00000000'; -- sem fechamento: lê tudo

    -- 2) Movimentos com OP do período aberto (inclusive os excluídos, para apagar do portal).
    CREATE TABLE #sd3 (
        d3_filial nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        d3_tm nvarchar(3) COLLATE DATABASE_DEFAULT NOT NULL,
        d3_cf nvarchar(3) COLLATE DATABASE_DEFAULT NOT NULL,
        d3_cod nvarchar(30) COLLATE DATABASE_DEFAULT NOT NULL,
        d3_um nvarchar(2) COLLATE DATABASE_DEFAULT NOT NULL,
        d3_quant float NOT NULL,
        d3_local nvarchar(4) COLLATE DATABASE_DEFAULT NOT NULL,
        d3_doc nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        d3_emissao nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        d3_numseq nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        d3_custo1 float NOT NULL,
        d3_estorno nvarchar(1) COLLATE DATABASE_DEFAULT NOT NULL,
        op nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        op_numero nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        tipo nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        d_e_l_e_t_ nchar(1) COLLATE DATABASE_DEFAULT NOT NULL,
        r_e_c_n_o_ int NOT NULL PRIMARY KEY,
        custo_medio float NULL
    );

    SET @remota = N'SELECT D3_FILIAL, D3_TM, D3_CF, D3_COD, D3_UM, D3_QUANT, D3_LOCAL, D3_DOC, D3_EMISSAO, D3_NUMSEQ, '
        + N'D3_CUSTO1, D3_ESTORNO, D3_ORDEM, D_E_L_E_T_, R_E_C_N_O_ FROM CMT8F4_145045_PR_PD.dbo.SD3010 '
        + N'WHERE D3_EMISSAO > ''' + @fechamento + N''' AND LEN(D3_ORDEM) > 0';
    SET @sql = N'INSERT INTO #sd3 (d3_filial, d3_tm, d3_cf, d3_cod, d3_um, d3_quant, d3_local, d3_doc, d3_emissao, d3_numseq, '
        + N'd3_custo1, d3_estorno, op, op_numero, tipo, d_e_l_e_t_, r_e_c_n_o_) '
        + N'SELECT LEFT(RTRIM(D3_FILIAL), 8), LEFT(RTRIM(D3_TM), 3), UPPER(LEFT(RTRIM(D3_CF), 3)), LEFT(RTRIM(D3_COD), 30), '
        + N'LEFT(RTRIM(D3_UM), 2), ISNULL(D3_QUANT, 0), LEFT(RTRIM(D3_LOCAL), 4), LEFT(RTRIM(D3_DOC), 20), '
        + N'LEFT(RTRIM(D3_EMISSAO), 8), LEFT(RTRIM(D3_NUMSEQ), 10), ISNULL(D3_CUSTO1, 0), UPPER(LEFT(RTRIM(D3_ESTORNO), 1)), '
        + N'LEFT(LTRIM(RTRIM(D3_ORDEM)), 20), LEFT(LTRIM(RTRIM(D3_ORDEM)), 11), '
        + N'CASE LEFT(UPPER(LTRIM(D3_CF)), 2) WHEN ''PR'' THEN ''producao'' WHEN ''RE'' THEN ''consumo'' '
        + N'WHEN ''DE'' THEN ''devolucao'' ELSE ''outro'' END, '
        + N'CASE WHEN D_E_L_E_T_ = ''*'' THEN ''*'' ELSE '' '' END, R_E_C_N_O_ '
        + N'FROM OPENQUERY(ALUMBRAPRD, ''' + REPLACE(@remota, N'''', N'''''') + N''')';
    EXEC sp_executesql @sql;

    SELECT @lidos = COUNT(*), @ultimo = MAX(r_e_c_n_o_) FROM #sd3;

    -- Leitura vazia com movimentos do período no portal: algo errado no servidor vinculado; não apaga nada.
    IF @lidos = 0 AND EXISTS (SELECT 1 FROM dbo.movimentos_producao WHERE d3_emissao > @fechamento)
    BEGIN
        RAISERROR(N'A leitura da SD3010 no ALUMBRAPRD não trouxe nenhum movimento do período, mas o portal tem: nada foi apagado. Confira o acesso ao servidor vinculado.', 16, 1);
        RETURN;
    END;

    -- 3) Custo médio de estoque do item (SB2010), para valorizar o movimento que o Protheus ainda não valorizou.
    CREATE TABLE #sb2 (
        filial nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        produto nvarchar(30) COLLATE DATABASE_DEFAULT NOT NULL,
        armazem nvarchar(4) COLLATE DATABASE_DEFAULT NOT NULL,
        qatu float NOT NULL, vatu1 float NOT NULL, cm1 float NOT NULL
    );
    IF @lidos > 0
    BEGIN
        SET @sql = N'INSERT INTO #sb2 (filial, produto, armazem, qatu, vatu1, cm1) '
            + N'SELECT LEFT(RTRIM(B2_FILIAL), 8), LEFT(RTRIM(B2_COD), 30), LEFT(RTRIM(B2_LOCAL), 4), '
            + N'ISNULL(B2_QATU, 0), ISNULL(B2_VATU1, 0), ISNULL(B2_CM1, 0) FROM OPENQUERY(ALUMBRAPRD, '
            + N'''SELECT B2_FILIAL, B2_COD, B2_LOCAL, B2_QATU, B2_VATU1, B2_CM1 FROM CMT8F4_145045_PR_PD.dbo.SB2010 '
            + N'WHERE D_E_L_E_T_ = '''''''' '')';
        EXEC sp_executesql @sql;
        CREATE INDEX ix_sb2 ON #sb2 (filial, produto);

        WITH geral AS (
            SELECT filial, produto,
                   CASE WHEN SUM(CASE WHEN qatu > 0 THEN qatu ELSE 0 END) > 0 AND SUM(CASE WHEN vatu1 > 0 THEN vatu1 ELSE 0 END) > 0
                        THEN SUM(CASE WHEN vatu1 > 0 THEN vatu1 ELSE 0 END) / SUM(CASE WHEN qatu > 0 THEN qatu ELSE 0 END)
                        ELSE MAX(cm1) END AS cm
            FROM #sb2 GROUP BY filial, produto
        )
        UPDATE s SET custo_medio = ROUND(CASE WHEN a.cm1 > 0 THEN a.cm1 WHEN g.cm > 0 THEN g.cm END, 6)
        FROM #sd3 s
        LEFT JOIN #sb2 a ON a.filial = s.d3_filial AND a.produto = s.d3_cod AND a.armazem = s.d3_local
        LEFT JOIN geral g ON g.filial = s.d3_filial AND g.produto = s.d3_cod;
    END;

    BEGIN TRANSACTION;

    -- 4) Excluídos no Protheus: apaga do portal (e conta os que nunca vieram).
    DELETE t FROM dbo.movimentos_producao t
    JOIN #sd3 s ON s.r_e_c_n_o_ = t.r_e_c_n_o_
    WHERE s.d_e_l_e_t_ = '*';
    SET @excluidos = @@ROWCOUNT;
    SET @ignorados = (SELECT COUNT(*) FROM #sd3 WHERE d_e_l_e_t_ = '*') - @excluidos;
    DELETE FROM #sd3 WHERE d_e_l_e_t_ = '*';

    -- Marcados como excluídos por sincronizações antigas (pelo PHP, que só marcava).
    DELETE FROM dbo.movimentos_producao WHERE d_e_l_e_t_ = '*';
    SET @excluidos += @@ROWCOUNT;

    -- Do período aberto, mas que não estão mais na SD3010 com OP (apagados de vez ou sem OP agora).
    DELETE t FROM dbo.movimentos_producao t
    WHERE t.d3_emissao > @fechamento
      AND NOT EXISTS (SELECT 1 FROM #sd3 s WHERE s.r_e_c_n_o_ = t.r_e_c_n_o_);
    SET @excluidos += @@ROWCOUNT;

    -- 5) MERGE pelo R_E_C_N_O_: novas e alteradas (só as que mudaram algum campo).
    MERGE dbo.movimentos_producao WITH (HOLDLOCK) AS t
    USING #sd3 AS s ON t.r_e_c_n_o_ = s.r_e_c_n_o_
    WHEN MATCHED AND EXISTS (
        SELECT s.d3_filial, s.d3_tm, s.d3_cf, s.d3_cod, s.d3_um, s.d3_quant, s.d3_local, s.d3_doc, s.d3_emissao,
               s.d3_numseq, s.d3_custo1, s.d3_estorno, s.op, s.op_numero, s.tipo, s.custo_medio
        EXCEPT
        SELECT t.d3_filial, t.d3_tm, t.d3_cf, t.d3_cod, t.d3_um, t.d3_quant, t.d3_local, t.d3_doc, t.d3_emissao,
               t.d3_numseq, t.d3_custo1, t.d3_estorno, t.op, t.op_numero, t.tipo, t.custo_medio
    ) THEN UPDATE SET
        d3_filial = s.d3_filial, d3_tm = s.d3_tm, d3_cf = s.d3_cf, d3_cod = s.d3_cod, d3_um = s.d3_um,
        d3_quant = s.d3_quant, d3_local = s.d3_local, d3_doc = s.d3_doc, d3_emissao = s.d3_emissao,
        d3_numseq = s.d3_numseq, d3_custo1 = s.d3_custo1, d3_estorno = s.d3_estorno, op = s.op, op_numero = s.op_numero,
        tipo = s.tipo, custo_medio = s.custo_medio, d_e_l_e_t_ = ' ', sincronizado_em = @agora
    WHEN NOT MATCHED BY TARGET THEN INSERT (
        d3_filial, d3_tm, d3_cf, d3_cod, d3_um, d3_quant, d3_local, d3_doc, d3_emissao, d3_numseq, d3_custo1,
        d3_estorno, op, op_numero, tipo, custo_medio, d_e_l_e_t_, r_e_c_n_o_, sincronizado_em
    ) VALUES (
        s.d3_filial, s.d3_tm, s.d3_cf, s.d3_cod, s.d3_um, s.d3_quant, s.d3_local, s.d3_doc, s.d3_emissao, s.d3_numseq,
        s.d3_custo1, s.d3_estorno, s.op, s.op_numero, s.tipo, s.custo_medio, ' ', s.r_e_c_n_o_, @agora
    )
    OUTPUT $action INTO @acoes (acao);

    COMMIT TRANSACTION;

    SELECT ISNULL(@lidos, 0) AS lidos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'INSERT') AS novos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'UPDATE') AS atualizados,
           @excluidos AS excluidos,
           @ignorados AS ignorados,
           @ultimo AS ultimo_recno,
           @fechamento AS fechamento;
END
