/*
 * Sincronização das ordens de produção, inteira, direto no banco: Compras.dbo.SC2 (Protheus, só LEITURA) →
 * dbo.ordens_producao (portal). Sem parâmetros: EXEC dbo.sp_sincronizar_ordens_producao
 * Pode ser rodada no SSMS, por um job do SQL Server Agent ou pelo portal (tela Banco de dados / protheus:sincronizar).
 * Instalada pelas migrações (só SQL Server); depois de mudar este arquivo, rode "php artisan banco:procedures".
 *
 * Mesma lógica do ImportadorOrdensProducao (PHP), com um único MERGE:
 * - origem: a SC2 inteira, uma linha por OP (filial + número + item + sequência): a não excluída de R_E_C_N_O_ mais
 *   recente (OP excluída e recriada no Protheus deixa duas linhas);
 * - chave: R_E_C_N_O_. OP do portal ainda sem esse R_E_C_N_O_ (gravada antes, ou recriada no Protheus) é achada pela
 *   chave do Protheus, que é única no portal;
 * - grava só o que mudou; nunca apaga: excluída no Protheus fica com d_e_l_e_t_ = '*' (e nem entra, se nunca veio);
 * - recalcula o status de TODAS as OPs (StatusOrdemProducao::regra), o custo meta (último fechamento do produto na
 *   SB9, CustoOrdemProducao) e tira das máquinas as OPs fechadas ou excluídas (SituacaoMaquina).
 * Devolve uma linha: lidos, novos, atualizados, excluidos, ignorados, ultimo_recno. Datas em UTC.
 */
ALTER PROCEDURE dbo.sp_sincronizar_ordens_producao
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @agora datetime = GETUTCDATE();
    DECLARE @lidos int, @ultimo int, @ignorados int;
    DECLARE @acoes TABLE (acao nvarchar(10), id bigint, excluida nchar(1));
    DECLARE @mudaram TABLE (id bigint);

    SELECT @lidos = COUNT(*), @ultimo = MAX(R_E_C_N_O_) FROM Compras.dbo.SC2;

    -- 1) Origem: SC2 tratada como no PHP (texto sem espaços à direita, no tamanho da coluna do portal).
    SELECT
        LEFT(RTRIM(C2_FILIAL), 8) COLLATE DATABASE_DEFAULT AS c2_filial,
        LEFT(RTRIM(C2_NUM), 10) COLLATE DATABASE_DEFAULT AS c2_num,
        LEFT(RTRIM(C2_ITEM), 4) COLLATE DATABASE_DEFAULT AS c2_item,
        LEFT(RTRIM(C2_SEQUEN), 4) COLLATE DATABASE_DEFAULT AS c2_sequen,
        LEFT(RTRIM(C2_NUM) + RTRIM(C2_ITEM) + RTRIM(C2_SEQUEN), 20) COLLATE DATABASE_DEFAULT AS numero,
        LEFT(RTRIM(C2_PRODUTO), 30) COLLATE DATABASE_DEFAULT AS c2_produto,
        LEFT(RTRIM(C2_LOCAL), 4) COLLATE DATABASE_DEFAULT AS c2_local,
        LEFT(RTRIM(C2_CC), 20) COLLATE DATABASE_DEFAULT AS c2_cc,
        LEFT(RTRIM(C2_UM), 2) COLLATE DATABASE_DEFAULT AS c2_um,
        CAST(ISNULL(C2_QUANT, 0) AS float) AS c2_quant,
        CAST(ISNULL(C2_QUJE, 0) AS float) AS c2_quje,
        CAST(ISNULL(C2_PERDA, 0) AS float) AS c2_perda,
        LEFT(RTRIM(C2_EMISSAO), 8) COLLATE DATABASE_DEFAULT AS c2_emissao,
        LEFT(RTRIM(C2_DATPRI), 8) COLLATE DATABASE_DEFAULT AS c2_datpri,
        LEFT(RTRIM(C2_DATPRF), 8) COLLATE DATABASE_DEFAULT AS c2_datprf,
        LEFT(RTRIM(C2_DATRF), 8) COLLATE DATABASE_DEFAULT AS c2_datrf,
        LEFT(RTRIM(C2_TPOP), 1) COLLATE DATABASE_DEFAULT AS c2_tpop,
        LEFT(RTRIM(C2_STATUS), 1) COLLATE DATABASE_DEFAULT AS c2_status,
        LEFT(RTRIM(C2_OBS), 120) COLLATE DATABASE_DEFAULT AS c2_obs,
        LEFT(RTRIM(C2_ROTEIRO), 2) COLLATE DATABASE_DEFAULT AS c2_roteiro,
        LEFT(RTRIM(C2_REVISAO), 3) COLLATE DATABASE_DEFAULT AS c2_revisao,
        CAST(CASE WHEN D_E_L_E_T_ = '*' THEN '*' ELSE ' ' END AS nchar(1)) COLLATE DATABASE_DEFAULT AS d_e_l_e_t_,
        R_E_C_N_O_ AS r_e_c_n_o_,
        CAST(NULL AS bigint) AS id,               -- registro do portal
        CAST(NULL AS int) AS atual_recno          -- R_E_C_N_O_ gravado nesse registro
    INTO #sc2
    FROM Compras.dbo.SC2
    WHERE RTRIM(C2_NUM) <> '';

    SET @ignorados = @lidos - @@ROWCOUNT; -- sem número

    -- Uma linha por OP: a não excluída de R_E_C_N_O_ mais recente.
    WITH r AS (
        SELECT ROW_NUMBER() OVER (PARTITION BY c2_filial, c2_num, c2_item, c2_sequen
            ORDER BY CASE WHEN d_e_l_e_t_ = '*' THEN 1 ELSE 0 END, r_e_c_n_o_ DESC) AS rn
        FROM #sc2
    )
    DELETE FROM r WHERE rn > 1;
    SET @ignorados += @@ROWCOUNT;

    -- 2) Registro do portal de cada OP: pelo R_E_C_N_O_; senão, pela chave do Protheus.
    CREATE INDEX ix_sc2_recno ON #sc2 (r_e_c_n_o_);
    UPDATE s SET id = t.id, atual_recno = t.r_e_c_n_o_
    FROM #sc2 s JOIN dbo.ordens_producao t ON t.r_e_c_n_o_ = s.r_e_c_n_o_;
    UPDATE s SET id = t.id, atual_recno = t.r_e_c_n_o_
    FROM #sc2 s JOIN dbo.ordens_producao t
        ON t.c2_filial = s.c2_filial AND t.c2_num = s.c2_num AND t.c2_item = s.c2_item AND t.c2_sequen = s.c2_sequen
    WHERE s.id IS NULL;

    -- O R_E_C_N_O_ aponta para um registro, mas a chave já é de outro (mudou de número?): não mexe.
    DELETE s FROM #sc2 s
    WHERE EXISTS (SELECT 1 FROM dbo.ordens_producao t
        WHERE t.c2_filial = s.c2_filial AND t.c2_num = s.c2_num AND t.c2_item = s.c2_item AND t.c2_sequen = s.c2_sequen
          AND t.id <> s.id);
    SET @ignorados += @@ROWCOUNT;

    -- Duas OPs da origem no mesmo registro do portal: fica a do mesmo R_E_C_N_O_ (senão, a mais recente).
    WITH d AS (
        SELECT ROW_NUMBER() OVER (PARTITION BY id
            ORDER BY CASE WHEN atual_recno = r_e_c_n_o_ THEN 0 ELSE 1 END, r_e_c_n_o_ DESC) AS rn
        FROM #sc2 WHERE id IS NOT NULL
    )
    DELETE FROM d WHERE rn > 1;
    SET @ignorados += @@ROWCOUNT;

    -- Excluída no Protheus e nunca veio para o portal.
    DELETE FROM #sc2 WHERE id IS NULL AND d_e_l_e_t_ = '*';
    SET @ignorados += @@ROWCOUNT;

    BEGIN TRANSACTION;

    -- 3) MERGE: novas e alteradas (só as que mudaram algum campo).
    MERGE dbo.ordens_producao WITH (HOLDLOCK) AS t
    USING #sc2 AS s ON t.id = s.id
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

    -- 4) Status de TODAS as OPs (mesma regra de StatusOrdemProducao::regra).
    WITH emAberto AS (
        SELECT a.ordem_producao_id AS op, l.tipo,
               ROW_NUMBER() OVER (PARTITION BY a.ordem_producao_id ORDER BY a.inicio DESC, a.id DESC) AS rn
        FROM dbo.apontamentos_estado a
        JOIN dbo.legendas l ON l.id = a.legenda_id
        WHERE a.fim IS NULL AND a.ordem_producao_id IS NOT NULL
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
        LEFT JOIN emAberto ab ON ab.op = o.id AND ab.rn = 1
    )
    UPDATE o SET status = c.novo, status_em = @agora
    OUTPUT inserted.id INTO @mudaram (id)
    FROM dbo.ordens_producao o JOIN calculo c ON c.id = o.id
    WHERE o.status <> c.novo;

    -- 5) Custo meta: custo unitário do fechamento mais recente do produto na SB9 (filial da OP; senão, filial em
    --    branco). Soma de B9_VINI1 ÷ soma de B9_QINI; sem saldo, o maior B9_CM1.
    WITH ultimo AS (
        SELECT B9_FILIAL, B9_COD, MAX(B9_DATA) AS B9_DATA
        FROM Compras.dbo.SB9
        WHERE D_E_L_E_T_ <> '*'
        GROUP BY B9_FILIAL, B9_COD
    ),
    fechamento AS (
        SELECT RTRIM(b.B9_FILIAL) COLLATE DATABASE_DEFAULT AS filial, RTRIM(b.B9_COD) COLLATE DATABASE_DEFAULT AS produto,
               CASE WHEN SUM(b.B9_QINI) > 0 THEN SUM(b.B9_VINI1) / SUM(b.B9_QINI)
                    WHEN MAX(b.B9_CM1) > 0 THEN MAX(b.B9_CM1) ELSE 0 END AS custo
        FROM Compras.dbo.SB9 b
        JOIN ultimo u ON u.B9_FILIAL = b.B9_FILIAL AND u.B9_COD = b.B9_COD AND u.B9_DATA = b.B9_DATA
        WHERE b.D_E_L_E_T_ <> '*'
        GROUP BY b.B9_FILIAL, b.B9_COD
    )
    SELECT filial, produto, custo INTO #sb9 FROM fechamento;
    CREATE INDEX ix_sb9 ON #sb9 (produto, filial);

    WITH meta AS (
        SELECT o.id, o.custo_meta, ROUND(COALESCE(kf.custo, kb.custo), 6) AS novo
        FROM dbo.ordens_producao o
        LEFT JOIN #sb9 kf ON kf.filial = RTRIM(o.c2_filial) AND kf.produto = RTRIM(o.c2_produto)
        LEFT JOIN #sb9 kb ON kb.filial = N'' AND kb.produto = RTRIM(o.c2_produto)
        WHERE RTRIM(o.c2_produto) <> ''
    )
    UPDATE meta SET custo_meta = novo
    OUTPUT inserted.id INTO @mudaram (id)
    WHERE NOT (novo IS NULL AND custo_meta IS NULL)
      AND (novo IS NULL OR custo_meta IS NULL OR ABS(novo - ROUND(custo_meta, 6)) > 0.000001);

    -- 6) OP fechada ou excluída no Protheus sai da máquina (os apontamentos continuam com ela).
    UPDATE m SET ordem_producao_id = NULL
    FROM dbo.maquinas m
    JOIN dbo.ordens_producao o ON o.id = m.ordem_producao_id
    WHERE o.status = 'fechada' OR o.d_e_l_e_t_ = '*';

    COMMIT TRANSACTION;

    -- Resumo. Status ou custo meta que mudaram em OPs sem mudança no Protheus contam como atualizadas.
    SELECT ISNULL(@lidos, 0) AS lidos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'INSERT') AS novos,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'UPDATE' AND excluida <> '*')
             + (SELECT COUNT(DISTINCT m.id) FROM @mudaram m WHERE NOT EXISTS (SELECT 1 FROM @acoes a WHERE a.id = m.id)) AS atualizados,
           (SELECT COUNT(*) FROM @acoes WHERE acao = 'UPDATE' AND excluida = '*') AS excluidos,
           @ignorados AS ignorados,
           @ultimo AS ultimo_recno;
END
