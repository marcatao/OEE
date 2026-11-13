/*
 * Sincronização das ordens de produção: SC2 do Protheus (banco Compras, só LEITURA) → ordens_producao do portal.
 * Instalada pela migração 2026_11_30_000001 (só SQL Server). O PHP (SincronizadorProtheus) apenas a chama, em etapas.
 *
 * Mesma lógica do ImportadorOrdensProducao (PHP), em lote, com MERGE:
 * - lê até @lote linhas da SC2 a partir de @ultimo_recno (ordem de R_E_C_N_O_), filtrando C2_EMISSAO >= @desde;
 * - chave: R_E_C_N_O_ (a linha da SC2). Se o R_E_C_N_O_ ainda não está no portal, procura pela chave do Protheus
 *   (filial + número + item + sequência), que é única no portal: OP excluída e recriada no Protheus gera outro
 *   R_E_C_N_O_ e vale a não excluída mais recente (regra "substitui" do PHP);
 * - grava só o que mudou; nunca apaga: excluída no Protheus fica com d_e_l_e_t_ = '*';
 * - campo que não existe na SC2 (ex.: C2_ROTEIRO) fica em branco e volta em "campos_ausentes", sem falhar;
 * - recalcula o status de TODAS as OPs do lote (StatusOrdemProducao::regra), o custo meta (último fechamento do
 *   produto na SB9, CustoOrdemProducao::atualizarMetas) e tira das máquinas as OPs fechadas ou excluídas
 *   (SituacaoMaquina::desvincularOpsFechadas).
 * Devolve uma linha: lidos, novos, atualizados, excluidos, ignorados, ultimo_recno, terminou (1 = fim da tabela),
 * campos_ausentes. Datas gravadas em UTC.
 */
ALTER PROCEDURE dbo.sp_sincronizar_ordens_producao
    @banco sysname,                 -- banco do Protheus (ex.: Compras)
    @tabela nvarchar(256),          -- SC2 ou dbo.SC2 (config protheus.sincronizacoes.ordens_producao.tabela_origem)
    @tabela_sb9 nvarchar(256),      -- SB9 (config protheus.tabela_sb9)
    @desde varchar(8) = '',         -- AAAAMMDD (PROTHEUS_SC2_DESDE); vazio = todas
    @ultimo_recno int = 0,          -- continua depois deste R_E_C_N_O_
    @lote int = 10000               -- linhas da SC2 por chamada
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @agora datetime = GETUTCDATE();
    DECLARE @sql nvarchar(max);
    DECLARE @sc2 nvarchar(800) = QUOTENAME(@banco) + N'.' + QUOTENAME(ISNULL(PARSENAME(@tabela, 2), N'dbo')) + N'.' + QUOTENAME(PARSENAME(@tabela, 1));
    DECLARE @sb9 nvarchar(800) = QUOTENAME(@banco) + N'.' + QUOTENAME(ISNULL(PARSENAME(@tabela_sb9, 2), N'dbo')) + N'.' + QUOTENAME(PARSENAME(@tabela_sb9, 1));
    SET @ultimo_recno = ISNULL(@ultimo_recno, 0);
    IF @lote IS NULL OR @lote < 50 SET @lote = 50;

    IF PARSENAME(@tabela, 1) IS NULL OR OBJECT_ID(@sc2) IS NULL
    BEGIN
        RAISERROR(N'Invalid object name ''%s''.', 16, 1, @sc2);
        RETURN;
    END;

    -- Campos que existem na SC2 de origem (funciona também se ela for view ou synonym no banco Compras).
    DECLARE @colunas TABLE (nome sysname PRIMARY KEY);
    SET @sql = N'SELECT * FROM ' + @sc2;
    INSERT INTO @colunas (nome)
    SELECT DISTINCT UPPER(name) FROM sys.dm_exec_describe_first_result_set(@sql, NULL, 0) WHERE name IS NOT NULL;

    -- Campo da SC2 → expressão (como o PHP: texto sem espaços à direita e cortado no tamanho da coluna do portal).
    -- padrao NULL = campo obrigatório.
    DECLARE @mapa TABLE (ordem int PRIMARY KEY, campo sysname, coluna sysname, expr nvarchar(400), padrao nvarchar(20));
    INSERT INTO @mapa (ordem, campo, coluna, expr, padrao) VALUES
        (1,  'C2_FILIAL',  'c2_filial',  N'LEFT(RTRIM(C2_FILIAL), 8)', NULL),
        (2,  'C2_NUM',     'c2_num',     N'LEFT(RTRIM(C2_NUM), 10)', NULL),
        (3,  'C2_ITEM',    'c2_item',    N'LEFT(RTRIM(C2_ITEM), 4)', NULL),
        (4,  'C2_SEQUEN',  'c2_sequen',  N'LEFT(RTRIM(C2_SEQUEN), 4)', NULL),
        (5,  'C2_PRODUTO', 'c2_produto', N'LEFT(RTRIM(C2_PRODUTO), 30)', N''''''),
        (6,  'C2_LOCAL',   'c2_local',   N'LEFT(RTRIM(C2_LOCAL), 4)', N''''''),
        (7,  'C2_CC',      'c2_cc',      N'LEFT(RTRIM(C2_CC), 20)', N''''''),
        (8,  'C2_UM',      'c2_um',      N'LEFT(RTRIM(C2_UM), 2)', N''''''),
        (9,  'C2_QUANT',   'c2_quant',   N'ISNULL(C2_QUANT, 0)', N'0'),
        (10, 'C2_QUJE',    'c2_quje',    N'ISNULL(C2_QUJE, 0)', N'0'),
        (11, 'C2_PERDA',   'c2_perda',   N'ISNULL(C2_PERDA, 0)', N'0'),
        (12, 'C2_EMISSAO', 'c2_emissao', N'LEFT(RTRIM(C2_EMISSAO), 8)', N''''''),
        (13, 'C2_DATPRI',  'c2_datpri',  N'LEFT(RTRIM(C2_DATPRI), 8)', N''''''),
        (14, 'C2_DATPRF',  'c2_datprf',  N'LEFT(RTRIM(C2_DATPRF), 8)', N''''''),
        (15, 'C2_DATRF',   'c2_datrf',   N'LEFT(RTRIM(C2_DATRF), 8)', N''''''),
        (16, 'C2_TPOP',    'c2_tpop',    N'LEFT(RTRIM(C2_TPOP), 1)', N''''''),
        (17, 'C2_STATUS',  'c2_status',  N'LEFT(RTRIM(C2_STATUS), 1)', N''''''),
        (18, 'C2_OBS',     'c2_obs',     N'LEFT(RTRIM(C2_OBS), 120)', N''''''),
        (19, 'C2_ROTEIRO', 'c2_roteiro', N'LEFT(RTRIM(C2_ROTEIRO), 2)', N''''''),
        (20, 'C2_REVISAO', 'c2_revisao', N'LEFT(RTRIM(C2_REVISAO), 3)', N''''''),
        (21, 'D_E_L_E_T_', 'd_e_l_e_t_', N'CASE WHEN D_E_L_E_T_ = ''*'' THEN ''*'' ELSE '' '' END', NULL),
        (22, 'R_E_C_N_O_', 'r_e_c_n_o_', N'R_E_C_N_O_', NULL);

    DECLARE @faltando nvarchar(400) = STUFF((SELECT N', ' + m.campo FROM @mapa m
        WHERE m.padrao IS NULL AND NOT EXISTS (SELECT 1 FROM @colunas c WHERE c.nome = m.campo)
        ORDER BY m.ordem FOR XML PATH(''), TYPE).value('.', 'nvarchar(max)'), 1, 2, '');
    IF @faltando IS NOT NULL
    BEGIN
        RAISERROR(N'A tabela %s não tem os campos obrigatórios: %s.', 16, 1, @sc2, @faltando);
        RETURN;
    END;
    DECLARE @ausentes nvarchar(400) = STUFF((SELECT N', ' + m.campo FROM @mapa m
        WHERE NOT EXISTS (SELECT 1 FROM @colunas c WHERE c.nome = m.campo)
        ORDER BY m.ordem FOR XML PATH(''), TYPE).value('.', 'nvarchar(max)'), 1, 2, '');
    DECLARE @alvos nvarchar(max) = STUFF((SELECT N', ' + m.coluna FROM @mapa m ORDER BY m.ordem
        FOR XML PATH(''), TYPE).value('.', 'nvarchar(max)'), 1, 2, '');
    DECLARE @lista nvarchar(max) = STUFF((SELECT N', ' + CASE WHEN c.nome IS NULL THEN m.padrao ELSE m.expr END
        FROM @mapa m LEFT JOIN @colunas c ON c.nome = m.campo ORDER BY m.ordem
        FOR XML PATH(''), TYPE).value('.', 'nvarchar(max)'), 1, 2, '');

    -- 1) Lote da SC2 (só leitura no Protheus).
    CREATE TABLE #src (
        c2_filial nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_num nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_item nvarchar(4) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_sequen nvarchar(4) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_produto nvarchar(30) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_local nvarchar(4) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_cc nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_um nvarchar(2) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_quant float NOT NULL,
        c2_quje float NOT NULL,
        c2_perda float NOT NULL,
        c2_emissao nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_datpri nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_datprf nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_datrf nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_tpop nvarchar(1) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_status nvarchar(1) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_obs nvarchar(120) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_roteiro nvarchar(2) COLLATE DATABASE_DEFAULT NOT NULL,
        c2_revisao nvarchar(3) COLLATE DATABASE_DEFAULT NOT NULL,
        d_e_l_e_t_ nchar(1) COLLATE DATABASE_DEFAULT NOT NULL,
        r_e_c_n_o_ int NOT NULL PRIMARY KEY,
        numero nvarchar(20) COLLATE DATABASE_DEFAULT NULL,
        id bigint NULL,                 -- registro do portal
        atual_recno int NULL,           -- R_E_C_N_O_ gravado nesse registro
        atual_excluida bit NULL
    );

    SET @sql = N'INSERT INTO #src (' + @alvos + N') SELECT TOP (@lote) ' + @lista + N' FROM ' + @sc2
        + N' WHERE R_E_C_N_O_ > @ultimo'
        + CASE WHEN LEN(@desde) = 8 THEN N' AND C2_EMISSAO >= @desde' ELSE N'' END
        + N' ORDER BY R_E_C_N_O_';
    EXEC sp_executesql @sql, N'@lote int, @ultimo int, @desde varchar(8)', @lote = @lote, @ultimo = @ultimo_recno, @desde = @desde;

    DECLARE @lidos int = (SELECT COUNT(*) FROM #src);
    DECLARE @ultimo int = ISNULL((SELECT MAX(r_e_c_n_o_) FROM #src), @ultimo_recno);
    DECLARE @ignorados int = 0, @novos int = 0, @atualizados int = 0, @excluidos int = 0;

    -- Sem número: ignora.
    DELETE FROM #src WHERE c2_num = '';
    SET @ignorados += @@ROWCOUNT;
    UPDATE #src SET numero = LEFT(c2_num + c2_item + c2_sequen, 20);

    -- Uma linha por chave dentro do lote: a não excluída de R_E_C_N_O_ mais recente.
    WITH r AS (
        SELECT ROW_NUMBER() OVER (PARTITION BY c2_filial, c2_num, c2_item, c2_sequen
            ORDER BY CASE WHEN d_e_l_e_t_ = '*' THEN 1 ELSE 0 END, r_e_c_n_o_ DESC) AS rn
        FROM #src
    )
    DELETE FROM r WHERE rn > 1;
    SET @ignorados += @@ROWCOUNT;

    -- 2) Registro do portal de cada linha: pelo R_E_C_N_O_; senão, pela chave do Protheus.
    UPDATE s SET id = t.id, atual_recno = t.r_e_c_n_o_, atual_excluida = CASE WHEN t.d_e_l_e_t_ = '*' THEN 1 ELSE 0 END
    FROM #src s JOIN dbo.ordens_producao t ON t.r_e_c_n_o_ = s.r_e_c_n_o_;

    UPDATE s SET id = t.id, atual_recno = t.r_e_c_n_o_, atual_excluida = CASE WHEN t.d_e_l_e_t_ = '*' THEN 1 ELSE 0 END
    FROM #src s JOIN dbo.ordens_producao t
        ON t.c2_filial = s.c2_filial AND t.c2_num = s.c2_num AND t.c2_item = s.c2_item AND t.c2_sequen = s.c2_sequen
    WHERE s.id IS NULL;

    -- Achou pelo R_E_C_N_O_, mas a chave pertence a outro registro do portal (mudou de número?): não mexe.
    DELETE s FROM #src s
    WHERE s.id IS NOT NULL AND EXISTS (SELECT 1 FROM dbo.ordens_producao t
        WHERE t.c2_filial = s.c2_filial AND t.c2_num = s.c2_num AND t.c2_item = s.c2_item AND t.c2_sequen = s.c2_sequen
          AND t.id <> s.id);
    SET @ignorados += @@ROWCOUNT;

    -- Duas linhas do lote no mesmo registro do portal: fica a do mesmo R_E_C_N_O_ (senão, a mais recente).
    WITH d AS (
        SELECT ROW_NUMBER() OVER (PARTITION BY id
            ORDER BY CASE WHEN atual_recno = r_e_c_n_o_ THEN 0 ELSE 1 END, r_e_c_n_o_ DESC) AS rn
        FROM #src WHERE id IS NOT NULL
    )
    DELETE FROM d WHERE rn > 1;
    SET @ignorados += @@ROWCOUNT;

    -- Versão mais antiga da mesma OP (o portal já tem uma mais recente): não sobrescreve.
    DELETE FROM #src
    WHERE id IS NOT NULL AND ISNULL(atual_recno, 0) <> r_e_c_n_o_
      AND NOT ((atual_excluida = 1 AND d_e_l_e_t_ <> '*') OR r_e_c_n_o_ >= ISNULL(atual_recno, 0));
    SET @ignorados += @@ROWCOUNT;

    -- Excluída no Protheus e nunca veio para o portal.
    DELETE FROM #src WHERE id IS NULL AND d_e_l_e_t_ = '*';
    SET @ignorados += @@ROWCOUNT;

    DECLARE @acoes TABLE (acao nvarchar(10), id bigint, excluida nchar(1));
    DECLARE @ids TABLE (id bigint PRIMARY KEY);
    DECLARE @mudaram TABLE (id bigint);

    BEGIN TRANSACTION;

    -- 3) Grava: novas e alteradas (só as que mudaram algum campo).
    MERGE dbo.ordens_producao WITH (HOLDLOCK) AS t
    USING #src AS s ON t.id = s.id
    WHEN MATCHED AND EXISTS (
        SELECT s.c2_filial, s.c2_num, s.c2_item, s.c2_sequen, s.numero, s.c2_produto, s.c2_local, s.c2_cc, s.c2_um,
               s.c2_quant, s.c2_quje, s.c2_perda, s.c2_emissao, s.c2_datpri, s.c2_datprf, s.c2_datrf, s.c2_tpop,
               s.c2_status, s.c2_obs, s.c2_roteiro, s.c2_revisao, s.d_e_l_e_t_, s.r_e_c_n_o_
        EXCEPT
        SELECT t.c2_filial, t.c2_num, t.c2_item, t.c2_sequen, t.numero, t.c2_produto, t.c2_local, t.c2_cc, t.c2_um,
               t.c2_quant, t.c2_quje, t.c2_perda, t.c2_emissao, t.c2_datpri, t.c2_datprf, t.c2_datrf, t.c2_tpop,
               t.c2_status, t.c2_obs, t.c2_roteiro, t.c2_revisao, t.d_e_l_e_t_, t.r_e_c_n_o_
    ) THEN UPDATE SET
        c2_filial = s.c2_filial, c2_num = s.c2_num, c2_item = s.c2_item, c2_sequen = s.c2_sequen, numero = s.numero,
        c2_produto = s.c2_produto, c2_local = s.c2_local, c2_cc = s.c2_cc, c2_um = s.c2_um, c2_quant = s.c2_quant,
        c2_quje = s.c2_quje, c2_perda = s.c2_perda, c2_emissao = s.c2_emissao, c2_datpri = s.c2_datpri,
        c2_datprf = s.c2_datprf, c2_datrf = s.c2_datrf, c2_tpop = s.c2_tpop, c2_status = s.c2_status, c2_obs = s.c2_obs,
        c2_roteiro = s.c2_roteiro, c2_revisao = s.c2_revisao, d_e_l_e_t_ = s.d_e_l_e_t_, r_e_c_n_o_ = s.r_e_c_n_o_,
        sincronizado_em = @agora
    WHEN NOT MATCHED BY TARGET THEN INSERT (
        c2_filial, c2_num, c2_item, c2_sequen, numero, c2_produto, c2_local, c2_cc, c2_um, c2_quant, c2_quje, c2_perda,
        c2_emissao, c2_datpri, c2_datprf, c2_datrf, c2_tpop, c2_status, c2_obs, c2_roteiro, c2_revisao, d_e_l_e_t_,
        r_e_c_n_o_, status, status_em, sincronizado_em
    ) VALUES (
        s.c2_filial, s.c2_num, s.c2_item, s.c2_sequen, s.numero, s.c2_produto, s.c2_local, s.c2_cc, s.c2_um, s.c2_quant,
        s.c2_quje, s.c2_perda, s.c2_emissao, s.c2_datpri, s.c2_datprf, s.c2_datrf, s.c2_tpop, s.c2_status, s.c2_obs,
        s.c2_roteiro, s.c2_revisao, s.d_e_l_e_t_, s.r_e_c_n_o_,
        -- OP nova ainda não tem apontamentos no portal: o status sai só dos dados do Protheus.
        CASE WHEN s.c2_datrf <> '' THEN 'fechada' WHEN s.c2_status = 'U' THEN 'parada'
             WHEN s.c2_quje > 0 THEN 'andamento' ELSE 'aberta' END,
        @agora, @agora
    )
    OUTPUT $action, inserted.id, inserted.d_e_l_e_t_ INTO @acoes (acao, id, excluida);

    SELECT @novos = SUM(CASE WHEN acao = 'INSERT' THEN 1 ELSE 0 END),
           @atualizados = SUM(CASE WHEN acao = 'UPDATE' AND excluida <> '*' THEN 1 ELSE 0 END),
           @excluidos = SUM(CASE WHEN acao = 'UPDATE' AND excluida = '*' THEN 1 ELSE 0 END)
    FROM @acoes;

    -- Todas as OPs do lote no portal (novas, alteradas e sem mudança).
    INSERT INTO @ids (id) SELECT id FROM #src WHERE id IS NOT NULL UNION SELECT id FROM @acoes;

    -- 4) Status de TODAS as OPs do lote (mesma regra de StatusOrdemProducao::regra).
    WITH emAberto AS (
        SELECT a.ordem_producao_id AS op, l.tipo,
               ROW_NUMBER() OVER (PARTITION BY a.ordem_producao_id ORDER BY a.inicio DESC, a.id DESC) AS rn
        FROM dbo.apontamentos_estado a
        JOIN dbo.legendas l ON l.id = a.legenda_id
        JOIN @ids i ON i.id = a.ordem_producao_id
        WHERE a.fim IS NULL
    ),
    calculo AS (
        SELECT o.id,
               CASE
                   WHEN RTRIM(o.c2_datrf) <> '' THEN 'fechada'
                   WHEN RTRIM(o.c2_status) = 'U' OR (ab.tipo IS NOT NULL AND ab.tipo <> 'produzindo') THEN 'parada'
                   WHEN ab.tipo IS NOT NULL OR o.c2_quje > 0
                        OR EXISTS (SELECT 1 FROM dbo.apontamentos_estado x WHERE x.ordem_producao_id = o.id) THEN 'andamento'
                   ELSE 'aberta'
               END AS novo
        FROM dbo.ordens_producao o
        JOIN @ids i ON i.id = o.id
        LEFT JOIN emAberto ab ON ab.op = o.id AND ab.rn = 1
    )
    UPDATE o SET status = c.novo, status_em = @agora
    OUTPUT inserted.id INTO @mudaram (id)
    FROM dbo.ordens_producao o JOIN calculo c ON c.id = o.id
    WHERE o.status <> c.novo;

    -- 5) Custo meta: custo unitário do fechamento mais recente do produto na SB9 (filial da OP; senão, filial em
    --    branco). Soma de B9_VINI1 ÷ soma de B9_QINI; sem saldo, o maior B9_CM1. Consultas pelo índice da SB9
    --    (B9_FILIAL + B9_COD + B9_DATA).
    IF PARSENAME(@tabela_sb9, 1) IS NOT NULL AND OBJECT_ID(@sb9) IS NOT NULL
    BEGIN
        CREATE TABLE #sb9 (
            filial nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL,
            produto nvarchar(30) COLLATE DATABASE_DEFAULT NOT NULL,
            ultima varchar(8) NULL,
            custo float NULL,
            PRIMARY KEY (filial, produto)
        );
        INSERT INTO #sb9 (filial, produto)
        SELECT DISTINCT f.filial, RTRIM(o.c2_produto)
        FROM dbo.ordens_producao o
        JOIN @ids i ON i.id = o.id
        CROSS APPLY (VALUES (RTRIM(o.c2_filial)), (N'')) f(filial)
        WHERE RTRIM(o.c2_produto) <> '';

        -- A collation do lado do portal é convertida para a da SB9: a comparação continua usando o índice dela.
        DECLARE @colacao sysname;
        SET @sql = N'SELECT B9_COD FROM ' + @sb9;
        SELECT @colacao = collation_name FROM sys.dm_exec_describe_first_result_set(@sql, NULL, 0) WHERE name = 'B9_COD';
        DECLARE @col nvarchar(200) = CASE WHEN @colacao IS NULL THEN N'' ELSE N' COLLATE ' + @colacao END;

        SET @sql = N'
            UPDATE k SET ultima = x.ultima
            FROM #sb9 k
            CROSS APPLY (SELECT MAX(b.B9_DATA) AS ultima FROM ' + @sb9 + N' b
                WHERE b.B9_FILIAL = k.filial' + @col + N' AND b.B9_COD = k.produto' + @col + N' AND b.D_E_L_E_T_ <> ''*'') x;

            UPDATE k SET custo = CASE WHEN x.qtd > 0 THEN x.valor / x.qtd
                                      WHEN x.cm IS NULL OR x.cm < 0 THEN 0 ELSE x.cm END
            FROM #sb9 k
            CROSS APPLY (SELECT SUM(b.B9_QINI) AS qtd, SUM(b.B9_VINI1) AS valor, MAX(b.B9_CM1) AS cm, COUNT(*) AS n
                FROM ' + @sb9 + N' b
                WHERE b.B9_FILIAL = k.filial' + @col + N' AND b.B9_COD = k.produto' + @col + N'
                  AND b.B9_DATA = k.ultima AND b.D_E_L_E_T_ <> ''*'') x
            WHERE k.ultima IS NOT NULL AND x.n > 0;';
        EXEC sp_executesql @sql;

        WITH meta AS (
            SELECT o.id, o.custo_meta, ROUND(COALESCE(kf.custo, kb.custo), 6) AS novo
            FROM dbo.ordens_producao o
            JOIN @ids i ON i.id = o.id
            LEFT JOIN #sb9 kf ON kf.filial = RTRIM(o.c2_filial) AND kf.produto = RTRIM(o.c2_produto)
            LEFT JOIN #sb9 kb ON kb.filial = N'' AND kb.produto = RTRIM(o.c2_produto)
        )
        UPDATE meta SET custo_meta = novo
        OUTPUT inserted.id INTO @mudaram (id)
        WHERE NOT (novo IS NULL AND custo_meta IS NULL)
          AND (novo IS NULL OR custo_meta IS NULL OR ABS(novo - ROUND(custo_meta, 6)) > 0.000001);
    END;

    -- 6) OP fechada ou excluída no Protheus sai da máquina (os apontamentos continuam com ela).
    UPDATE m SET ordem_producao_id = NULL
    FROM dbo.maquinas m
    JOIN dbo.ordens_producao o ON o.id = m.ordem_producao_id
    JOIN @ids i ON i.id = o.id
    WHERE o.status = 'fechada' OR o.d_e_l_e_t_ = '*';

    COMMIT TRANSACTION;

    -- Status ou custo meta que mudaram em OPs sem mudança no Protheus também contam como atualizadas.
    SET @atualizados = ISNULL(@atualizados, 0)
        + (SELECT COUNT(DISTINCT m.id) FROM @mudaram m WHERE NOT EXISTS (SELECT 1 FROM @acoes a WHERE a.id = m.id));

    SELECT @lidos AS lidos, ISNULL(@novos, 0) AS novos, @atualizados AS atualizados, ISNULL(@excluidos, 0) AS excluidos,
           @ignorados AS ignorados, @ultimo AS ultimo_recno, CASE WHEN @lidos < @lote THEN 1 ELSE 0 END AS terminou,
           ISNULL(@ausentes, N'') AS campos_ausentes;
END
