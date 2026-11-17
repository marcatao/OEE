/*
 * OEE Alumbra -> Protheus: TABELAS MURO dos pedidos de compra.
 *
 * Rode UMA vez no banco do Protheus (o mesmo que o portal lê, ex.: Compras). Pode rodar de novo: só cria o que falta.
 * - O PORTAL grava o pedido (INSERT) com STATUS = 'AGUARDANDO_PROTHEUS' e lê de volta o que o Protheus preencher.
 * - O PROTHEUS (fonte OEEPCJOB.tlpp, no schedule) gera o pedido (MATA120) e atualiza STATUS, NUM_PEDIDO, aprovação,
 *   conclusão e valores.
 *
 * STATUS (cabeçalho):
 *   AGUARDANDO_PROTHEUS  -> gravado pelo portal; o Protheus ainda não pegou
 *   PROCESSANDO          -> o job do Protheus pegou e está gerando o pedido
 *   AGUARDANDO_APROVACAO -> pedido gerado (NUM_PEDIDO preenchido), aguardando a aprovação (alçada)
 *   APROVADO             -> liberado (APROVADOR e APROVADO_EM preenchidos)
 *   CONCLUIDO            -> tudo entregue ou eliminado por resíduo (CONCLUIDO_EM e valores finais)
 *   REPROVADO            -> reprovado na alçada
 *   ERRO                 -> o ExecAuto recusou (MENSAGEM traz o motivo); o portal pode reenviar ou cancelar
 *   CANCELADO            -> cancelado no portal (antes de o Protheus pegar) ou pedido excluído no Protheus
 *
 * Datas no horário local do servidor do banco (GETDATE()).
 */

IF OBJECT_ID('dbo.OEE_PEDIDO_COMPRA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.OEE_PEDIDO_COMPRA (
        ID             INT IDENTITY(1, 1) NOT NULL CONSTRAINT PK_OEE_PEDIDO_COMPRA PRIMARY KEY,
        ID_PORTAL      INT            NOT NULL,                    -- nº do pedido no portal (pedidos_compra.id)
        ORIGEM         VARCHAR(30)    NOT NULL DEFAULT '',         -- SM-000123 (solicitação de material) ou OS-000045
        FILIAL         VARCHAR(8)     NOT NULL DEFAULT '',         -- filial do pedido (vazio = a do job)
        CC_ORIGEM      VARCHAR(20)    NOT NULL DEFAULT '',         -- centro de custo de quem pede
        CC_DESTINO     VARCHAR(20)    NOT NULL DEFAULT '',         -- centro de custo que recebe o custo (C7_CC)
        FORNECEDOR     VARCHAR(10)    NOT NULL DEFAULT '',         -- opcional: vazio = parâmetro MV_XOEEFOR
        LOJA           VARCHAR(4)     NOT NULL DEFAULT '',         -- opcional: vazio = parâmetro MV_XOEELOJ
        COND_PAGTO     VARCHAR(3)     NOT NULL DEFAULT '',         -- opcional: vazio = parâmetro MV_XOEECND
        SOLICITANTE    VARCHAR(120)   NOT NULL DEFAULT '',         -- nome de quem gerou no portal
        OBSERVACAO     VARCHAR(500)   NOT NULL DEFAULT '',
        STATUS         VARCHAR(25)    NOT NULL CONSTRAINT DF_OEE_PEDIDO_COMPRA_STATUS DEFAULT 'AGUARDANDO_PROTHEUS',
        MENSAGEM       VARCHAR(1000)  NULL,                        -- erro do ExecAuto ou aviso
        NUM_PEDIDO     VARCHAR(10)    NOT NULL DEFAULT '',         -- C7_NUM gerado
        INTEGRADO_EM   DATETIME       NULL,
        APROVADOR      VARCHAR(120)   NULL,
        APROVADO_EM    DATETIME       NULL,
        CONCLUIDO_EM   DATETIME       NULL,
        VALOR_TOTAL    DECIMAL(18, 2) NULL,                        -- soma do C7_TOTAL (concluído: o entregue)
        TENTATIVAS     INT            NOT NULL DEFAULT 0,
        CRIADO_EM      DATETIME       NOT NULL DEFAULT GETDATE(),
        ATUALIZADO_EM  DATETIME       NULL,
        CONSTRAINT UQ_OEE_PEDIDO_COMPRA_PORTAL UNIQUE (ID_PORTAL),
        CONSTRAINT CK_OEE_PEDIDO_COMPRA_STATUS CHECK (STATUS IN ('AGUARDANDO_PROTHEUS', 'PROCESSANDO', 'AGUARDANDO_APROVACAO',
            'APROVADO', 'CONCLUIDO', 'REPROVADO', 'ERRO', 'CANCELADO'))
    );
    CREATE INDEX IX_OEE_PEDIDO_COMPRA_STATUS ON dbo.OEE_PEDIDO_COMPRA (STATUS, ID);
END
GO

IF OBJECT_ID('dbo.OEE_PEDIDO_COMPRA_ITEM', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.OEE_PEDIDO_COMPRA_ITEM (
        ID             INT IDENTITY(1, 1) NOT NULL CONSTRAINT PK_OEE_PEDIDO_COMPRA_ITEM PRIMARY KEY,
        ID_PEDIDO      INT            NOT NULL CONSTRAINT FK_OEE_PEDIDO_COMPRA_ITEM_PEDIDO REFERENCES dbo.OEE_PEDIDO_COMPRA (ID),
        ITEM           VARCHAR(4)     NOT NULL,                    -- 0001, 0002... (vira o C7_ITEM)
        ID_ITEM_PORTAL INT            NOT NULL,                    -- pedidos_compra_itens.id
        PRODUTO        VARCHAR(30)    NOT NULL,                    -- B1_COD
        DESCRICAO      VARCHAR(120)   NOT NULL DEFAULT '',
        UM             VARCHAR(2)     NOT NULL DEFAULT '',
        QUANTIDADE     DECIMAL(14, 4) NOT NULL,
        PRECO          DECIMAL(18, 4) NOT NULL DEFAULT 0,          -- 0 = o Protheus usa B1_UPRC (ou o custo médio B2_CM1)
        OBSERVACAO     VARCHAR(250)   NOT NULL DEFAULT '',
        C7_ITEM        VARCHAR(4)     NOT NULL DEFAULT '',         -- item gerado no pedido
        VALOR_UNITARIO DECIMAL(18, 4) NULL,                        -- C7_PRECO
        VALOR_TOTAL    DECIMAL(18, 2) NULL,                        -- C7_TOTAL
        QTD_ENTREGUE   DECIMAL(14, 4) NULL,                        -- C7_QUJE
        CONSTRAINT UQ_OEE_PEDIDO_COMPRA_ITEM UNIQUE (ID_PEDIDO, ITEM),
        CONSTRAINT CK_OEE_PEDIDO_COMPRA_ITEM_QTD CHECK (QUANTIDADE > 0)
    );
    CREATE INDEX IX_OEE_PEDIDO_COMPRA_ITEM_PORTAL ON dbo.OEE_PEDIDO_COMPRA_ITEM (ID_ITEM_PORTAL);
END
GO

/*
 * Permissões (troque [usuario_do_portal] pelo login SQL que o portal usa no Protheus). O portal só grava nestas duas
 * tabelas; o resto do banco do Protheus continua só leitura para ele.
 *
 * GRANT SELECT, INSERT, UPDATE ON dbo.OEE_PEDIDO_COMPRA      TO [usuario_do_portal];
 * GRANT SELECT, INSERT, UPDATE ON dbo.OEE_PEDIDO_COMPRA_ITEM TO [usuario_do_portal];
 */
