# Pedidos de compra: OEE Alumbra → Protheus (tabelas muro nativas ZP1 / ZP2)

O administrador da unidade gera o pedido de compra no portal, a partir dos itens aprovados de uma **Solicitação de
material** ou direto numa **Ordem de serviço** aberta, com o centro de custo de **origem** (quem pede) e de **destino**
(recebe o custo; vai no `C7_CC`). O portal grava o pedido em duas tabelas NATIVAS do Protheus (criadas pelo dicionário);
um job do Protheus gera o pedido e devolve o número, a aprovação, a conclusão e os valores.

```
Portal ──INSERT──▶ ZP1 (cabeçalho) + ZP2 (itens)   ZP1_STATUS = AGUARDANDO_PROTHEUS
                         │
             U_OEEPCJOB (Schedule do Protheus)
                         ├─ MATA120 (ExecAuto) ─▶ SC7   AGUARDANDO_APROVACAO + ZP1_NUMPED
                         └─ confere SC7/SCR/SD1          APROVADO (ZP1_APROV, data) → CONCLUIDO (data, valores)
                                                         REPROVADO · ERRO (ZP1_MSG) · CANCELADO
Portal ◀──SELECT── a cada 10 min (compras:atualizar-pedidos) e ao abrir a solicitação ou a OS
```

## 1. Criar as tabelas pelo dicionário (uma vez)

1. Compile `OEEUPDPC.tlpp` e `OEEPCJOB.tlpp` no RPO.
2. Com ninguém usando ZP1/ZP2, rode o compatibilizador `U_OEEUPDPC`:
   - pelo SmartClient, programa inicial `U_OEEUPDPC` (empresa/filial de `EMPRESA_PADRAO`/`FILIAL_PADRAO` no fonte); ou
   - já logado na empresa, em Fórmulas: `U_OEEUPDPC()`.

   Ele grava **SX2** (tabelas compartilhadas), **SX3** (campos) e **SIX** (índices) e cria as tabelas físicas
   (`ZP1010`, `ZP2010`) com `X31UpdTable`. Pode rodar de novo (atualiza, não apaga). Se ZP1 ou ZP2 já forem de outra
   customização, ele para sem mexer em nada: troque `ALIAS_CAB` / `ALIAS_ITE` no fonte, rode de novo e informe os
   novos aliases no `.env` do portal (`COMPRAS_MURO_ALIAS`, `COMPRAS_MURO_ALIAS_ITENS`) e nos parâmetros
   `MV_XOEEPCC` / `MV_XOEEPCI`.
3. Dê ao usuário SQL que o portal usa no banco do Protheus permissão SÓ nestas duas tabelas:

   ```sql
   GRANT SELECT, INSERT, UPDATE ON dbo.ZP1010 TO [usuario_do_portal];
   GRANT SELECT, INSERT, UPDATE ON dbo.ZP2010 TO [usuario_do_portal];
   ```

O portal grava cada linha como o Protheus grava: `D_E_L_E_T_ = ' '`, `R_E_C_N_O_` = próximo número (com trava de
leitura na transação), `_FILIAL` em branco (tabela compartilhada), datas `AAAAMMDD` e horas `HH:MM:SS`.

### ZP1 — OEE - Pedido de compra (chave única `ZP1_FILIAL+ZP1_IDPORT`)

| Campo | Tipo | Conteúdo |
|---|---|---|
| ZP1_FILIAL | C filial | em branco (compartilhada) |
| ZP1_IDPORT | C 10 | nº do pedido no portal (zeros à esquerda) |
| ZP1_ORIGEM | C 30 | SM-000123 ou OS-000045 |
| ZP1_FILPED | C filial | filial do pedido (vazio = a do job) |
| ZP1_CCORIG / ZP1_CCDEST | C (CTT_CUSTO) | centro de custo de origem / de destino (C7_CC) |
| ZP1_FORNEC / ZP1_LOJA / ZP1_COND | C | opcionais (vazio = MV_XOEEFOR / MV_XOEELOJ / MV_XOEECND) |
| ZP1_SOLIC | C 60 | quem gerou no portal |
| ZP1_OBS | C 250 | observação do pedido |
| ZP1_STATUS | C 20 | AGUARDANDO_PROTHEUS, PROCESSANDO, AGUARDANDO_APROVACAO, APROVADO, CONCLUIDO, REPROVADO, ERRO, CANCELADO |
| ZP1_MSG | C 250 | erro do ExecAuto ou aviso |
| ZP1_NUMPED | C (C7_NUM) | número do pedido gerado |
| ZP1_DTINT / ZP1_HRINT | D / C 8 | integração |
| ZP1_APROV, ZP1_DTAPRO / ZP1_HRAPRO | C 60, D / C 8 | aprovação |
| ZP1_DTCONC / ZP1_HRCONC | D / C 8 | conclusão |
| ZP1_VALOR | N 16,2 | soma do C7_TOTAL (concluído: o entregue) |
| ZP1_TENTAT | N 3 | tentativas de geração |
| ZP1_DTCRIA / ZP1_HRCRIA, ZP1_DTATU / ZP1_HRATU | D / C 8 | gravação pelo portal / última alteração |

Índices: 1 `FILIAL+IDPORT`, 2 `FILIAL+STATUS+IDPORT`, 3 `FILIAL+NUMPED`.

### ZP2 — OEE - Itens do pedido de compra (chave única `ZP2_FILIAL+ZP2_IDPORT+ZP2_ITEM`)

| Campo | Tipo | Conteúdo |
|---|---|---|
| ZP2_FILIAL | C filial | em branco |
| ZP2_IDPORT | C 10 | nº do pedido no portal (liga com ZP1) |
| ZP2_ITEM | C 4 | 0001, 0002... (vira o C7_ITEM) |
| ZP2_IDITEM | C 10 | nº do item no portal |
| ZP2_PROD / ZP2_DESC / ZP2_UM | C | produto (B1_COD), descrição (60), unidade |
| ZP2_QUANT | N 14,4 | quantidade |
| ZP2_PRECO | N 16,4 | 0 = o job usa B1_UPRC (ou B2_CM1) |
| ZP2_OBS | C 100 | observação do item |
| ZP2_C7ITEM | C 4 | item gerado no pedido |
| ZP2_VUNIT / ZP2_VTOTAL / ZP2_QTDENT | N | C7_PRECO / C7_TOTAL / C7_QUJE |

Índice: 1 `FILIAL+IDPORT+ITEM`.

## 2. Agendar o job

Parâmetros (SX6, caractere, todos opcionais):

| Parâmetro    | Para quê                                                    | Exemplo     |
|--------------|-------------------------------------------------------------|-------------|
| `MV_XOEEFOR` | fornecedor padrão do pedido                                 | `000001`    |
| `MV_XOEELOJ` | loja padrão                                                 | `01`        |
| `MV_XOEECND` | condição de pagamento padrão                                | `001`       |
| `MV_XOEECCO` | campo da SC7 que recebe o CC de origem (crie o campo antes) | `C7_XCCORI` |
| `MV_XOEEPCC` | alias da tabela do cabeçalho                                | `ZP1`       |
| `MV_XOEEPCI` | alias da tabela dos itens                                   | `ZP2`       |

O portal ainda não escolhe fornecedor: o pedido nasce com o fornecedor/loja/condição padrão e o comprador ajusta no
Protheus antes de aprovar (os campos `ZP1_FORNEC`, `ZP1_LOJA` e `ZP1_COND` já existem para quando o portal informar).

Agende `U_OEEPCJOB` no Schedule (SIGACFG), com a empresa/filial, a cada 5 ou 10 minutos. À mão:
`U_OEEPCJOB({"01", "0101"})`.

## Regras do job

- Preço do item: `ZP2_PRECO` (o portal manda 0) → `B1_UPRC` → custo médio `B2_CM1` do armazém padrão; sem nenhum, o
  pedido fica com `ERRO` e a mensagem. No portal o administrador pode **reenviar** (depois de corrigir o cadastro) ou
  **cancelar**.
- `C7_OBS` começa com `OEE#<nº do portal>`: se o job cair no meio, depois de 30 minutos em `PROCESSANDO` ele reconhece
  o pedido já gerado (ou devolve para a fila).
- Aprovação: `C7_CONAPRO` = `B` aguardando, `L` liberado (aprovador = último `CR_USERLIB` da SCR, tipo PC/IP; a SCR só
  guarda a data, então a hora fica em branco se não foi hoje), `R` reprovado. Pedido sem alçada vira `APROVADO` com
  "Liberado sem alçada".
- Conclusão: todos os itens entregues (`C7_QUJE >= C7_QUANT`), eliminados por resíduo (`C7_RESIDUO = 'S'`) ou
  encerrados (`C7_ENCER = 'E'`). Data = última entrada na SD1. Valor do pedido concluído = quantidade entregue × preço.
- Antes de concluir, o valor é a soma do `C7_TOTAL` (atualizada a cada rodada; o portal soma no custo da OS).
- Pedido excluído no Protheus → `CANCELADO`.
- O job atualiza a muro por UPDATE condicionado ao status (o portal cancela do mesmo jeito): cancelar no portal só
  funciona enquanto o pedido está `AGUARDANDO_PROTHEUS` ou `ERRO`.
