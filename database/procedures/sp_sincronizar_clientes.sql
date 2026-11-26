/*
 * Sincronização dos clientes: SA1010 do Protheus, no servidor vinculado [ALUMBRAPRD].[CMT8F4_145045_PR_PD] (só
 * LEITURA) → dbo.clientes (portal). Sem parâmetros: EXEC dbo.sp_sincronizar_clientes
 * Pode ser rodada no SSMS, por um job do SQL Server Agent ou pelo portal (tela Banco de dados / protheus:sincronizar /
 * periodicidade). Instalada pelas migrações (só SQL Server); depois de mudar este arquivo, crie uma migração que chame
 * ProceduresBanco::instalar() ou rode "php artisan banco:procedures".
 *
 * - Lê a SA1010 INTEIRA não excluída por OPENQUERY (só as colunas usadas vêm pela rede);
 * - MERGE pelo R_E_C_N_O_: inclui os novos e altera só os que mudaram algum campo;
 * - APAGA do portal os clientes que não vieram (excluídos no Protheus ou apagados de vez), soltando antes os pedidos
 *   ligados a eles (pedidos_venda.cliente_id = NULL);
 * - LIGA os pedidos de venda ao cliente (pedidos_venda.cliente_id) pelo C5_CLIENTE + C5_LOJACLI, na filial do pedido
 *   ou compartilhada (A1_FILIAL em branco) — só grava os pedidos cujo vínculo mudou. A procedure dos pedidos
 *   (sp_sincronizar_pedidos_venda) faz o mesmo nos pedidos da janela dela, para o pedido novo já nascer ligado.
 * Por segurança, leitura vazia com clientes no portal = erro (nada é apagado).
 * Devolve uma linha: lidos, novos, atualizados, excluidos, ignorados, ultimo_recno, pedidos_ligados.
 */
ALTER PROCEDURE dbo.sp_sincronizar_clientes
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @agora datetime = GETUTCDATE();
    DECLARE @sql nvarchar(max), @remota nvarchar(max);
    DECLARE @lidos int, @ultimo int, @excluidos int = 0, @ligados int = 0;
    DECLARE @acoes TABLE (acao nvarchar(10));

    IF NOT EXISTS (SELECT 1 FROM sys.servers WHERE name = N'ALUMBRAPRD')
    BEGIN
        RAISERROR(N'O servidor vinculado ALUMBRAPRD não existe neste SQL Server (sys.servers). Peça ao TI para criá-lo, com acesso de leitura ao banco CMT8F4_145045_PR_PD.', 16, 1);
        RETURN;
    END;

    CREATE TABLE #sa1 (
        a1_filial nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_cod nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_loja nvarchar(4) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_nome nvarchar(60) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_nreduz nvarchar(40) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_pessoa nvarchar(1) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_tipo nvarchar(1) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_cgc nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_inscr nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_end nvarchar(80) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_bairro nvarchar(40) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_mun nvarchar(60) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_est nvarchar(2) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_cep nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_ddd nvarchar(5) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_tel nvarchar(30) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_email nvarchar(255) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_vend nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        a1_msblql nvarchar(1) COLLATE DATABASE_DEFAULT NOT NULL,
        r_e_c_n_o_ int NOT NULL PRIMARY KEY
    );

    -- 1) SA1010 não excluída. SQL dinâmico: a procedure instala mesmo onde o servidor vinculado não existe.
    SET @remota = N'SELECT A1_FILIAL, A1_COD, A1_LOJA, A1_NOME, A1_NREDUZ, A1_PESSOA, A1_TIPO, A1_CGC, A1_INSCR, A1_END, '
        + N'A1_BAIRRO, A1_MUN, A1_EST, A1_CEP, A1_DDD, A1_TEL, A1_EMAIL, A1_VEND, A1_MSBLQL, R_E_C_N_O_ '
        + N'FROM CMT8F4_145045_PR_PD.dbo.SA1010 WHERE D_E_L_E_T_ = '' ''';
    SET @sql = N'INSERT INTO #sa1 (a1_filial, a1_cod, a1_loja, a1_nome, a1_nreduz, a1_pessoa, a1_tipo, a1_cgc, a1_inscr, '
        + N'a1_end, a1_bairro, a1_mun, a1_est, a1_cep, a1_ddd, a1_tel, a1_email, a1_vend, a1_msblql, r_e_c_n_o_) '
        + N'SELECT LEFT(RTRIM(A1_FILIAL), 8), LEFT(RTRIM(A1_COD), 10), LEFT(RTRIM(A1_LOJA), 4), LEFT(RTRIM(A1_NOME), 60), '
        + N'LEFT(RTRIM(A1_NREDUZ), 40), LEFT(RTRIM(A1_PESSOA), 1), LEFT(RTRIM(A1_TIPO), 1), LEFT(RTRIM(A1_CGC), 20), '
        + N'LEFT(RTRIM(A1_INSCR), 20), LEFT(RTRIM(A1_END), 80), LEFT(RTRIM(A1_BAIRRO), 40), LEFT(RTRIM(A1_MUN), 60), '
        + N'LEFT(RTRIM(A1_EST), 2), LEFT(RTRIM(A1_CEP), 10), LEFT(RTRIM(A1_DDD), 5), LEFT(RTRIM(A1_TEL), 30), '
        + N'LEFT(RTRIM(A1_EMAIL), 255), LEFT(RTRIM(A1_VEND), 10), LEFT(RTRIM(A1_MSBLQL), 1), R_E_C_N_O_ '
        + N'FROM OPENQUERY(ALUMBRAPRD, ''' + REPLACE(@remota, N'''', N'''''') + N''')';
    EXEC sp_executesql @sql;

    SELECT @lidos = COUNT(*), @ultimo = MAX(r_e_c_n_o_) FROM #sa1;

    -- Leitura vazia com clientes no portal: algo errado no servidor vinculado; não apaga nada.
    IF @lidos = 0 AND EXISTS (SELECT 1 FROM dbo.clientes)
    BEGIN
        RAISERROR(N'A leitura da SA1010 no ALUMBRAPRD não trouxe nenhum cliente, mas o portal tem clientes: nada foi apagado. Confira o acesso ao servidor vinculado.', 16, 1);
        RETURN;
    END;

    BEGIN TRANSACTION;

    -- 2) Clientes que não vieram (excluídos no Protheus ou apagados de vez): solta os pedidos e apaga do portal.
    UPDATE p SET cliente_id = NULL
    FROM dbo.pedidos_venda p
    JOIN dbo.clientes c ON c.id = p.cliente_id
    WHERE NOT EXISTS (SELECT 1 FROM #sa1 s WHERE s.r_e_c_n_o_ = c.r_e_c_n_o_);

    DELETE c FROM dbo.clientes c
    WHERE NOT EXISTS (SELECT 1 FROM #sa1 s WHERE s.r_e_c_n_o_ = c.r_e_c_n_o_);
    SET @excluidos = @@ROWCOUNT;

    -- 3) MERGE pelo R_E_C_N_O_: novos e alterados (só os que mudaram algum campo).
    MERGE dbo.clientes WITH (HOLDLOCK) AS t
    USING #sa1 AS s ON t.r_e_c_n_o_ = s.r_e_c_n_o_
    WHEN MATCHED AND EXISTS (
        SELECT s.a1_filial, s.a1_cod, s.a1_loja, s.a1_nome, s.a1_nreduz, s.a1_pessoa, s.a1_tipo, s.a1_cgc, s.a1_inscr,
               s.a1_end, s.a1_bairro, s.a1_mun, s.a1_est, s.a1_cep, s.a1_ddd, s.a1_tel, s.a1_email, s.a1_vend, s.a1_msblql
        EXCEPT
        SELECT t.a1_filial, t.a1_cod, t.a1_loja, t.a1_nome, t.a1_nreduz, t.a1_pessoa, t.a1_tipo, t.a1_cgc, t.a1_inscr,
               t.a1_end, t.a1_bairro, t.a1_mun, t.a1_est, t.a1_cep, t.a1_ddd, t.a1_tel, t.a1_email, t.a1_vend, t.a1_msblql
    ) THEN UPDATE SET
        a1_filial = s.a1_filial, a1_cod = s.a1_cod, a1_loja = s.a1_loja, a1_nome = s.a1_nome, a1_nreduz = s.a1_nreduz,
        a1_pessoa = s.a1_pessoa, a1_tipo = s.a1_tipo, a1_cgc = s.a1_cgc, a1_inscr = s.a1_inscr, a1_end = s.a1_end,
        a1_bairro = s.a1_bairro, a1_mun = s.a1_mun, a1_est = s.a1_est, a1_cep = s.a1_cep, a1_ddd = s.a1_ddd,
        a1_tel = s.a1_tel, a1_email = s.a1_email, a1_vend = s.a1_vend, a1_msblql = s.a1_msblql, sincronizado_em = @agora
    WHEN NOT MATCHED BY TARGET THEN INSERT (
        a1_filial, a1_cod, a1_loja, a1_nome, a1_nreduz, a1_pessoa, a1_tipo, a1_cgc, a1_inscr, a1_end, a1_bairro, a1_mun,
        a1_est, a1_cep, a1_ddd, a1_tel, a1_email, a1_vend, a1_msblql, r_e_c_n_o_, sincronizado_em
    ) VALUES (
        s.a1_filial, s.a1_cod, s.a1_loja, s.a1_nome, s.a1_nreduz, s.a1_pessoa, s.a1_tipo, s.a1_cgc, s.a1_inscr, s.a1_end,
        s.a1_bairro, s.a1_mun, s.a1_est, s.a1_cep, s.a1_ddd, s.a1_tel, s.a1_email, s.a1_vend, s.a1_msblql, s.r_e_c_n_o_, @agora
    )
    OUTPUT $action INTO @acoes (acao);

    -- 4) Liga os pedidos de venda ao cliente (filial do pedido ou compartilhada), só os que mudaram.
    WITH vinculo AS (
        SELECT p.id, p.cliente_id, c.id AS novo
        FROM dbo.pedidos_venda p
        OUTER APPLY (
            SELECT TOP 1 c.id FROM dbo.clientes c
            WHERE c.a1_cod = p.c5_cliente AND c.a1_loja = p.c5_lojacli AND (c.a1_filial = p.c5_filial OR c.a1_filial = '')
            ORDER BY c.a1_filial DESC
        ) c
    )
    UPDATE v SET cliente_id = novo FROM vinculo v
    WHERE ISNULL(v.cliente_id, 0) <> ISNULL(v.novo, 0);
    SET @ligados = @@ROWCOUNT;

    COMMIT TRANSACTION;

    SELECT ISNULL(@lidos, 0) AS lidos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'INSERT') AS novos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'UPDATE') AS atualizados,
           @excluidos AS excluidos,
           0 AS ignorados,
           @ultimo AS ultimo_recno,
           @ligados AS pedidos_ligados;
END
