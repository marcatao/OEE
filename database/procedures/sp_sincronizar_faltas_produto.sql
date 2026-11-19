/*
 * Sincronização da falta de produtos, inteira, direto no banco: Compras.dbo.ZB5 (Protheus, só LEITURA) →
 * dbo.faltas_produto (portal). Sem parâmetros: EXEC dbo.sp_sincronizar_faltas_produto
 * Pode ser rodada no SSMS, por um job do SQL Server Agent ou pelo portal (tela Banco de dados / protheus:sincronizar).
 * Instalada pelas migrações (só SQL Server); depois de mudar este arquivo, crie uma migração que chame
 * ProceduresBanco::instalar() ou rode "php artisan banco:procedures".
 *
 * Mesma lógica do ImportadorFaltasProduto (PHP, usado nos testes), com um único MERGE pelo R_E_C_N_O_:
 * - só os campos REAIS da ZB5 (ZB5_DESCPR e ZB5_NOME são virtuais no dicionário);
 * - texto sem espaços à direita; ZB5_DTSOLI + ZB5_HORINI e ZB5_DTATEN + ZB5_HORFIM (horário de Brasília, como o
 *   Protheus grava) viram solicitado_em / atendido_em em UTC; hora inválida ou vazia = 00:00:00;
 * - grava só o que mudou; nunca apaga: excluída no Protheus fica com d_e_l_e_t_ = '*' (e nem entra, se nunca veio).
 * Devolve uma linha: lidos, novos, atualizados, excluidos, ignorados, ultimo_recno.
 */
ALTER PROCEDURE dbo.sp_sincronizar_faltas_produto
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @agora datetime = GETUTCDATE();
    DECLARE @lidos int, @ultimo int, @ignorados int;
    DECLARE @acoes TABLE (acao nvarchar(10), excluida nchar(1));

    SELECT @lidos = COUNT(*), @ultimo = MAX(R_E_C_N_O_) FROM Compras.dbo.ZB5;

    -- 1) Origem tratada como no PHP.
    WITH z AS (
        SELECT
            LEFT(RTRIM(ZB5_FILIAL), 6) COLLATE DATABASE_DEFAULT AS zb5_filial,
            LEFT(RTRIM(ZB5_DOC), 10) COLLATE DATABASE_DEFAULT AS zb5_doc,
            LEFT(RTRIM(ZB5_PRODUT), 16) COLLATE DATABASE_DEFAULT AS zb5_produt,
            CAST(ISNULL(ZB5_QTDSOL, 0) AS float) AS zb5_qtdsol,
            CAST(ISNULL(ZB5_QTDATE, 0) AS float) AS zb5_qtdate,
            LEFT(RTRIM(ZB5_PEDIDO), 6) COLLATE DATABASE_DEFAULT AS zb5_pedido,
            CAST(ISNULL(ZB5_VLPED, 0) AS float) AS zb5_vlped,
            LEFT(RTRIM(ZB5_SOLICI), 19) COLLATE DATABASE_DEFAULT AS zb5_solici,
            LEFT(RTRIM(ZB5_DTSOLI), 8) COLLATE DATABASE_DEFAULT AS zb5_dtsoli,
            LEFT(RTRIM(ZB5_HORINI), 8) COLLATE DATABASE_DEFAULT AS zb5_horini,
            LEFT(RTRIM(ZB5_DTATEN), 8) COLLATE DATABASE_DEFAULT AS zb5_dtaten,
            LEFT(RTRIM(ZB5_HORFIM), 8) COLLATE DATABASE_DEFAULT AS zb5_horfim,
            LEFT(RTRIM(ZB5_ATENDI), 19) COLLATE DATABASE_DEFAULT AS zb5_atendi,
            CAST(LEFT(RTRIM(ZB5_STATUS), 1) AS nchar(1)) COLLATE DATABASE_DEFAULT AS zb5_status,
            CAST(CASE WHEN D_E_L_E_T_ = '*' THEN '*' ELSE ' ' END AS nchar(1)) COLLATE DATABASE_DEFAULT AS d_e_l_e_t_,
            R_E_C_N_O_ AS r_e_c_n_o_
        FROM Compras.dbo.ZB5
    )
    SELECT z.*,
        -- Data + hora local (Brasília) → UTC. Hora "HH:MM" ganha ":00"; inválida ou vazia = meia-noite.
        CAST(CAST(TRY_CONVERT(datetime, z.zb5_dtsoli + ' ' + CASE
                WHEN z.zb5_horini LIKE '[0-2][0-9]:[0-5][0-9]:[0-5][0-9]' THEN z.zb5_horini
                WHEN z.zb5_horini LIKE '[0-2][0-9]:[0-5][0-9]' THEN z.zb5_horini + ':00'
                ELSE '00:00:00' END) AS datetime2(0))
            AT TIME ZONE 'E. South America Standard Time' AT TIME ZONE 'UTC' AS datetime) AS solicitado_em,
        CAST(CAST(TRY_CONVERT(datetime, z.zb5_dtaten + ' ' + CASE
                WHEN z.zb5_horfim LIKE '[0-2][0-9]:[0-5][0-9]:[0-5][0-9]' THEN z.zb5_horfim
                WHEN z.zb5_horfim LIKE '[0-2][0-9]:[0-5][0-9]' THEN z.zb5_horfim + ':00'
                ELSE '00:00:00' END) AS datetime2(0))
            AT TIME ZONE 'E. South America Standard Time' AT TIME ZONE 'UTC' AS datetime) AS atendido_em
    INTO #zb5
    FROM z;

    -- Data vazia ou inválida: sem data (TRY_CONVERT devolve NULL, mas "        " + hora pode virar 1900-01-01).
    UPDATE #zb5 SET solicitado_em = NULL WHERE zb5_dtsoli NOT LIKE '[1-2][0-9][0-9][0-9][0-1][0-9][0-3][0-9]';
    UPDATE #zb5 SET atendido_em = NULL WHERE zb5_dtaten NOT LIKE '[1-2][0-9][0-9][0-9][0-1][0-9][0-3][0-9]';

    -- Excluída no Protheus e nunca veio para o portal.
    DELETE s FROM #zb5 s
    WHERE s.d_e_l_e_t_ = '*' AND NOT EXISTS (SELECT 1 FROM dbo.faltas_produto t WHERE t.r_e_c_n_o_ = s.r_e_c_n_o_);
    SET @ignorados = @@ROWCOUNT;

    BEGIN TRANSACTION;

    -- 2) MERGE pelo R_E_C_N_O_: novas e alteradas (só as que mudaram algum campo).
    MERGE dbo.faltas_produto WITH (HOLDLOCK) AS t
    USING #zb5 AS s ON t.r_e_c_n_o_ = s.r_e_c_n_o_
    WHEN MATCHED AND EXISTS (
        SELECT s.zb5_filial, s.zb5_doc, s.zb5_produt, s.zb5_qtdsol, s.zb5_qtdate, s.zb5_pedido, s.zb5_vlped, s.zb5_solici,
               s.zb5_dtsoli, s.zb5_horini, s.zb5_dtaten, s.zb5_horfim, s.zb5_atendi, s.zb5_status, s.d_e_l_e_t_,
               s.solicitado_em, s.atendido_em
        EXCEPT
        SELECT t.zb5_filial, t.zb5_doc, t.zb5_produt, t.zb5_qtdsol, t.zb5_qtdate, t.zb5_pedido, t.zb5_vlped, t.zb5_solici,
               t.zb5_dtsoli, t.zb5_horini, t.zb5_dtaten, t.zb5_horfim, t.zb5_atendi, t.zb5_status, t.d_e_l_e_t_,
               t.solicitado_em, t.atendido_em
    ) THEN UPDATE SET
        zb5_filial = s.zb5_filial, zb5_doc = s.zb5_doc, zb5_produt = s.zb5_produt, zb5_qtdsol = s.zb5_qtdsol,
        zb5_qtdate = s.zb5_qtdate, zb5_pedido = s.zb5_pedido, zb5_vlped = s.zb5_vlped, zb5_solici = s.zb5_solici,
        zb5_dtsoli = s.zb5_dtsoli, zb5_horini = s.zb5_horini, zb5_dtaten = s.zb5_dtaten, zb5_horfim = s.zb5_horfim,
        zb5_atendi = s.zb5_atendi, zb5_status = s.zb5_status, d_e_l_e_t_ = s.d_e_l_e_t_,
        solicitado_em = s.solicitado_em, atendido_em = s.atendido_em, sincronizado_em = @agora
    WHEN NOT MATCHED BY TARGET THEN INSERT (
        zb5_filial, zb5_doc, zb5_produt, zb5_qtdsol, zb5_qtdate, zb5_pedido, zb5_vlped, zb5_solici, zb5_dtsoli,
        zb5_horini, zb5_dtaten, zb5_horfim, zb5_atendi, zb5_status, d_e_l_e_t_, r_e_c_n_o_, solicitado_em, atendido_em,
        sincronizado_em
    ) VALUES (
        s.zb5_filial, s.zb5_doc, s.zb5_produt, s.zb5_qtdsol, s.zb5_qtdate, s.zb5_pedido, s.zb5_vlped, s.zb5_solici,
        s.zb5_dtsoli, s.zb5_horini, s.zb5_dtaten, s.zb5_horfim, s.zb5_atendi, s.zb5_status, s.d_e_l_e_t_, s.r_e_c_n_o_,
        s.solicitado_em, s.atendido_em, @agora
    )
    OUTPUT $action, inserted.d_e_l_e_t_ INTO @acoes (acao, excluida);

    COMMIT TRANSACTION;

    SELECT ISNULL(@lidos, 0) AS lidos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'INSERT') AS novos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'UPDATE' AND excluida <> '*') AS atualizados,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'UPDATE' AND excluida = '*') AS excluidos,
           @ignorados AS ignorados,
           @ultimo AS ultimo_recno;
END
