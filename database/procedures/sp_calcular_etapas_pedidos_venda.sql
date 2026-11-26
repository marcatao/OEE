/*
 * Etapas dos pedidos de venda (Faturamento › Pedidos de venda): calcula, em SQL e de uma vez, a etapa de TODOS os pedidos
 * de dbo.pedidos_venda (SC5 + SC6, um item por linha) com dbo.pedidos_venda_liberacoes (SC9) e as regras ativas de
 * dbo.regras_etapa_pedido, e grava em dbo.pedidos_venda_etapas (uma linha por pedido; MERGE, só o que mudou).
 * Só lê tabelas do portal. Chamada no fim de dbo.sp_sincronizar_pedidos_venda e pela tela Etapas dos pedidos ao salvar
 * uma regra (o portal só faz EXEC). Pode ser rodada no SSMS: EXEC dbo.sp_calcular_etapas_pedidos_venda
 * Instalada pelas migrações (só SQL Server); depois de mudar este arquivo, crie uma migração que chame
 * ProceduresBanco::instalar() ou rode "php artisan banco:procedures".
 *
 * MESMA regra de App\Services\Faturamento\EtapasPedidoVenda (o PHP só roda nos testes em SQLite): mudou aqui, mude lá.
 * - Liberação em aberto (sem nota): bloqueada (C9_BLCRED/C9_BLEST fora de vazio e "10") = carteira; senão a etapa mais
 *   adiantada entre as regras que valem (campo lido do JSON "extras"); nenhuma = em expedição.
 * - Item com saldo (vendido > entregue e não eliminado por resíduo): a liberação mais atrasada; saldo sem liberação
 *   suficiente = carteira. Pedido = o item mais atrasado; sem saldo = faturado (se entregou algo) ou encerrado (NULL).
 * - etapa_desde (UTC): faturado = C6_DATFAT; expedição = última C9_DATALIB; mesma etapa = mantém; primeiro cálculo =
 *   emissão (carteira) ou liberação; mudou = agora. Datas do Protheus no horário de Brasília.
 * Parâmetros opcionais: @devolver = 0 não devolve linha (uso dentro de outra procedure); @alterados OUTPUT = quantos
 * pedidos mudaram. Com @devolver = 1 (padrão) devolve uma linha: alterados.
 */
ALTER PROCEDURE dbo.sp_calcular_etapas_pedidos_venda
    @devolver bit = 1,
    @alterados int = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @agora datetime2(0) = SYSUTCDATETIME();
    DECLARE @fuso sysname = N'E. South America Standard Time'; -- America/Sao_Paulo
    DECLARE @acoes TABLE (acao nvarchar(10));

    -- 1) Regras ativas (ordem da etapa no processo) e os valores de cada uma, já separados e em maiúsculas.
    CREATE TABLE #regras (id int PRIMARY KEY, ordem int NOT NULL, campo nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        operador nvarchar(12) COLLATE DATABASE_DEFAULT NOT NULL);
    INSERT INTO #regras (id, ordem, campo, operador)
    SELECT r.id,
           CASE r.etapa WHEN 'separacao' THEN 2 WHEN 'conferencia' THEN 3 WHEN 'logistica' THEN 4 ELSE 5 END,
           UPPER(LTRIM(RTRIM(r.campo))), r.operador
    FROM dbo.regras_etapa_pedido r
    WHERE r.ativo = 1 AND r.etapa IN ('separacao', 'conferencia', 'logistica', 'aguardando_faturamento')
      AND UPPER(LTRIM(RTRIM(r.campo))) LIKE N'C9[_]%' AND UPPER(LTRIM(RTRIM(r.campo))) NOT LIKE N'%[^A-Za-z0-9_]%';

    CREATE TABLE #valores (regra_id int NOT NULL, valor nvarchar(200) COLLATE DATABASE_DEFAULT NOT NULL);
    INSERT INTO #valores (regra_id, valor)
    SELECT r.id, UPPER(LTRIM(RTRIM(x.v.value('.', 'nvarchar(200)'))))
    FROM dbo.regras_etapa_pedido r
    JOIN #regras g ON g.id = r.id
    CROSS APPLY (SELECT CAST(N'<v>' + REPLACE(REPLACE(REPLACE(REPLACE(r.valores, N'&', N'&amp;'), N'<', N'&lt;'), N'>', N'&gt;'), N',', N'</v><v>')
        + N'</v>' AS xml) AS lista) l
    CROSS APPLY l.lista.nodes('/v') x (v)
    WHERE LTRIM(RTRIM(x.v.value('.', 'nvarchar(200)'))) <> N'';

    -- 2) Etapa de cada liberação em aberto (0 carteira, 1 expedição, 2 separação, 3 conferência, 4 logística,
    --    5 aguardando faturamento).
    CREATE TABLE #lib (c9_filial nvarchar(8) COLLATE DATABASE_DEFAULT NOT NULL, c9_pedido nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        c9_item nvarchar(4) COLLATE DATABASE_DEFAULT NOT NULL, qtdlib float NOT NULL, datalib nvarchar(8) COLLATE DATABASE_DEFAULT NULL,
        ordem int NOT NULL);
    INSERT INTO #lib (c9_filial, c9_pedido, c9_item, qtdlib, datalib, ordem)
    SELECT RTRIM(l.c9_filial), RTRIM(l.c9_pedido), LTRIM(RTRIM(l.c9_item)), l.c9_qtdlib,
           CASE WHEN l.c9_datalib LIKE '[12][0-9][0-9][0-9][01][0-9][0-3][0-9]' THEN l.c9_datalib END,
           CASE WHEN LTRIM(RTRIM(l.c9_blcred)) NOT IN ('', '10') OR LTRIM(RTRIM(l.c9_blest)) NOT IN ('', '10') THEN 0
                ELSE ISNULL((
                    SELECT MAX(r.ordem) FROM #regras r
                    CROSS APPLY (SELECT UPPER(LTRIM(RTRIM(ISNULL(JSON_VALUE(l.extras, N'$.' + r.campo), N'')))) AS v) c
                    WHERE (r.operador = 'preenchido' AND c.v <> N'')
                       OR (r.operador = 'vazio' AND c.v = N'')
                       OR (r.operador = 'igual' AND EXISTS (SELECT 1 FROM #valores w WHERE w.regra_id = r.id AND w.valor = c.v))
                       OR (r.operador = 'diferente' AND EXISTS (SELECT 1 FROM #valores w WHERE w.regra_id = r.id)
                           AND NOT EXISTS (SELECT 1 FROM #valores w WHERE w.regra_id = r.id AND w.valor = c.v))
                       OR (r.operador = 'comeca' AND c.v <> N''
                           AND EXISTS (SELECT 1 FROM #valores w WHERE w.regra_id = r.id AND LEFT(c.v, LEN(w.valor)) = w.valor))
                ), 1) END
    FROM dbo.pedidos_venda_liberacoes l
    WHERE LTRIM(RTRIM(l.c9_nfiscal)) = '';
    CREATE CLUSTERED INDEX ix_lib ON #lib (c9_filial, c9_pedido, c9_item);

    -- 3) Cada item: aberto (com saldo, sem resíduo), liberado, etapa da liberação mais atrasada e última liberação.
    SELECT RTRIM(i.c5_filial) AS c5_filial, RTRIM(i.c5_num) AS c5_num, i.c5_cliente, i.c5_lojacli, i.cliente_nome, i.c5_emissao,
           i.c6_qtdent AS qtdent, i.c6_prcven AS prcven, i.c6_valor AS valor, i.c6_qtdven - i.c6_qtdent AS saldo,
           CASE WHEN i.c6_entreg LIKE '[12][0-9][0-9][0-9][01][0-9][0-3][0-9]' THEN i.c6_entreg END AS entreg,
           CASE WHEN i.c6_datfat LIKE '[12][0-9][0-9][0-9][01][0-9][0-3][0-9]' THEN i.c6_datfat END AS datfat,
           CASE WHEN i.c6_qtdven - i.c6_qtdent > 0.00001 AND UPPER(LTRIM(RTRIM(i.c6_blq))) <> 'R' THEN 1 ELSE 0 END AS aberto,
           lb.liberado, lb.ordem, lb.ultima
    INTO #itens
    FROM dbo.pedidos_venda i
    OUTER APPLY (SELECT SUM(l.qtdlib) AS liberado, MIN(l.ordem) AS ordem, MAX(l.datalib) AS ultima
                 FROM #lib l WHERE l.c9_filial = RTRIM(i.c5_filial) AND l.c9_pedido = RTRIM(i.c5_num) AND l.c9_item = LTRIM(RTRIM(i.c6_item))) lb;

    -- 4) Cada pedido: a etapa do item aberto mais atrasado, valores e datas.
    SELECT c5_filial, c5_num, MAX(c5_cliente) AS c5_cliente, MAX(c5_lojacli) AS c5_lojacli, MAX(cliente_nome) AS cliente_nome,
           MAX(c5_emissao) AS c5_emissao,
           MIN(CASE WHEN aberto = 1 THEN CASE WHEN ordem IS NULL OR liberado + 0.00001 < saldo THEN 0 ELSE ordem END END) AS ordem,
           MAX(CASE WHEN qtdent > 0.00001 THEN 1 ELSE 0 END) AS entregou,
           ROUND(SUM(valor), 2) AS valor_total,
           ROUND(SUM(CASE WHEN aberto = 1 THEN saldo * prcven ELSE 0 END), 2) AS valor_saldo,
           ROUND(SUM(qtdent * prcven), 2) AS valor_faturado,
           ISNULL(MIN(CASE WHEN aberto = 1 THEN entreg END), ISNULL(MAX(entreg), '')) AS entrega_prevista,
           ISNULL(MAX(datfat), '') AS faturado_em,
           MAX(CASE WHEN aberto = 1 THEN ultima END) AS ultima_liberacao
    INTO #pedidos
    FROM #itens
    GROUP BY c5_filial, c5_num;

    -- 5) Nome da etapa, datas em UTC e "desde quando" (comparando com o cálculo anterior).
    SELECT p.*, e.etapa AS etapa_ant, CAST(e.etapa_desde AS datetime2(0)) AS desde_ant,
           CASE WHEN p.ordem IS NOT NULL THEN CHOOSE(p.ordem + 1, 'carteira', 'expedicao', 'separacao', 'conferencia', 'logistica', 'aguardando_faturamento')
                WHEN p.entregou = 1 THEN 'faturado' END AS etapa,
           CAST(TRY_CONVERT(datetime2(0), NULLIF(p.faturado_em, ''), 112) AT TIME ZONE @fuso AT TIME ZONE 'UTC' AS datetime2(0)) AS dia_fat,
           CAST(TRY_CONVERT(datetime2(0), p.ultima_liberacao, 112) AT TIME ZONE @fuso AT TIME ZONE 'UTC' AS datetime2(0)) AS dia_lib,
           CAST(TRY_CONVERT(datetime2(0), CASE WHEN p.c5_emissao LIKE '[12][0-9][0-9][0-9][01][0-9][0-3][0-9]' THEN p.c5_emissao END, 112)
                AT TIME ZONE @fuso AT TIME ZONE 'UTC' AS datetime2(0)) AS dia_emi
    INTO #calc
    FROM #pedidos p
    LEFT JOIN dbo.pedidos_venda_etapas e ON e.c5_filial = p.c5_filial AND e.c5_num = p.c5_num;

    SELECT c.*,
        CAST(CASE WHEN c.etapa IS NOT NULL AND c.etapa <> 'faturado' AND c.entregou = 1 THEN 1 ELSE 0 END AS bit) AS parcial,
        CASE
            WHEN c.etapa IS NULL THEN NULL
            WHEN c.etapa = 'faturado' THEN COALESCE(c.dia_fat, CASE WHEN c.etapa_ant = 'faturado' THEN c.desde_ant END, @agora)
            WHEN c.etapa = 'expedicao' AND c.dia_lib IS NOT NULL THEN c.dia_lib
            WHEN c.etapa = c.etapa_ant AND c.desde_ant IS NOT NULL THEN c.desde_ant
            WHEN c.etapa_ant IS NULL AND c.desde_ant IS NULL
                THEN COALESCE(CASE WHEN c.etapa = 'carteira' THEN c.dia_emi ELSE COALESCE(c.dia_lib, c.dia_emi) END, @agora)
            ELSE @agora END AS desde
    INTO #final
    FROM #calc c;

    -- 6) Grava só o que mudou. Pedido que sumiu de pedidos_venda fica sem etapa (some da tela).
    MERGE dbo.pedidos_venda_etapas WITH (HOLDLOCK) AS t
    USING #final AS s ON t.c5_filial = s.c5_filial AND t.c5_num = s.c5_num
    WHEN MATCHED AND EXISTS (
        SELECT s.c5_cliente, s.c5_lojacli, s.cliente_nome, s.c5_emissao, s.etapa, s.desde, s.parcial, s.valor_total,
               s.valor_saldo, s.valor_faturado, s.entrega_prevista, s.faturado_em
        EXCEPT
        SELECT t.c5_cliente, t.c5_lojacli, t.cliente_nome, t.c5_emissao, t.etapa, CAST(t.etapa_desde AS datetime2(0)), t.parcial,
               t.valor_total, t.valor_saldo, t.valor_faturado, t.entrega_prevista, t.faturado_em
    ) THEN UPDATE SET
        c5_cliente = s.c5_cliente, c5_lojacli = s.c5_lojacli, cliente_nome = s.cliente_nome, c5_emissao = s.c5_emissao,
        etapa = s.etapa, etapa_desde = s.desde, parcial = s.parcial, valor_total = s.valor_total, valor_saldo = s.valor_saldo,
        valor_faturado = s.valor_faturado, entrega_prevista = s.entrega_prevista, faturado_em = s.faturado_em, calculado_em = @agora
    WHEN NOT MATCHED BY TARGET THEN INSERT (
        c5_filial, c5_num, c5_cliente, c5_lojacli, cliente_nome, c5_emissao, etapa, etapa_desde, parcial, valor_total,
        valor_saldo, valor_faturado, entrega_prevista, faturado_em, calculado_em
    ) VALUES (
        s.c5_filial, s.c5_num, s.c5_cliente, s.c5_lojacli, s.cliente_nome, s.c5_emissao, s.etapa, s.desde, s.parcial,
        s.valor_total, s.valor_saldo, s.valor_faturado, s.entrega_prevista, s.faturado_em, @agora
    )
    WHEN NOT MATCHED BY SOURCE AND t.etapa IS NOT NULL THEN UPDATE SET
        etapa = NULL, etapa_desde = NULL, parcial = 0, valor_saldo = 0, calculado_em = @agora
    OUTPUT $action INTO @acoes (acao);

    SET @alterados = (SELECT COUNT(*) FROM @acoes);
    IF @devolver = 1
        SELECT @alterados AS alterados;
END
