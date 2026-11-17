# Pedidos de compra: OEE Alumbra → Protheus (tabela muro)

O administrador da unidade gera o pedido de compra no portal, a partir dos itens aprovados de uma **Solicitação de
material** ou direto numa **Ordem de serviço** aberta. O pedido tem o centro de custo de **origem** (quem pede) e de
**destino** (recebe o custo; vai no `C7_CC`). O portal grava o pedido numa tabela muro do banco do Protheus; um job do
Protheus gera o pedido e devolve o número, a aprovação, a conclusão e os valores.

```
Portal ──INSERT──▶ OEE_PEDIDO_COMPRA (+ _ITEM)  STATUS = AGUARDANDO_PROTHEUS
                         │
             OEEPCJOB (schedule do Protheus)
                         ├─ MATA120 (ExecAuto) ─▶ SC7   STATUS = AGUARDANDO_APROVACAO, NUM_PEDIDO
                         └─ confere SC7/SCR/SD1          APROVADO (aprovador, data) → CONCLUIDO (data, valores)
                                                         REPROVADO · ERRO (mensagem) · CANCELADO
Portal ◀──SELECT── a cada 10 min (compras:atualizar-pedidos) e ao abrir a solicitação ou a OS
```

## 1. Criar as tabelas (uma vez)

Rode `01-tabelas-muro.sql` no banco do Protheus (o mesmo que o portal lê, por padrão `Compras`). No fim do arquivo
estão os `GRANT` para o usuário do portal: ele só grava nestas duas tabelas.

## 2. Compilar e agendar o fonte

1. Compile `OEEPCJOB.tlpp` no RPO.
2. Parâmetros (SX6, caractere, todos opcionais):

   | Parâmetro    | Para quê                                                        | Exemplo        |
   |--------------|-----------------------------------------------------------------|----------------|
   | `MV_XOEEFOR` | fornecedor padrão do pedido                                     | `000001`       |
   | `MV_XOEELOJ` | loja padrão                                                     | `01`           |
   | `MV_XOEECND` | condição de pagamento padrão                                    | `001`          |
   | `MV_XOEECCO` | campo da SC7 que recebe o CC de origem (crie o campo antes)     | `C7_XCCORI`    |
   | `MV_XOEEPCC` | nome da tabela muro do cabeçalho                                | `OEE_PEDIDO_COMPRA` |
   | `MV_XOEEPCI` | nome da tabela muro dos itens                                   | `OEE_PEDIDO_COMPRA_ITEM` |

   O portal ainda não escolhe fornecedor: o pedido nasce com o fornecedor/loja/condição padrão e o comprador ajusta no
   Protheus antes de aprovar (as colunas `FORNECEDOR`, `LOJA` e `COND_PAGTO` da muro já existem para quando o portal
   passar a informar).
3. Agende `U_OEEPCJOB` no Schedule (SIGACFG), com a empresa/filial, a cada 5 ou 10 minutos. À mão:
   `U_OEEPCJOB({"01", "0101"})`.

## Regras do job

- Preço do item: o `PRECO` da muro (o portal manda 0) → `B1_UPRC` → custo médio `B2_CM1` do armazém padrão; sem
  nenhum, o pedido fica com `STATUS = ERRO` e a mensagem. No portal o administrador pode **reenviar** (depois de
  corrigir o cadastro) ou **cancelar**.
- `C7_OBS` começa com `OEE#<ID>` (ID da muro): se o job cair no meio, na próxima rodada ele reconhece o pedido já
  gerado (fica `PROCESSANDO` por até 30 minutos).
- Aprovação: `C7_CONAPRO` = `B` aguardando, `L` liberado (aprovador = último `CR_USERLIB` da SCR, tipo PC/IP), `R`
  reprovado. Pedido sem alçada vira `APROVADO` com "Liberado sem alçada".
- Conclusão: todos os itens entregues (`C7_QUJE >= C7_QUANT`), eliminados por resíduo (`C7_RESIDUO = 'S'`) ou
  encerrados (`C7_ENCER = 'E'`). Data = última entrada na SD1. Valor do pedido concluído = quantidade entregue × preço.
- Antes de concluir, o valor é a soma do `C7_TOTAL` (atualizado a cada rodada; o portal soma no custo da OS).
- Pedido excluído no Protheus → `CANCELADO`.
- Cancelar no portal só funciona enquanto o pedido está `AGUARDANDO_PROTHEUS` ou `ERRO` (UPDATE condicionado ao
  status: se o job já pegou, o portal avisa).
