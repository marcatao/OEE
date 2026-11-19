/*
 * Sincronização dos Empenhos OP: SD4010 do Protheus, no servidor vinculado [ALUMBRAPRD].[CMT8F4_145045_PR_PD]
 * (só LEITURA) → dbo.empenhos_op (portal). Sem parâmetros: EXEC dbo.sp_sincronizar_empenhos_op
 * Pode ser rodada no SSMS, por um job do SQL Server Agent ou pelo portal (tela Banco de dados / protheus:sincronizar /
 * periodicidade). Instalada pelas migrações (só SQL Server); depois de mudar este arquivo, crie uma migração que chame
 * ProceduresBanco::instalar() ou rode "php artisan banco:procedures".
 *
 * Lê a SD4010 INTEIRA (o saldo D4_QUANT muda até a OP fechar), só as linhas com OP (D4_OP preenchido), por OPENQUERY
 * (o filtro roda no servidor do Protheus):
 * - MERGE pelo R_E_C_N_O_: inclui as novas e altera só as que mudaram;
 * - APAGA do portal os empenhos excluídos no Protheus (D_E_L_E_T_ = '*'), os já marcados como excluídos por
 *   sincronizações antigas e os que sumiram da SD4010 (ou ficaram sem OP);
 * - op_numero = os 11 primeiros caracteres do D4_OP (C2_NUM + C2_ITEM + C2_SEQUEN), como o ImportadorEmpenhos.
 * Por segurança, se a leitura não trouxer nenhuma linha e o portal tiver empenhos, para com erro (não apaga tudo).
 * Devolve uma linha: lidos, novos, atualizados, excluidos, ignorados, ultimo_recno.
 */
ALTER PROCEDURE dbo.sp_sincronizar_empenhos_op
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @agora datetime = GETUTCDATE();
    DECLARE @sql nvarchar(max);
    DECLARE @lidos int, @ultimo int, @ignorados int = 0, @excluidos int = 0;
    DECLARE @acoes TABLE (acao nvarchar(10));

    IF NOT EXISTS (SELECT 1 FROM sys.servers WHERE name = N'ALUMBRAPRD')
    BEGIN
        RAISERROR(N'O servidor vinculado ALUMBRAPRD não existe neste SQL Server (sys.servers). Peça ao TI para criá-lo, com acesso de leitura ao banco CMT8F4_145045_PR_PD.', 16, 1);
        RETURN;
    END;

    CREATE TABLE #sd4 (
        d4_filial nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        d4_cod nvarchar(30) COLLATE DATABASE_DEFAULT NOT NULL,
        d4_op nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        op_numero nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        d4_local nvarchar(4) COLLATE DATABASE_DEFAULT NOT NULL,
        d4_data nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        d4_qtdeori float NOT NULL,
        d4_quant float NOT NULL,
        d4_trt nvarchar(3) COLLATE DATABASE_DEFAULT NOT NULL,
        d4_lotectl nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        d4_oporig nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        d_e_l_e_t_ nchar(1) COLLATE DATABASE_DEFAULT NOT NULL,
        r_e_c_n_o_ int NOT NULL PRIMARY KEY
    );

    -- 1) SD4010 com OP (inclusive as excluídas, para apagar do portal). SQL dinâmico: a procedure instala mesmo onde o
    --    servidor vinculado não existe (ex.: outro ambiente).
    SET @sql = N'INSERT INTO #sd4 (d4_filial, d4_cod, d4_op, op_numero, d4_local, d4_data, d4_qtdeori, d4_quant, d4_trt, '
        + N'd4_lotectl, d4_oporig, d_e_l_e_t_, r_e_c_n_o_) '
        + N'SELECT LEFT(RTRIM(D4_FILIAL), 8), LEFT(RTRIM(D4_COD), 30), LEFT(LTRIM(RTRIM(D4_OP)), 20), LEFT(LTRIM(RTRIM(D4_OP)), 11), '
        + N'LEFT(RTRIM(D4_LOCAL), 4), LEFT(RTRIM(D4_DATA), 8), ISNULL(D4_QTDEORI, 0), ISNULL(D4_QUANT, 0), '
        + N'LEFT(RTRIM(D4_TRT), 3), LEFT(RTRIM(D4_LOTECTL), 20), LEFT(RTRIM(D4_OPORIG), 20), '
        + N'CASE WHEN D_E_L_E_T_ = ''*'' THEN ''*'' ELSE '' '' END, R_E_C_N_O_ '
        + N'FROM OPENQUERY(ALUMBRAPRD, ''SELECT D4_FILIAL, D4_COD, D4_OP, D4_LOCAL, D4_DATA, D4_QTDEORI, D4_QUANT, D4_TRT, '
        + N'D4_LOTECTL, D4_OPORIG, D_E_L_E_T_, R_E_C_N_O_ FROM CMT8F4_145045_PR_PD.dbo.SD4010 WHERE LEN(D4_OP) > 0'')';
    EXEC sp_executesql @sql;

    SELECT @lidos = COUNT(*), @ultimo = MAX(r_e_c_n_o_) FROM #sd4;

    IF @lidos = 0 AND EXISTS (SELECT 1 FROM dbo.empenhos_op)
    BEGIN
        RAISERROR(N'A leitura da SD4010 no ALUMBRAPRD não trouxe nenhum empenho, mas o portal tem empenhos: nada foi apagado. Confira o acesso ao servidor vinculado.', 16, 1);
        RETURN;
    END;

    BEGIN TRANSACTION;

    -- 2) Excluídos no Protheus: apaga do portal (e conta os que nunca vieram).
    DELETE t FROM dbo.empenhos_op t
    JOIN #sd4 s ON s.r_e_c_n_o_ = t.r_e_c_n_o_
    WHERE s.d_e_l_e_t_ = '*';
    SET @excluidos = @@ROWCOUNT;
    SET @ignorados = (SELECT COUNT(*) FROM #sd4 WHERE d_e_l_e_t_ = '*') - @excluidos;
    DELETE FROM #sd4 WHERE d_e_l_e_t_ = '*';

    -- Marcados como excluídos por sincronizações antigas (pelo PHP, que só marcava) e os que sumiram da SD4010.
    DELETE t FROM dbo.empenhos_op t
    WHERE t.d_e_l_e_t_ = '*' OR NOT EXISTS (SELECT 1 FROM #sd4 s WHERE s.r_e_c_n_o_ = t.r_e_c_n_o_);
    SET @excluidos += @@ROWCOUNT;

    -- 3) MERGE pelo R_E_C_N_O_: novos e alterados (só os que mudaram algum campo).
    MERGE dbo.empenhos_op WITH (HOLDLOCK) AS t
    USING #sd4 AS s ON t.r_e_c_n_o_ = s.r_e_c_n_o_
    WHEN MATCHED AND EXISTS (
        SELECT s.d4_filial, s.d4_cod, s.d4_op, s.op_numero, s.d4_local, s.d4_data, s.d4_qtdeori, s.d4_quant, s.d4_trt,
               s.d4_lotectl, s.d4_oporig
        EXCEPT
        SELECT t.d4_filial, t.d4_cod, t.d4_op, t.op_numero, t.d4_local, t.d4_data, t.d4_qtdeori, t.d4_quant, t.d4_trt,
               t.d4_lotectl, t.d4_oporig
    ) THEN UPDATE SET
        d4_filial = s.d4_filial, d4_cod = s.d4_cod, d4_op = s.d4_op, op_numero = s.op_numero, d4_local = s.d4_local,
        d4_data = s.d4_data, d4_qtdeori = s.d4_qtdeori, d4_quant = s.d4_quant, d4_trt = s.d4_trt,
        d4_lotectl = s.d4_lotectl, d4_oporig = s.d4_oporig, d_e_l_e_t_ = ' ', sincronizado_em = @agora
    WHEN NOT MATCHED BY TARGET THEN INSERT (
        d4_filial, d4_cod, d4_op, op_numero, d4_local, d4_data, d4_qtdeori, d4_quant, d4_trt, d4_lotectl, d4_oporig,
        d_e_l_e_t_, r_e_c_n_o_, sincronizado_em
    ) VALUES (
        s.d4_filial, s.d4_cod, s.d4_op, s.op_numero, s.d4_local, s.d4_data, s.d4_qtdeori, s.d4_quant, s.d4_trt,
        s.d4_lotectl, s.d4_oporig, ' ', s.r_e_c_n_o_, @agora
    )
    OUTPUT $action INTO @acoes (acao);

    COMMIT TRANSACTION;

    SELECT ISNULL(@lidos, 0) AS lidos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'INSERT') AS novos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'UPDATE') AS atualizados,
           @excluidos AS excluidos,
           @ignorados AS ignorados,
           @ultimo AS ultimo_recno;
END
