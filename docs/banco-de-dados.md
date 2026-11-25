# Banco de dados — OEE Alumbra

> Gerado por `php artisan banco:documentar` a partir das migrações (tipos do **SQL Server**). Não edite as
> seções geradas: mude a migração e gere de novo. O trecho "Decisões e sugestões de modelagem" é mantido.

- **64** tabelas do sistema e **6** de infraestrutura do Laravel.
- Origem: **Sistema OEE** (criada e mantida pelo portal), **Protheus** (copiada pela sincronização, só leitura no Protheus) ou
  **Mista** (parte dos campos vem do Protheus na primeira sincronização e o resto é do portal).
- Todas as datas são gravadas em UTC. Cadastros têm auditoria (`criado_em`, `criado_por_id`, `atualizado_em`, `atualizado_por_id` → `usuarios`).

## Diagrama

Tabelas de infraestrutura e as ligações de auditoria (`criado_por_id`/`atualizado_por_id` → `usuarios`) ficam fora do
diagrama para ele ficar legível. Linha tracejada = ligação por código, sem chave estrangeira no banco.

```mermaid
erDiagram
    agendas_sincronizacao {
        bigint id PK
        nvarchar(40) chave UK
        bit ativo
        int intervalo_minutos
        nchar(5) inicio
        datetime ultimo_horario
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    apontamentos {
        bigint id PK
        bigint maquina_id FK
        bigint turno_id FK
        bigint produto_id FK
        datetime inicio
        datetime fim
        float(53) ciclo_ideal_s
        int total_produzido
        int pecas_boas
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    apontamentos_estado {
        bigint id PK
        bigint maquina_id FK
        bigint legenda_id FK
        datetime inicio
        datetime fim
        nvarchar(40) referencia
        nvarchar(500) observacao
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        bigint ordem_producao_id FK
        smallint ciclo_medio
        bigint iot_dispositivo_id FK
        nvarchar(20) iot_canal
        bigint solicitacao_vinculada_id FK
        int ciclos
        smallint cavidades
        int pecas
        int ciclo_medio_ms
    }
    assistente_conversas {
        bigint id PK
        bigint usuario_id FK
        nvarchar(120) titulo
        nvarchar(max) mensagens
        datetime criado_em
        datetime atualizado_em
    }
    assistente_perguntas {
        bigint id PK
        bigint conversa_id FK
        bigint usuario_id FK
        nvarchar(2000) pergunta
        int posicao
        nvarchar(12) situacao
        smallint etapas
        nvarchar(1000) ferramentas
        nvarchar(60) modelo
        int tokens_entrada
        int tokens_saida
        int tokens_cache_leitura
        int tokens_cache_escrita
        nvarchar(1000) erro
        datetime criado_em
        datetime concluida_em
    }
    calendario_dias {
        bigint id PK
        bigint calendario_id FK
        date data
        nvarchar(12) tipo
        nvarchar(120) descricao
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    calendarios {
        bigint id PK
        nvarchar(20) unidade
        nvarchar(80) nome
        smallint ano
        nvarchar(300) descricao
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    centros_custo {
        bigint id PK
        nchar(8) ctt_filial
        nvarchar(20) ctt_custo
        nvarchar(40) ctt_desc01
        nchar(1) ctt_classe
        nchar(1) ctt_bloq
        nvarchar(20) ctt_ccsup
        nchar(8) ctt_dtexis
        nchar(8) ctt_dtexsf
        nchar(1) d_e_l_e_t_
        int r_e_c_n_o_
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        nvarchar(20) unidade
    }
    dispositivos_iot {
        bigint id PK
        nvarchar(40) codigo UK
        nvarchar(120) nome
        nvarchar(20) tipo
        nvarchar(20) protocolo
        nvarchar(200) endereco
        int porta
        nvarchar(250) topico
        bigint maquina_id FK
        datetime ultimo_sinal_em
        nvarchar(500) observacao
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        nvarchar(20) unidade
    }
    empenhos_op {
        bigint id PK
        nvarchar(8) d4_filial
        nvarchar(30) d4_cod
        nvarchar(20) d4_op
        nvarchar(20) op_numero
        nvarchar(4) d4_local
        nchar(8) d4_data
        float(53) d4_qtdeori
        float(53) d4_quant
        nvarchar(3) d4_trt
        nvarchar(20) d4_lotectl
        nvarchar(20) d4_oporig
        nchar(1) d_e_l_e_t_
        int r_e_c_n_o_ UK
        datetime sincronizado_em
    }
    estruturas_produto {
        bigint id PK
        nvarchar(8) g1_filial
        nvarchar(30) g1_cod
        nvarchar(30) g1_comp
        nvarchar(3) g1_trt
        float(53) g1_quant
        float(53) g1_perda
        nchar(8) g1_ini
        nchar(8) g1_fim
        nchar(1) g1_fixvar
        nvarchar(3) g1_revini
        nvarchar(3) g1_revfim
        nchar(1) d_e_l_e_t_
        int r_e_c_n_o_
        datetime sincronizado_em
    }
    faltas_produto {
        bigint id PK
        nvarchar(6) zb5_filial
        nvarchar(10) zb5_doc
        nvarchar(16) zb5_produt
        float(53) zb5_qtdsol
        float(53) zb5_qtdate
        nvarchar(6) zb5_pedido
        float(53) zb5_vlped
        nvarchar(19) zb5_solici
        nchar(8) zb5_dtsoli
        nvarchar(8) zb5_horini
        nchar(8) zb5_dtaten
        nvarchar(8) zb5_horfim
        nvarchar(19) zb5_atendi
        nchar(1) zb5_status
        datetime solicitado_em
        datetime atendido_em
        nchar(1) d_e_l_e_t_
        int r_e_c_n_o_ UK
        datetime sincronizado_em
    }
    ferramentas {
        bigint id PK
        nvarchar(8) h4_filial
        nvarchar(10) h4_codigo
        nvarchar(120) h4_descri
        float(53) h4_quant
        float(53) h4_vidauti
        nchar(1) h4_tipovid
        int h4_qtdcav
        int h4_qtdcavativas
        nvarchar(20) unidade
        nchar(1) d_e_l_e_t_
        int r_e_c_n_o_
        datetime sincronizado_em
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        bit disponivel
    }
    filtros_usuarios {
        bigint id PK
        bigint usuario_id FK
        nvarchar(40) tela
        nvarchar(80) nome
        nvarchar(max) filtros
        bit padrao
        datetime criado_em
        datetime atualizado_em
    }
    grupo_permissoes {
        bigint grupo_id PK, FK
        nvarchar(60) permissao PK
    }
    grupo_usuario {
        bigint grupo_id PK, FK
        bigint usuario_id PK, FK
    }
    grupos {
        bigint id PK
        nvarchar(80) nome UK
        nvarchar(300) descricao
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    legendas {
        bigint id PK
        nvarchar(12) codigo UK
        nvarchar(120) descricao
        nvarchar(20) tipo
        nvarchar(7) cor
        int ordem
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        nvarchar(20) unidade
        bit exige_op
        nvarchar(20) abre_ss_unidade
        bigint abre_ss_area_executante_id FK
        bit exibir_dashboards
    }
    linhas {
        bigint id PK
        bigint planta_id FK
        nvarchar(20) codigo
        nvarchar(120) nome
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        nvarchar(20) unidade
    }
    maos_de_obra {
        bigint id PK
        nvarchar(30) b1_cod UK
        decimal(6, fator_servico
        nvarchar(20) unidade
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    maquinas {
        bigint id PK
        bigint linha_id FK
        nvarchar(30) codigo UK
        nvarchar(120) nome
        nvarchar(120) fabricante
        nvarchar(120) modelo
        float(53) ciclo_ideal_s
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        nvarchar(20) centro_custo
        int micro_parada_s
        int macro_parada_s
        nvarchar(100) iot1_id
        nvarchar(100) iot1_canal
        nvarchar(20) iot1_tipo
        nvarchar(100) iot2_id
        nvarchar(100) iot2_canal
        nvarchar(20) iot2_tipo
        nvarchar(100) iot3_id
        nvarchar(100) iot3_canal
        nvarchar(20) iot3_tipo
        nvarchar(8) hb_filial
        int r_e_c_n_o_
        nchar(1) d_e_l_e_t_
        datetime sincronizado_em
        datetime excluida_protheus_em
        bigint iot1_dispositivo_id FK
        bigint iot2_dispositivo_id FK
        bigint iot3_dispositivo_id FK
        nvarchar(20) unidade
        nvarchar(12) situacao
        datetime situacao_desde
        bigint situacao_legenda_id FK
        bigint ordem_producao_id FK
        bigint calendario_id FK
        nvarchar(7) dias_semana
        nvarchar(5) jornada_inicio
        nvarchar(5) jornada_fim
        nvarchar(200) horarios_semana
        int micro_parada_ms
        int macro_parada_ms
        nvarchar(1000) turnos_semana
    }
    metas_producao {
        bigint id PK
        nvarchar(30) produto
        nvarchar(3) revisao
        nvarchar(30) item_mo
        float(53) horas
        float(53) base
        float(53) por_minuto
        datetime vigente_desde
        datetime registrado_em
        float(53) fator_servico
    }
    metas_producao_horas {
        bigint id PK
        bigint maquina_id FK
        bigint ordem_producao_id FK
        date dia
        datetime hora_inicio
        float(53) meta_por_minuto
        int segundos
        float(53) meta
        int pecas
        datetime atualizado_em
    }
    motivos_parada {
        bigint id PK
        nvarchar(20) codigo UK
        nvarchar(200) descricao
        nvarchar(60) categoria
        bit planejada
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    movimentos_producao {
        bigint id PK
        nvarchar(8) d3_filial
        nvarchar(3) d3_tm
        nvarchar(3) d3_cf
        nvarchar(30) d3_cod
        nvarchar(2) d3_um
        float(53) d3_quant
        nvarchar(4) d3_local
        nvarchar(20) d3_doc
        nchar(8) d3_emissao
        nvarchar(10) d3_numseq
        float(53) d3_custo1
        nchar(1) d3_estorno
        nvarchar(20) op
        nvarchar(20) op_numero
        nvarchar(20) tipo
        nchar(1) d_e_l_e_t_
        int r_e_c_n_o_ UK
        datetime sincronizado_em
        float(53) custo_medio
    }
    notas_fiscais_op {
        bigint id PK
        nvarchar(8) d2_filial
        nvarchar(20) d2_doc
        nvarchar(3) d2_serie
        nvarchar(4) d2_item
        nvarchar(30) d2_cod
        nvarchar(2) d2_um
        float(53) d2_quant
        nvarchar(4) d2_local
        nchar(8) d2_emissao
        float(53) d2_custo1
        nvarchar(5) d2_cf
        nvarchar(10) d2_cliente
        nvarchar(4) d2_loja
        nvarchar(20) op
        nvarchar(20) op_numero
        nchar(1) d_e_l_e_t_
        int r_e_c_n_o_ UK
        datetime sincronizado_em
        float(53) custo_medio
    }
    ordens_producao {
        bigint id PK
        nvarchar(8) c2_filial
        nvarchar(10) c2_num
        nvarchar(4) c2_item
        nvarchar(4) c2_sequen
        nvarchar(20) numero
        nvarchar(30) c2_produto
        nvarchar(4) c2_local
        nvarchar(20) c2_cc
        nvarchar(2) c2_um
        float(53) c2_quant
        float(53) c2_quje
        float(53) c2_perda
        nchar(8) c2_emissao
        nchar(8) c2_datpri
        nchar(8) c2_datprf
        nchar(8) c2_datrf
        nchar(1) c2_tpop
        nchar(1) c2_status
        nvarchar(120) c2_obs
        nchar(1) d_e_l_e_t_
        int r_e_c_n_o_
        nvarchar(20) status
        datetime status_em
        datetime sincronizado_em
        float(53) custo_meta
        nvarchar(2) c2_roteiro
        nvarchar(3) c2_revisao
        decimal(18, quantidade_real
        decimal(18, quantidade_perda
    }
    ordens_servico {
        bigint id PK
        nvarchar(20) unidade
        bigint solicitacao_id FK, UK
        bigint plano_preventiva_id FK
        date prevista_para
        nvarchar(15) tipo
        bigint maquina_id FK
        bigint area_executante_id FK
        nvarchar(20) centro_custo
        nvarchar(120) centro_custo_solicitante
        nvarchar(10) prioridade
        bit maquina_parada
        nvarchar(2000) descricao
        bigint solicitante_id FK
        bigint aprovado_por_id FK
        bigint responsavel_id FK
        nvarchar(20) status
        datetime iniciada_em
        datetime concluida_em
        nvarchar(2000) solucao
        bigint aceite_por_id FK
        datetime aceite_em
        nvarchar(500) aceite_observacao
        nvarchar(500) cancelamento_motivo
        bigint ordem_producao_id FK
        bigint apontamento_estado_id FK
        int total_minutos
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        nvarchar(120) titulo
        nvarchar(10) avaliacao
        bit risco_integridade
        bit automatica
        nvarchar(120) origem_iot
        bigint ferramenta_id FK
    }
    os_apontamentos_horas {
        bigint id PK
        bigint ordem_servico_id FK
        bigint mantenedor_id FK, UK
        datetime inicio
        datetime fim
        int minutos_normais
        int minutos_extras
        nvarchar(1000) descricao
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    os_areas_executantes {
        bigint id PK
        nvarchar(20) unidade
        nvarchar(20) codigo UK
        nvarchar(80) nome
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        int meta_atendimento_min
        int meta_reparo_min
    }
    os_assinaturas {
        bigint id PK
        bigint ordem_servico_id FK
        bigint assinado_por_id FK
        bigint registrado_por_id FK
        nvarchar(120) nome_assinante
        nvarchar(10) avaliacao
        nvarchar(max) imagem_png
        datetime criado_em
    }
    os_checklist {
        bigint id PK
        bigint ordem_servico_id FK
        bigint plano_item_id FK
        smallint ordem
        nvarchar(300) descricao
        nvarchar(3) resposta
        nvarchar(300) observacao
        bigint respondido_por_id FK
        datetime respondido_em
    }
    os_ferramentas {
        bigint id PK
        nvarchar(20) unidade
        nvarchar(30) codigo UK
        nvarchar(150) descricao
        nvarchar(100) marca_modelo
        nvarchar(60) numero_serie
        bigint mantenedor_id FK
        datetime atribuida_em
        nvarchar(500) observacao
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    os_ferramentas_atributos {
        bigint id PK
        bigint ferramenta_id FK
        nvarchar(60) nome
        nvarchar(255) valor
        smallint ordem
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    os_historico {
        bigint id PK
        bigint ordem_servico_id FK
        bigint solicitacao_id FK
        nvarchar(30) acao
        nvarchar(20) status_anterior
        nvarchar(20) status_novo
        nvarchar(1000) observacao
        bigint usuario_id FK
        datetime criado_em
    }
    os_interacao_anexos {
        bigint id PK
        bigint interacao_id FK
        nvarchar(200) nome
        nvarchar(300) caminho
        nvarchar(120) mime
        int tamanho
        datetime criado_em
    }
    os_interacoes {
        bigint id PK
        bigint ordem_servico_id FK
        bigint usuario_id FK
        nvarchar(4000) texto
        datetime criado_em
    }
    os_justificativas_setor {
        bigint id PK
        nvarchar(20) unidade
        nvarchar(120) centro_custo
        date competencia
        nvarchar(1000) texto
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    os_mantenedor_areas {
        bigint id PK
        bigint mantenedor_id FK
        bigint area_executante_id FK
        datetime criado_em
        bigint criado_por_id FK
    }
    os_mantenedores {
        bigint id PK
        nvarchar(20) unidade
        bigint usuario_id FK, UK
        nvarchar(120) nome
        nvarchar(20) matricula
        bigint area_executante_id FK
        nvarchar(20) centro_custo
        nvarchar(5) jornada_inicio
        nvarchar(5) jornada_fim
        smallint intervalo_min
        nvarchar(7) dias_semana
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        bigint calendario_id FK
        nvarchar(200) horarios_semana
    }
    os_materiais {
        bigint id PK
        bigint ordem_servico_id FK
        nvarchar(30) produto_codigo
        nvarchar(200) descricao
        decimal(14, quantidade
        nvarchar(4) um
        nvarchar(12) status
        nvarchar(30) solicitacao_compra
        decimal(18, custo_unitario
        nvarchar(500) observacao
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    os_ordem_mantenedores {
        bigint id PK
        bigint ordem_servico_id FK
        bigint mantenedor_id FK
        datetime criado_em
        bigint criado_por_id FK
    }
    os_planos_itens {
        bigint id PK
        bigint plano_preventiva_id FK
        smallint ordem
        nvarchar(300) descricao
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    os_planos_preventiva {
        bigint id PK
        nvarchar(20) unidade
        nvarchar(20) codigo UK
        nvarchar(200) descricao
        bigint maquina_id FK
        bigint area_executante_id FK
        bigint responsavel_id FK
        nvarchar(10) prioridade
        nvarchar(10) periodicidade
        nvarchar(12) meses
        smallint dia_mes
        date proxima_em
        smallint antecedencia_dias
        int duracao_prevista_min
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    os_recusas_aplicativo {
        bigint id PK
        bigint solicitacao_id FK
        bigint usuario_id FK
        datetime criado_em
    }
    os_sugestoes_descricao {
        bigint id PK
        nvarchar(20) unidade
        nvarchar(200) texto
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    paradas {
        bigint id PK
        bigint maquina_id FK
        bigint motivo_id FK
        datetime inicio
        datetime fim
        nvarchar(500) observacao
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    pedidos_compra {
        bigint id PK
        nvarchar(20) unidade
        bigint solicitacao_material_id FK
        bigint ordem_servico_id FK
        nvarchar(8) filial
        nvarchar(20) centro_custo_origem
        nvarchar(20) centro_custo_destino
        nvarchar(500) observacao
        nvarchar(25) status
        nvarchar(10) numero_pedido
        nvarchar(1000) mensagem
        datetime integrado_em
        nvarchar(120) aprovador
        datetime aprovado_em
        datetime concluido_em
        decimal(18, valor_total
        datetime consultado_em
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        nvarchar(12) tipo
    }
    pedidos_compra_itens {
        bigint id PK
        bigint pedido_compra_id FK
        bigint solicitacao_material_item_id FK
        nvarchar(4) item
        nvarchar(30) produto_codigo
        nvarchar(120) produto_descricao
        nvarchar(4) um
        decimal(14, quantidade
        nvarchar(250) observacao
        decimal(18, valor_unitario
        decimal(18, valor_total
        decimal(14, quantidade_entregue
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        nvarchar(10) pedido_gerado
    }
    perdas_op {
        bigint id PK
        bigint ordem_producao_id FK
        bigint maquina_id FK
        date dia
        decimal(18, quantidade
        decimal(18, peso
        decimal(18, peso_unitario
        nvarchar(12) origem
        nvarchar(500) observacao
        datetime excluido_em
        bigint excluido_por_id FK
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        tinyint turno
    }
    plantas {
        bigint id PK
        nvarchar(20) codigo UK
        nvarchar(120) nome
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        nvarchar(20) unidade
    }
    produtos {
        bigint id PK
        nvarchar(40) codigo UK
        nvarchar(200) descricao
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    produtos_protheus {
        bigint id PK
        nvarchar(8) b1_filial
        nvarchar(30) b1_cod
        nvarchar(120) b1_desc
        nvarchar(2) b1_tipo
        nvarchar(2) b1_um
        nvarchar(10) b1_grupo
        nchar(1) b1_msblql
        nchar(1) d_e_l_e_t_
        int r_e_c_n_o_
        datetime sincronizado_em
        float(53) b1_qb
        float(53) b1_peso
    }
    registros_login {
        bigint id PK
        bigint usuario_id FK
        nvarchar(320) email
        datetime data_hora
        bit sucesso
        nvarchar(200) motivo
        nvarchar(45) ip
        nvarchar(400) navegador
    }
    roteiros_operacao {
        bigint id PK
        nvarchar(8) g2_filial
        nvarchar(30) g2_produto
        nvarchar(4) g2_codigo
        nvarchar(4) g2_operac
        nvarchar(120) g2_descri
        nvarchar(10) g2_recurso
        nvarchar(10) g2_ctrab
        nvarchar(10) g2_ferram
        nchar(1) g2_tpoper
        float(53) g2_setup
        float(53) g2_tempad
        float(53) g2_lotepad
        float(53) g2_maoobra
        nchar(1) d_e_l_e_t_
        int r_e_c_n_o_
        datetime sincronizado_em
    }
    sincronizacoes {
        bigint id PK
        nvarchar(40) chave
        nvarchar(160) origem
        nvarchar(20) disparo
        bigint usuario_id FK
        datetime iniciado_em
        datetime terminado_em
        nvarchar(20) situacao
        int lidos
        int novos
        int atualizados
        int ignorados
        nvarchar(1000) mensagem
        int excluidos
        datetime sinal_em
        bigint ultimo_recno
    }
    solicitacoes_material {
        bigint id PK
        nvarchar(20) unidade
        bigint solicitante_id FK
        nvarchar(12) status
        nvarchar(1000) observacao
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    solicitacoes_material_itens {
        bigint id PK
        bigint solicitacao_material_id FK
        nvarchar(30) produto_codigo
        nvarchar(120) produto_descricao
        nvarchar(4) um
        nvarchar(20) centro_custo
        decimal(14, quantidade_solicitada
        nvarchar(10) prioridade
        nvarchar(1000) observacao
        nvarchar(12) status
        decimal(14, quantidade_aprovada
        nvarchar(500) motivo_reprovacao
        bigint aprovado_por_id FK
        datetime aprovado_em
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    solicitacoes_servico {
        bigint id PK
        nvarchar(20) unidade
        bigint solicitante_id FK
        nvarchar(15) tipo
        bigint maquina_id FK
        bigint area_executante_id FK
        nvarchar(20) centro_custo
        nvarchar(120) centro_custo_solicitante
        nvarchar(10) prioridade
        bit maquina_parada
        nvarchar(2000) descricao
        nvarchar(12) status
        bigint analisado_por_id FK
        datetime analisado_em
        nvarchar(500) motivo_rejeicao
        bigint apontamento_estado_id FK
        bigint ordem_producao_id FK
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
        nvarchar(120) titulo
        bit risco_integridade
        bit automatica
        nvarchar(120) origem_iot
        bigint ferramenta_id FK
    }
    tokens_aplicativo {
        bigint id PK
        bigint usuario_id FK
        nchar(64) token_hash UK
        nvarchar(120) dispositivo
        nvarchar(20) versao_app
        datetime criado_em
        datetime ultimo_uso_em
        datetime revogado_em
    }
    turnos {
        bigint id PK
        nvarchar(60) nome UK
        time inicio
        time fim
        bit ativo
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    usuario_perfis {
        bigint id PK
        bigint usuario_id FK
        nvarchar(30) perfil
        datetime criado_em
        bigint criado_por_id FK
    }
    usuarios {
        bigint id PK
        nvarchar(36) entra_oid UK
        nvarchar(36) entra_tenant_id
        nvarchar(200) nome
        nvarchar(320) email
        nvarchar(20) perfil
        bit ativo
        datetime ultimo_login_em
        datetime criado_em
        datetime atualizado_em
        nvarchar(120) setor
        nvarchar(120) cargo
        nvarchar(320) email_contato
        nvarchar(40) celular
        nvarchar(60) matricula
        nvarchar(255) foto
        datetime foto_atualizada_em
        datetime perfil_entra_em
        nvarchar(120) departamento
        nvarchar(120) local_escritorio
        nvarchar(200) gerente_nome
        nvarchar(320) gerente_email
        nvarchar(25) usuario_protheus
        nvarchar(20) id_protheus
    }
    versoes_aplicativo {
        bigint id PK
        int codigo UK
        nvarchar(40) nome
        nvarchar(255) arquivo
        bigint tamanho
        nvarchar(64) sha256
        bit obrigatoria
        bit ativo
        nvarchar(1000) observacao
        nvarchar(20) unidade
        datetime criado_em
        bigint criado_por_id FK
        datetime atualizado_em
        bigint atualizado_por_id FK
    }
    maquinas ||--o{ apontamentos : "maquina_id"
    turnos |o--o{ apontamentos : "turno_id"
    produtos |o--o{ apontamentos : "produto_id"
    maquinas ||--o{ apontamentos_estado : "maquina_id"
    legendas ||--o{ apontamentos_estado : "legenda_id"
    ordens_producao |o--o{ apontamentos_estado : "ordem_producao_id"
    dispositivos_iot |o--o{ apontamentos_estado : "iot_dispositivo_id"
    solicitacoes_servico |o--o{ apontamentos_estado : "solicitacao_vinculada_id"
    usuarios ||--o{ assistente_conversas : "usuario_id"
    assistente_conversas ||--o{ assistente_perguntas : "conversa_id"
    usuarios ||--o{ assistente_perguntas : "usuario_id"
    calendarios ||--o{ calendario_dias : "calendario_id"
    maquinas |o--o{ dispositivos_iot : "maquina_id"
    usuarios ||--o{ filtros_usuarios : "usuario_id"
    grupos ||--o{ grupo_permissoes : "grupo_id"
    grupos ||--o{ grupo_usuario : "grupo_id"
    usuarios ||--o{ grupo_usuario : "usuario_id"
    os_areas_executantes |o--o{ legendas : "abre_ss_area_executante_id"
    plantas ||--o{ linhas : "planta_id"
    linhas ||--o{ maquinas : "linha_id"
    dispositivos_iot |o--o{ maquinas : "iot1_dispositivo_id"
    dispositivos_iot |o--o{ maquinas : "iot2_dispositivo_id"
    dispositivos_iot |o--o{ maquinas : "iot3_dispositivo_id"
    legendas |o--o{ maquinas : "situacao_legenda_id"
    ordens_producao |o--o{ maquinas : "ordem_producao_id"
    calendarios |o--o{ maquinas : "calendario_id"
    maquinas ||--o{ metas_producao_horas : "maquina_id"
    ordens_producao ||--o{ metas_producao_horas : "ordem_producao_id"
    solicitacoes_servico |o--o{ ordens_servico : "solicitacao_id"
    os_planos_preventiva |o--o{ ordens_servico : "plano_preventiva_id"
    maquinas |o--o{ ordens_servico : "maquina_id"
    os_areas_executantes ||--o{ ordens_servico : "area_executante_id"
    usuarios |o--o{ ordens_servico : "solicitante_id"
    usuarios |o--o{ ordens_servico : "aprovado_por_id"
    os_mantenedores |o--o{ ordens_servico : "responsavel_id"
    usuarios |o--o{ ordens_servico : "aceite_por_id"
    ordens_producao |o--o{ ordens_servico : "ordem_producao_id"
    apontamentos_estado |o--o{ ordens_servico : "apontamento_estado_id"
    ferramentas |o--o{ ordens_servico : "ferramenta_id"
    ordens_servico ||--o{ os_apontamentos_horas : "ordem_servico_id"
    os_mantenedores ||--o{ os_apontamentos_horas : "mantenedor_id"
    ordens_servico ||--o{ os_assinaturas : "ordem_servico_id"
    usuarios |o--o{ os_assinaturas : "assinado_por_id"
    usuarios |o--o{ os_assinaturas : "registrado_por_id"
    ordens_servico ||--o{ os_checklist : "ordem_servico_id"
    os_planos_itens |o--o{ os_checklist : "plano_item_id"
    usuarios |o--o{ os_checklist : "respondido_por_id"
    os_mantenedores |o--o{ os_ferramentas : "mantenedor_id"
    os_ferramentas ||--o{ os_ferramentas_atributos : "ferramenta_id"
    ordens_servico |o--o{ os_historico : "ordem_servico_id"
    solicitacoes_servico |o--o{ os_historico : "solicitacao_id"
    usuarios |o--o{ os_historico : "usuario_id"
    os_interacoes ||--o{ os_interacao_anexos : "interacao_id"
    ordens_servico ||--o{ os_interacoes : "ordem_servico_id"
    usuarios ||--o{ os_interacoes : "usuario_id"
    os_mantenedores ||--o{ os_mantenedor_areas : "mantenedor_id"
    os_areas_executantes ||--o{ os_mantenedor_areas : "area_executante_id"
    usuarios |o--o{ os_mantenedores : "usuario_id"
    os_areas_executantes |o--o{ os_mantenedores : "area_executante_id"
    calendarios |o--o{ os_mantenedores : "calendario_id"
    ordens_servico ||--o{ os_materiais : "ordem_servico_id"
    ordens_servico ||--o{ os_ordem_mantenedores : "ordem_servico_id"
    os_mantenedores ||--o{ os_ordem_mantenedores : "mantenedor_id"
    os_planos_preventiva ||--o{ os_planos_itens : "plano_preventiva_id"
    maquinas |o--o{ os_planos_preventiva : "maquina_id"
    os_areas_executantes ||--o{ os_planos_preventiva : "area_executante_id"
    os_mantenedores |o--o{ os_planos_preventiva : "responsavel_id"
    solicitacoes_servico ||--o{ os_recusas_aplicativo : "solicitacao_id"
    usuarios ||--o{ os_recusas_aplicativo : "usuario_id"
    maquinas ||--o{ paradas : "maquina_id"
    motivos_parada |o--o{ paradas : "motivo_id"
    solicitacoes_material |o--o{ pedidos_compra : "solicitacao_material_id"
    ordens_servico |o--o{ pedidos_compra : "ordem_servico_id"
    pedidos_compra ||--o{ pedidos_compra_itens : "pedido_compra_id"
    solicitacoes_material_itens |o--o{ pedidos_compra_itens : "solicitacao_material_item_id"
    ordens_producao ||--o{ perdas_op : "ordem_producao_id"
    maquinas |o--o{ perdas_op : "maquina_id"
    usuarios |o--o{ perdas_op : "excluido_por_id"
    usuarios |o--o{ registros_login : "usuario_id"
    usuarios |o--o{ sincronizacoes : "usuario_id"
    usuarios ||--o{ solicitacoes_material : "solicitante_id"
    solicitacoes_material ||--o{ solicitacoes_material_itens : "solicitacao_material_id"
    usuarios |o--o{ solicitacoes_material_itens : "aprovado_por_id"
    usuarios ||--o{ solicitacoes_servico : "solicitante_id"
    maquinas |o--o{ solicitacoes_servico : "maquina_id"
    os_areas_executantes ||--o{ solicitacoes_servico : "area_executante_id"
    usuarios |o--o{ solicitacoes_servico : "analisado_por_id"
    apontamentos_estado |o--o{ solicitacoes_servico : "apontamento_estado_id"
    ordens_producao |o--o{ solicitacoes_servico : "ordem_producao_id"
    ferramentas |o--o{ solicitacoes_servico : "ferramenta_id"
    usuarios ||--o{ tokens_aplicativo : "usuario_id"
    usuarios ||--o{ usuario_perfis : "usuario_id"
    centros_custo |o..o{ maquinas : "centro_custo (código)"
    produtos_protheus |o..o{ ordens_producao : "c2_produto (código)"
    centros_custo |o..o{ ordens_producao : "c2_cc (código)"
    ordens_producao |o..o{ movimentos_producao : "op_numero (código)"
    produtos_protheus |o..o{ movimentos_producao : "d3_cod (código)"
    ordens_producao |o..o{ notas_fiscais_op : "op_numero (código)"
    produtos_protheus |o..o{ notas_fiscais_op : "d2_cod (código)"
    produtos_protheus |o..o{ estruturas_produto : "g1_cod (código)"
    produtos_protheus |o..o{ estruturas_produto : "g1_comp (código)"
    produtos_protheus |o..o{ roteiros_operacao : "g2_produto (código)"
    maquinas |o..o{ roteiros_operacao : "g2_ctrab (código)"
    ferramentas |o..o{ roteiros_operacao : "g2_ferram (código)"
    centros_custo |o..o{ os_mantenedores : "centro_custo (código)"
    centros_custo |o..o{ solicitacoes_servico : "centro_custo (código)"
    centros_custo |o..o{ ordens_servico : "centro_custo (código)"
    produtos_protheus |o..o{ os_materiais : "produto_codigo (código)"
```

## Decisões e sugestões de modelagem

<!-- manual:inicio -->
Mantido pelo agente `arquiteto-banco-dados`. Cada item diz se já foi aplicado ou se é só sugestão.

**Convenções em uso**

- Chave primária: `id` `bigint identity` em toda tabela; tabelas de ligação (`grupo_usuario`, `grupo_permissoes`) usam
  chave composta pelas duas colunas.
- Chave estrangeira: `<tabela_no_singular>_id` → `<tabela>.id`, sem exclusão em cascata (cadastros são desativados).
- Índices: toda FK usada em filtro tem índice; dados de produção (`apontamentos`, `apontamentos_estado`, `paradas`) têm
  índice composto `(maquina_id, inicio)`, que é o filtro de todas as telas de produção.
- Tabelas do Protheus (`centros_custo`) mantêm os nomes e tipos da origem (`ctt_*`, `d_e_l_e_t_`, `r_e_c_n_o_`) e a
  chave natural do Protheus como índice único (`ctt_filial`, `ctt_custo`).
- **Decisão (aplicada): `maquinas` é tabela mista com `Compras.SHB`.** Código (`HB_COD`), nome (`HB_NOME`) e centro de
  custo (`HB_CC`) vêm do Protheus só na primeira sincronização; a máquina chega inativa e sem linha (`linha_id` passou a
  aceitar vazio). Guarda `hb_filial`, `r_e_c_n_o_` e `d_e_l_e_t_` de origem (`r_e_c_n_o_` preenchido = veio do
  Protheus). Depois só a exclusão no Protheus altera a máquina (marca `d_e_l_e_t_ = '*'` e desativa). Regras no SQL
  Server: máquina ativa precisa de linha (`ck_maquinas_ativa_com_linha`) e máquina excluída no Protheus não fica ativa
  (`ck_maquinas_ativa_nao_excluida`).

**Sugestões (ainda não aplicadas)**

1. `maquinas.centro_custo` guarda só o código (sem filial e sem FK), como vem em `HB_CC` da SHB. Se a CTT tiver o
   mesmo código em filiais diferentes, a ligação fica ambígua. Sugestão: usar também `maquinas.hb_filial` para achar o
   centro de custo da mesma filial (ou o compartilhado), ou trocar por `centro_custo_id` → `centros_custo.id`.
2. `maquinas.iot1_* .. iot3_*` repete o mesmo trio de colunas três vezes e não se liga a `dispositivos_iot`. Sugestão:
   tabela `maquina_canais_iot` (`maquina_id`, `posicao`, `dispositivo_iot_id`, `canal`, `tipo`) com PK
   (`maquina_id`, `posicao`) — permite mais de 3 canais e FK para o dispositivo.
3. Há dois modelos de parada em paralelo: `paradas` + `motivos_parada` e `apontamentos_estado` + `legendas`. Vale
   decidir qual é o oficial para o cálculo de OEE e migrar o outro, para não haver duas fontes de verdade.
4. `apontamentos_estado` não tem `turno_id`; o turno é deduzido pelo horário. Se o turno puder mudar de horário com o
   tempo, gravar o `turno_id` no apontamento (como em `apontamentos`) preserva o histórico.
<!-- manual:fim -->

## Tabelas

| Tabela | Origem | Chave primária | Colunas | Ligações (FK) |
|---|---|---|---|---|
| [`agendas_sincronizacao`](#agendas_sincronizacao) | Sistema | `id` | 10 | 2 |
| [`apontamentos`](#apontamentos) | Sistema | `id` | 13 | 5 |
| [`apontamentos_estado`](#apontamentos_estado) | Sistema | `id` | 20 | 7 |
| [`assistente_conversas`](#assistente_conversas) | Sistema | `id` | 6 | 1 |
| [`assistente_perguntas`](#assistente_perguntas) | Sistema | `id` | 16 | 2 |
| [`cache`](#cache) | Infra | `key` | 3 | 0 |
| [`cache_locks`](#cache_locks) | Infra | `key` | 3 | 0 |
| [`calendario_dias`](#calendario_dias) | Sistema | `id` | 9 | 3 |
| [`calendarios`](#calendarios) | Sistema | `id` | 10 | 2 |
| [`centros_custo`](#centros_custo) | Protheus | `id` | 16 | 2 |
| [`dispositivos_iot`](#dispositivos_iot) | Sistema | `id` | 17 | 3 |
| [`empenhos_op`](#empenhos_op) | Protheus | `id` | 15 | 0 |
| [`estruturas_produto`](#estruturas_produto) | Protheus | `id` | 15 | 0 |
| [`failed_jobs`](#failed_jobs) | Infra | `id` | 7 | 0 |
| [`faltas_produto`](#faltas_produto) | Protheus | `id` | 20 | 0 |
| [`ferramentas`](#ferramentas) | Mista | `id` | 18 | 2 |
| [`filtros_usuarios`](#filtros_usuarios) | Sistema | `id` | 8 | 1 |
| [`grupo_permissoes`](#grupo_permissoes) | Sistema | `grupo_id, permissao` | 2 | 1 |
| [`grupo_usuario`](#grupo_usuario) | Sistema | `grupo_id, usuario_id` | 2 | 2 |
| [`grupos`](#grupos) | Sistema | `id` | 8 | 2 |
| [`job_batches`](#job_batches) | Infra | `id` | 10 | 0 |
| [`jobs`](#jobs) | Infra | `id` | 7 | 0 |
| [`legendas`](#legendas) | Sistema | `id` | 16 | 3 |
| [`linhas`](#linhas) | Sistema | `id` | 10 | 3 |
| [`maos_de_obra`](#maos_de_obra) | Sistema | `id` | 8 | 2 |
| [`maquinas`](#maquinas) | Mista | `id` | 45 | 9 |
| [`metas_producao`](#metas_producao) | Sistema | `id` | 10 | 0 |
| [`metas_producao_horas`](#metas_producao_horas) | Sistema | `id` | 10 | 2 |
| [`motivos_parada`](#motivos_parada) | Sistema | `id` | 10 | 2 |
| [`movimentos_producao`](#movimentos_producao) | Protheus | `id` | 20 | 0 |
| [`notas_fiscais_op`](#notas_fiscais_op) | Protheus | `id` | 20 | 0 |
| [`ordens_producao`](#ordens_producao) | Protheus | `id` | 30 | 0 |
| [`ordens_servico`](#ordens_servico) | Sistema | `id` | 37 | 13 |
| [`os_apontamentos_horas`](#os_apontamentos_horas) | Sistema | `id` | 12 | 4 |
| [`os_areas_executantes`](#os_areas_executantes) | Sistema | `id` | 11 | 2 |
| [`os_assinaturas`](#os_assinaturas) | Sistema | `id` | 8 | 3 |
| [`os_checklist`](#os_checklist) | Sistema | `id` | 9 | 3 |
| [`os_ferramentas`](#os_ferramentas) | Sistema | `id` | 14 | 3 |
| [`os_ferramentas_atributos`](#os_ferramentas_atributos) | Sistema | `id` | 9 | 3 |
| [`os_historico`](#os_historico) | Sistema | `id` | 9 | 3 |
| [`os_interacao_anexos`](#os_interacao_anexos) | Sistema | `id` | 7 | 1 |
| [`os_interacoes`](#os_interacoes) | Sistema | `id` | 5 | 2 |
| [`os_justificativas_setor`](#os_justificativas_setor) | Sistema | `id` | 9 | 2 |
| [`os_mantenedor_areas`](#os_mantenedor_areas) | Sistema | `id` | 5 | 3 |
| [`os_mantenedores`](#os_mantenedores) | Sistema | `id` | 18 | 5 |
| [`os_materiais`](#os_materiais) | Sistema | `id` | 14 | 3 |
| [`os_ordem_mantenedores`](#os_ordem_mantenedores) | Sistema | `id` | 5 | 3 |
| [`os_planos_itens`](#os_planos_itens) | Sistema | `id` | 9 | 3 |
| [`os_planos_preventiva`](#os_planos_preventiva) | Sistema | `id` | 19 | 5 |
| [`os_recusas_aplicativo`](#os_recusas_aplicativo) | Sistema | `id` | 4 | 2 |
| [`os_sugestoes_descricao`](#os_sugestoes_descricao) | Sistema | `id` | 8 | 2 |
| [`paradas`](#paradas) | Sistema | `id` | 10 | 4 |
| [`pedidos_compra`](#pedidos_compra) | Sistema | `id` | 22 | 4 |
| [`pedidos_compra_itens`](#pedidos_compra_itens) | Sistema | `id` | 17 | 4 |
| [`perdas_op`](#perdas_op) | Sistema | `id` | 16 | 5 |
| [`plantas`](#plantas) | Sistema | `id` | 9 | 2 |
| [`produtos`](#produtos) | Sistema | `id` | 8 | 2 |
| [`produtos_protheus`](#produtos_protheus) | Protheus | `id` | 13 | 0 |
| [`registros_login`](#registros_login) | Sistema | `id` | 8 | 1 |
| [`roteiros_operacao`](#roteiros_operacao) | Protheus | `id` | 17 | 0 |
| [`sessions`](#sessions) | Infra | `id` | 6 | 0 |
| [`sincronizacoes`](#sincronizacoes) | Sistema | `id` | 16 | 1 |
| [`solicitacoes_material`](#solicitacoes_material) | Sistema | `id` | 9 | 3 |
| [`solicitacoes_material_itens`](#solicitacoes_material_itens) | Sistema | `id` | 18 | 4 |
| [`solicitacoes_servico`](#solicitacoes_servico) | Sistema | `id` | 26 | 9 |
| [`tokens_aplicativo`](#tokens_aplicativo) | Sistema | `id` | 8 | 1 |
| [`turnos`](#turnos) | Sistema | `id` | 9 | 2 |
| [`usuario_perfis`](#usuario_perfis) | Sistema | `id` | 5 | 2 |
| [`usuarios`](#usuarios) | Sistema | `id` | 24 | 0 |
| [`versoes_aplicativo`](#versoes_aplicativo) | Sistema | `id` | 14 | 2 |

### agendas_sincronizacao

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `chave` | `nvarchar(40)` | não |  |  |
| `ativo` | `bit` | não | `'0'` |  |
| `intervalo_minutos` | `int` | não | `'60'` |  |
| `inicio` | `nchar(5)` | não | `'00:00'` |  |
| `ultimo_horario` | `datetime` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `agendas_sincronizacao_chave_unique` único (chave)

### apontamentos

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `maquina_id` | `bigint` | não |  | FK → `maquinas.id` |
| `turno_id` | `bigint` | sim |  | FK → `turnos.id` |
| `produto_id` | `bigint` | sim |  | FK → `produtos.id` |
| `inicio` | `datetime` | não |  |  |
| `fim` | `datetime` | não |  |  |
| `ciclo_ideal_s` | `float(53)` | não |  |  |
| `total_produzido` | `int` | não |  |  |
| `pecas_boas` | `int` | não |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `apontamentos_maquina_id_inicio_index` (maquina_id, inicio)

**Regras (CHECK, só no SQL Server):** `ck_apontamentos_periodo`: `fim > inicio` · `ck_apontamentos_pecas`: `pecas_boas >= 0 AND pecas_boas <= total_produzido`

### apontamentos_estado

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `maquina_id` | `bigint` | não |  | FK → `maquinas.id` |
| `legenda_id` | `bigint` | não |  | FK → `legendas.id` |
| `inicio` | `datetime` | não |  |  |
| `fim` | `datetime` | sim |  |  |
| `referencia` | `nvarchar(40)` | sim |  |  |
| `observacao` | `nvarchar(500)` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `ordem_producao_id` | `bigint` | sim |  | FK → `ordens_producao.id` |
| `ciclo_medio` | `smallint` | sim |  |  |
| `iot_dispositivo_id` | `bigint` | sim |  | FK → `dispositivos_iot.id` |
| `iot_canal` | `nvarchar(20)` | sim |  |  |
| `solicitacao_vinculada_id` | `bigint` | sim |  | FK → `solicitacoes_servico.id` |
| `ciclos` | `int` | sim |  |  |
| `cavidades` | `smallint` | sim |  |  |
| `pecas` | `int` | sim |  |  |
| `ciclo_medio_ms` | `int` | sim |  |  |

**Índices:** `apontamentos_estado_maquina_id_inicio_index` (maquina_id, inicio) · `apontamentos_estado_ordem_producao_id_index` (ordem_producao_id) · `ix_apontamentos_estado_inicio` (inicio) · `ix_apontamentos_estado_legenda_inicio` (legenda_id, inicio)

**Regras (CHECK, só no SQL Server):** `ck_apontamentos_estado_periodo`: `fim IS NULL OR fim > inicio` · `ck_apontamentos_estado_ciclo_medio`: `ciclo_medio IS NULL OR (ciclo_medio >= 0 AND ciclo_medio <= 999)` · `ck_apontamentos_estado_ciclo_medio_ms`: `ciclo_medio_ms IS NULL OR ciclo_medio_ms >= 0`

### assistente_conversas

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `usuario_id` | `bigint` | não |  | FK → `usuarios.id` |
| `titulo` | `nvarchar(120)` | sim |  |  |
| `mensagens` | `nvarchar(max)` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `atualizado_em` | `datetime` | sim |  |  |

**Índices:** `assistente_conversas_usuario_id_atualizado_em_index` (usuario_id, atualizado_em)

### assistente_perguntas

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `conversa_id` | `bigint` | não |  | FK → `assistente_conversas.id` |
| `usuario_id` | `bigint` | não |  | FK → `usuarios.id` |
| `pergunta` | `nvarchar(2000)` | não |  |  |
| `posicao` | `int` | não | `'0'` |  |
| `situacao` | `nvarchar(12)` | não | `'pendente'` |  |
| `etapas` | `smallint` | não | `'0'` |  |
| `ferramentas` | `nvarchar(1000)` | sim |  |  |
| `modelo` | `nvarchar(60)` | sim |  |  |
| `tokens_entrada` | `int` | não | `'0'` |  |
| `tokens_saida` | `int` | não | `'0'` |  |
| `tokens_cache_leitura` | `int` | não | `'0'` |  |
| `tokens_cache_escrita` | `int` | não | `'0'` |  |
| `erro` | `nvarchar(1000)` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `concluida_em` | `datetime` | sim |  |  |

**Índices:** `assistente_perguntas_usuario_id_criado_em_index` (usuario_id, criado_em)

### cache

**Origem:** Infraestrutura do Laravel · **Chave primária:** `key`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `key` | `nvarchar(255)` | não |  | PK |
| `value` | `nvarchar(max)` | não |  |  |
| `expiration` | `bigint` | não |  |  |

**Índices:** `cache_expiration_index` (expiration)

### cache_locks

**Origem:** Infraestrutura do Laravel · **Chave primária:** `key`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `key` | `nvarchar(255)` | não |  | PK |
| `owner` | `nvarchar(255)` | não |  |  |
| `expiration` | `bigint` | não |  |  |

**Índices:** `cache_locks_expiration_index` (expiration)

### calendario_dias

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `calendario_id` | `bigint` | não |  | FK → `calendarios.id` |
| `data` | `date` | não |  |  |
| `tipo` | `nvarchar(12)` | não |  |  |
| `descricao` | `nvarchar(120)` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `calendario_dias_calendario_id_data_unique` único (calendario_id, data)

**Regras (CHECK, só no SQL Server):** `ck_calendario_dias_tipo`: `tipo IN ('feriado', 'ponte', 'folga', 'compensacao', 'util')`

### calendarios

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |
| `nome` | `nvarchar(80)` | não |  |  |
| `ano` | `smallint` | não |  |  |
| `descricao` | `nvarchar(300)` | sim |  |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `calendarios_unidade_nome_ano_unique` único (unidade, nome, ano) · `calendarios_unidade_ano_index` (unidade, ano)

**Regras (CHECK, só no SQL Server):** `ck_calendarios_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')`

### centros_custo

**Origem:** Protheus — sincronizada de `Compras.CTT` · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `ctt_filial` | `nchar(8)` | não | `''` |  |
| `ctt_custo` | `nvarchar(20)` | não |  |  |
| `ctt_desc01` | `nvarchar(40)` | não |  |  |
| `ctt_classe` | `nchar(1)` | não | `'2'` |  |
| `ctt_bloq` | `nchar(1)` | não | `'2'` |  |
| `ctt_ccsup` | `nvarchar(20)` | não | `''` |  |
| `ctt_dtexis` | `nchar(8)` | não | `''` |  |
| `ctt_dtexsf` | `nchar(8)` | não | `''` |  |
| `d_e_l_e_t_` | `nchar(1)` | não | `' '` |  |
| `r_e_c_n_o_` | `int` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |

**Índices:** `centros_custo_ctt_filial_ctt_custo_unique` único (ctt_filial, ctt_custo) · `centros_custo_ctt_custo_index` (ctt_custo) · `centros_custo_unidade_index` (unidade)

**Regras (CHECK, só no SQL Server):** `ck_centros_custo_classe`: `ctt_classe IN ('1', '2')` · `ck_centros_custo_bloq`: `ctt_bloq IN ('1', '2')` · `ck_centros_custo_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')`

### dispositivos_iot

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `codigo` | `nvarchar(40)` | não |  |  |
| `nome` | `nvarchar(120)` | não |  |  |
| `tipo` | `nvarchar(20)` | não |  |  |
| `protocolo` | `nvarchar(20)` | não |  |  |
| `endereco` | `nvarchar(200)` | sim |  |  |
| `porta` | `int` | sim |  |  |
| `topico` | `nvarchar(250)` | sim |  |  |
| `maquina_id` | `bigint` | sim |  | FK → `maquinas.id` |
| `ultimo_sinal_em` | `datetime` | sim |  |  |
| `observacao` | `nvarchar(500)` | sim |  |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |

**Índices:** `dispositivos_iot_codigo_unique` único (codigo) · `dispositivos_iot_maquina_id_index` (maquina_id) · `dispositivos_iot_unidade_index` (unidade)

**Regras (CHECK, só no SQL Server):** `ck_dispositivos_iot_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')`

### empenhos_op

**Origem:** Protheus — sincronizada de `Compras.SD4` · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `d4_filial` | `nvarchar(8)` | não | `''` |  |
| `d4_cod` | `nvarchar(30)` | não | `''` |  |
| `d4_op` | `nvarchar(20)` | não | `''` |  |
| `op_numero` | `nvarchar(20)` | não | `''` |  |
| `d4_local` | `nvarchar(4)` | não | `''` |  |
| `d4_data` | `nchar(8)` | não | `''` |  |
| `d4_qtdeori` | `float(53)` | não | `'0'` |  |
| `d4_quant` | `float(53)` | não | `'0'` |  |
| `d4_trt` | `nvarchar(3)` | não | `''` |  |
| `d4_lotectl` | `nvarchar(20)` | não | `''` |  |
| `d4_oporig` | `nvarchar(20)` | não | `''` |  |
| `d_e_l_e_t_` | `nchar(1)` | não | `' '` |  |
| `r_e_c_n_o_` | `int` | não |  |  |
| `sincronizado_em` | `datetime` | sim |  |  |

**Índices:** `empenhos_op_r_e_c_n_o__unique` único (r_e_c_n_o_) · `empenhos_op_op_numero_d4_filial_index` (op_numero, d4_filial) · `empenhos_op_d4_cod_index` (d4_cod)

### estruturas_produto

**Origem:** Protheus — sincronizada de `Compras.SG1` · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `g1_filial` | `nvarchar(8)` | não | `''` |  |
| `g1_cod` | `nvarchar(30)` | não |  | código → `produtos_protheus.b1_cod` (sem FK) |
| `g1_comp` | `nvarchar(30)` | não | `''` | código → `produtos_protheus.b1_cod` (sem FK) |
| `g1_trt` | `nvarchar(3)` | não | `''` |  |
| `g1_quant` | `float(53)` | não | `'0'` |  |
| `g1_perda` | `float(53)` | não | `'0'` |  |
| `g1_ini` | `nchar(8)` | não | `''` |  |
| `g1_fim` | `nchar(8)` | não | `''` |  |
| `g1_fixvar` | `nchar(1)` | não | `''` |  |
| `g1_revini` | `nvarchar(3)` | não | `''` |  |
| `g1_revfim` | `nvarchar(3)` | não | `''` |  |
| `d_e_l_e_t_` | `nchar(1)` | não | `' '` |  |
| `r_e_c_n_o_` | `int` | sim |  |  |
| `sincronizado_em` | `datetime` | sim |  |  |

**Índices:** `estruturas_produto_g1_filial_g1_cod_g1_comp_g1_trt_unique` único (g1_filial, g1_cod, g1_comp, g1_trt) · `estruturas_produto_g1_cod_index` (g1_cod) · `estruturas_produto_g1_comp_index` (g1_comp)

### failed_jobs

**Origem:** Infraestrutura do Laravel · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `uuid` | `nvarchar(255)` | não |  |  |
| `connection` | `nvarchar(255)` | não |  |  |
| `queue` | `nvarchar(255)` | não |  |  |
| `payload` | `nvarchar(max)` | não |  |  |
| `exception` | `nvarchar(max)` | não |  |  |
| `failed_at` | `datetime` | não | `CURRENT_TIMESTAMP` |  |

**Índices:** `failed_jobs_uuid_unique` único (uuid) · `failed_jobs_connection_queue_failed_at_index` (connection, queue, failed_at)

### faltas_produto

**Origem:** Protheus — sincronizada de `Compras.ZB5` · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `zb5_filial` | `nvarchar(6)` | não | `''` |  |
| `zb5_doc` | `nvarchar(10)` | não | `''` |  |
| `zb5_produt` | `nvarchar(16)` | não | `''` |  |
| `zb5_qtdsol` | `float(53)` | não | `'0'` |  |
| `zb5_qtdate` | `float(53)` | não | `'0'` |  |
| `zb5_pedido` | `nvarchar(6)` | não | `''` |  |
| `zb5_vlped` | `float(53)` | não | `'0'` |  |
| `zb5_solici` | `nvarchar(19)` | não | `''` |  |
| `zb5_dtsoli` | `nchar(8)` | não | `''` |  |
| `zb5_horini` | `nvarchar(8)` | não | `''` |  |
| `zb5_dtaten` | `nchar(8)` | não | `''` |  |
| `zb5_horfim` | `nvarchar(8)` | não | `''` |  |
| `zb5_atendi` | `nvarchar(19)` | não | `''` |  |
| `zb5_status` | `nchar(1)` | não | `'P'` |  |
| `solicitado_em` | `datetime` | sim |  |  |
| `atendido_em` | `datetime` | sim |  |  |
| `d_e_l_e_t_` | `nchar(1)` | não | `' '` |  |
| `r_e_c_n_o_` | `int` | não |  |  |
| `sincronizado_em` | `datetime` | sim |  |  |

**Índices:** `faltas_produto_r_e_c_n_o__unique` único (r_e_c_n_o_) · `faltas_produto_zb5_produt_index` (zb5_produt) · `faltas_produto_zb5_status_solicitado_em_index` (zb5_status, solicitado_em) · `faltas_produto_atendido_em_index` (atendido_em)

### ferramentas

**Origem:** Mista — parte dos campos vem de `Compras.SH4` (Protheus), o resto é do Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `h4_filial` | `nvarchar(8)` | não | `''` |  |
| `h4_codigo` | `nvarchar(10)` | não |  |  |
| `h4_descri` | `nvarchar(120)` | não | `''` |  |
| `h4_quant` | `float(53)` | não | `'0'` |  |
| `h4_vidauti` | `float(53)` | não | `'0'` |  |
| `h4_tipovid` | `nchar(1)` | não | `''` |  |
| `h4_qtdcav` | `int` | não | `'0'` |  |
| `h4_qtdcavativas` | `int` | não | `'0'` |  |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |
| `d_e_l_e_t_` | `nchar(1)` | não | `' '` |  |
| `r_e_c_n_o_` | `int` | sim |  |  |
| `sincronizado_em` | `datetime` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `disponivel` | `bit` | não | `'1'` |  |

**Índices:** `ferramentas_h4_filial_h4_codigo_unique` único (h4_filial, h4_codigo) · `ferramentas_h4_codigo_index` (h4_codigo) · `ferramentas_unidade_index` (unidade)

**Regras (CHECK, só no SQL Server):** `ck_ferramentas_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')` · `ck_ferramentas_cavidades`: `h4_qtdcavativas >= 0`

### filtros_usuarios

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `usuario_id` | `bigint` | não |  | FK → `usuarios.id` |
| `tela` | `nvarchar(40)` | não |  |  |
| `nome` | `nvarchar(80)` | não |  |  |
| `filtros` | `nvarchar(max)` | não |  |  |
| `padrao` | `bit` | não | `'0'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `atualizado_em` | `datetime` | sim |  |  |

**Índices:** `filtros_usuarios_usuario_id_tela_nome_unique` único (usuario_id, tela, nome)

### grupo_permissoes

**Origem:** Sistema OEE · **Chave primária:** `grupo_id, permissao`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `grupo_id` | `bigint` | não |  | PK; FK → `grupos.id` |
| `permissao` | `nvarchar(60)` | não |  | PK |

### grupo_usuario

**Origem:** Sistema OEE · **Chave primária:** `grupo_id, usuario_id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `grupo_id` | `bigint` | não |  | PK; FK → `grupos.id` |
| `usuario_id` | `bigint` | não |  | PK; FK → `usuarios.id` |

### grupos

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `nome` | `nvarchar(80)` | não |  |  |
| `descricao` | `nvarchar(300)` | sim |  |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `grupos_nome_unique` único (nome)

### job_batches

**Origem:** Infraestrutura do Laravel · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `nvarchar(255)` | não |  | PK |
| `name` | `nvarchar(255)` | não |  |  |
| `total_jobs` | `int` | não |  |  |
| `pending_jobs` | `int` | não |  |  |
| `failed_jobs` | `int` | não |  |  |
| `failed_job_ids` | `nvarchar(max)` | não |  |  |
| `options` | `nvarchar(max)` | sim |  |  |
| `cancelled_at` | `int` | sim |  |  |
| `created_at` | `int` | não |  |  |
| `finished_at` | `int` | sim |  |  |

### jobs

**Origem:** Infraestrutura do Laravel · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `queue` | `nvarchar(255)` | não |  |  |
| `payload` | `nvarchar(max)` | não |  |  |
| `attempts` | `smallint` | não |  |  |
| `reserved_at` | `int` | sim |  |  |
| `available_at` | `int` | não |  |  |
| `created_at` | `int` | não |  |  |

**Índices:** `jobs_queue_index` (queue)

### legendas

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `codigo` | `nvarchar(12)` | não |  |  |
| `descricao` | `nvarchar(120)` | não |  |  |
| `tipo` | `nvarchar(20)` | não |  |  |
| `cor` | `nvarchar(7)` | não |  |  |
| `ordem` | `int` | não | `'0'` |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |
| `exige_op` | `bit` | não | `'0'` |  |
| `abre_ss_unidade` | `nvarchar(20)` | sim |  |  |
| `abre_ss_area_executante_id` | `bigint` | sim |  | FK → `os_areas_executantes.id` |
| `exibir_dashboards` | `bit` | não | `'1'` |  |

**Índices:** `legendas_codigo_unique` único (codigo) · `legendas_unidade_index` (unidade)

**Regras (CHECK, só no SQL Server):** `ck_legendas_tipo`: `tipo IN ('produzindo', 'parada', 'planejada', 'desligada')` · `ck_legendas_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')`

### linhas

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `planta_id` | `bigint` | não |  | FK → `plantas.id` |
| `codigo` | `nvarchar(20)` | não |  |  |
| `nome` | `nvarchar(120)` | não |  |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |

**Índices:** `linhas_planta_id_codigo_unique` único (planta_id, codigo) · `linhas_unidade_index` (unidade)

**Regras (CHECK, só no SQL Server):** `ck_linhas_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')`

### maos_de_obra

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `b1_cod` | `nvarchar(30)` | não |  |  |
| `fator_servico` | `decimal(6,` | sim | `'0'` |  |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `maos_de_obra_b1_cod_unique` único (b1_cod)

### maquinas

**Origem:** Mista — parte dos campos vem de `Compras.SHB` (Protheus), o resto é do Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `linha_id` | `bigint` | não |  | FK → `linhas.id` |
| `codigo` | `nvarchar(30)` | não |  |  |
| `nome` | `nvarchar(120)` | não |  |  |
| `fabricante` | `nvarchar(120)` | sim |  |  |
| `modelo` | `nvarchar(120)` | sim |  |  |
| `ciclo_ideal_s` | `float(53)` | sim |  |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `centro_custo` | `nvarchar(20)` | sim |  | código → `centros_custo.ctt_custo` (sem FK) |
| `micro_parada_s` | `int` | sim |  |  |
| `macro_parada_s` | `int` | sim |  |  |
| `iot1_id` | `nvarchar(100)` | sim |  |  |
| `iot1_canal` | `nvarchar(100)` | sim |  |  |
| `iot1_tipo` | `nvarchar(20)` | sim |  |  |
| `iot2_id` | `nvarchar(100)` | sim |  |  |
| `iot2_canal` | `nvarchar(100)` | sim |  |  |
| `iot2_tipo` | `nvarchar(20)` | sim |  |  |
| `iot3_id` | `nvarchar(100)` | sim |  |  |
| `iot3_canal` | `nvarchar(100)` | sim |  |  |
| `iot3_tipo` | `nvarchar(20)` | sim |  |  |
| `hb_filial` | `nvarchar(8)` | sim |  |  |
| `r_e_c_n_o_` | `int` | sim |  |  |
| `d_e_l_e_t_` | `nchar(1)` | não | `' '` |  |
| `sincronizado_em` | `datetime` | sim |  |  |
| `excluida_protheus_em` | `datetime` | sim |  |  |
| `iot1_dispositivo_id` | `bigint` | sim |  | FK → `dispositivos_iot.id` |
| `iot2_dispositivo_id` | `bigint` | sim |  | FK → `dispositivos_iot.id` |
| `iot3_dispositivo_id` | `bigint` | sim |  | FK → `dispositivos_iot.id` |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |
| `situacao` | `nvarchar(12)` | sim |  |  |
| `situacao_desde` | `datetime` | sim |  |  |
| `situacao_legenda_id` | `bigint` | sim |  | FK → `legendas.id` |
| `ordem_producao_id` | `bigint` | sim |  | FK → `ordens_producao.id` |
| `calendario_id` | `bigint` | sim |  | FK → `calendarios.id` |
| `dias_semana` | `nvarchar(7)` | sim |  |  |
| `jornada_inicio` | `nvarchar(5)` | sim |  |  |
| `jornada_fim` | `nvarchar(5)` | sim |  |  |
| `horarios_semana` | `nvarchar(200)` | sim |  |  |
| `micro_parada_ms` | `int` | sim |  |  |
| `macro_parada_ms` | `int` | sim |  |  |
| `turnos_semana` | `nvarchar(1000)` | sim |  |  |

**Índices:** `maquinas_codigo_unique` único (codigo) · `maquinas_centro_custo_index` (centro_custo) · `maquinas_hb_filial_r_e_c_n_o__index` (hb_filial, r_e_c_n_o_) · `maquinas_unidade_index` (unidade)

**Regras (CHECK, só no SQL Server):** `ck_maquinas_paradas`: `micro_parada_s IS NULL OR macro_parada_s IS NULL OR macro_parada_s > micro_parada_s` · `ck_maquinas_iot1_tipo`: `iot1_tipo IS NULL OR iot1_tipo IN ('producao', 'refugo')` · `ck_maquinas_iot2_tipo`: `iot2_tipo IS NULL OR iot2_tipo IN ('producao', 'refugo')` · `ck_maquinas_iot3_tipo`: `iot3_tipo IS NULL OR iot3_tipo IN ('producao', 'refugo')` · `ck_maquinas_d_e_l_e_t_`: `d_e_l_e_t_ IN (' ', '*')` · `ck_maquinas_ativa_com_linha`: `ativo = 0 OR linha_id IS NOT NULL` · `ck_maquinas_ativa_nao_excluida`: `ativo = 0 OR d_e_l_e_t_ <> '*'` · `ck_maquinas_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')` · `ck_maquinas_situacao`: `situacao IS NULL OR situacao IN ('produzindo', 'parada')`

### metas_producao

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `produto` | `nvarchar(30)` | não |  |  |
| `revisao` | `nvarchar(3)` | não | `''` |  |
| `item_mo` | `nvarchar(30)` | sim |  |  |
| `horas` | `float(53)` | sim |  |  |
| `base` | `float(53)` | sim |  |  |
| `por_minuto` | `float(53)` | sim |  |  |
| `vigente_desde` | `datetime` | não |  |  |
| `registrado_em` | `datetime` | não |  |  |
| `fator_servico` | `float(53)` | sim |  |  |

**Índices:** `metas_producao_produto_revisao_vigente_desde_index` (produto, revisao, vigente_desde)

### metas_producao_horas

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `maquina_id` | `bigint` | não |  | FK → `maquinas.id` |
| `ordem_producao_id` | `bigint` | não |  | FK → `ordens_producao.id` |
| `dia` | `date` | não |  |  |
| `hora_inicio` | `datetime` | não |  |  |
| `meta_por_minuto` | `float(53)` | sim |  |  |
| `segundos` | `int` | não | `'0'` |  |
| `meta` | `float(53)` | sim |  |  |
| `pecas` | `int` | não | `'0'` |  |
| `atualizado_em` | `datetime` | sim |  |  |

**Índices:** `metas_producao_horas_maquina_id_hora_inicio_ordem_producao_id_unique` único (maquina_id, hora_inicio, ordem_producao_id) · `metas_producao_horas_dia_maquina_id_index` (dia, maquina_id)

### motivos_parada

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `codigo` | `nvarchar(20)` | não |  |  |
| `descricao` | `nvarchar(200)` | não |  |  |
| `categoria` | `nvarchar(60)` | sim |  |  |
| `planejada` | `bit` | não | `'0'` |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `motivos_parada_codigo_unique` único (codigo)

### movimentos_producao

**Origem:** Protheus — sincronizada de `Compras.SD3` · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `d3_filial` | `nvarchar(8)` | não | `''` |  |
| `d3_tm` | `nvarchar(3)` | não | `''` |  |
| `d3_cf` | `nvarchar(3)` | não | `''` |  |
| `d3_cod` | `nvarchar(30)` | não | `''` | código → `produtos_protheus.b1_cod` (sem FK) |
| `d3_um` | `nvarchar(2)` | não | `''` |  |
| `d3_quant` | `float(53)` | não | `'0'` |  |
| `d3_local` | `nvarchar(4)` | não | `''` |  |
| `d3_doc` | `nvarchar(20)` | não | `''` |  |
| `d3_emissao` | `nchar(8)` | não | `''` |  |
| `d3_numseq` | `nvarchar(10)` | não | `''` |  |
| `d3_custo1` | `float(53)` | não | `'0'` |  |
| `d3_estorno` | `nchar(1)` | não | `''` |  |
| `op` | `nvarchar(20)` | não | `''` |  |
| `op_numero` | `nvarchar(20)` | não | `''` | código → `ordens_producao.numero` (sem FK) |
| `tipo` | `nvarchar(20)` | não | `'outro'` |  |
| `d_e_l_e_t_` | `nchar(1)` | não | `' '` |  |
| `r_e_c_n_o_` | `int` | não |  |  |
| `sincronizado_em` | `datetime` | sim |  |  |
| `custo_medio` | `float(53)` | sim |  |  |

**Índices:** `movimentos_producao_r_e_c_n_o__unique` único (r_e_c_n_o_) · `movimentos_producao_op_numero_d3_filial_index` (op_numero, d3_filial) · `movimentos_producao_d3_emissao_index` (d3_emissao)

### notas_fiscais_op

**Origem:** Protheus — sincronizada de `Compras.SD2` · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `d2_filial` | `nvarchar(8)` | não | `''` |  |
| `d2_doc` | `nvarchar(20)` | não | `''` |  |
| `d2_serie` | `nvarchar(3)` | não | `''` |  |
| `d2_item` | `nvarchar(4)` | não | `''` |  |
| `d2_cod` | `nvarchar(30)` | não | `''` | código → `produtos_protheus.b1_cod` (sem FK) |
| `d2_um` | `nvarchar(2)` | não | `''` |  |
| `d2_quant` | `float(53)` | não | `'0'` |  |
| `d2_local` | `nvarchar(4)` | não | `''` |  |
| `d2_emissao` | `nchar(8)` | não | `''` |  |
| `d2_custo1` | `float(53)` | não | `'0'` |  |
| `d2_cf` | `nvarchar(5)` | não | `''` |  |
| `d2_cliente` | `nvarchar(10)` | não | `''` |  |
| `d2_loja` | `nvarchar(4)` | não | `''` |  |
| `op` | `nvarchar(20)` | não | `''` |  |
| `op_numero` | `nvarchar(20)` | não | `''` | código → `ordens_producao.numero` (sem FK) |
| `d_e_l_e_t_` | `nchar(1)` | não | `' '` |  |
| `r_e_c_n_o_` | `int` | não |  |  |
| `sincronizado_em` | `datetime` | sim |  |  |
| `custo_medio` | `float(53)` | sim |  |  |

**Índices:** `notas_fiscais_op_r_e_c_n_o__unique` único (r_e_c_n_o_) · `notas_fiscais_op_op_numero_d2_filial_index` (op_numero, d2_filial) · `notas_fiscais_op_d2_emissao_index` (d2_emissao)

### ordens_producao

**Origem:** Protheus — sincronizada de `Compras.SC2` · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `c2_filial` | `nvarchar(8)` | não | `''` |  |
| `c2_num` | `nvarchar(10)` | não |  |  |
| `c2_item` | `nvarchar(4)` | não | `''` |  |
| `c2_sequen` | `nvarchar(4)` | não | `''` |  |
| `numero` | `nvarchar(20)` | não |  |  |
| `c2_produto` | `nvarchar(30)` | não | `''` | código → `produtos_protheus.b1_cod` (sem FK) |
| `c2_local` | `nvarchar(4)` | não | `''` |  |
| `c2_cc` | `nvarchar(20)` | não | `''` | código → `centros_custo.ctt_custo` (sem FK) |
| `c2_um` | `nvarchar(2)` | não | `''` |  |
| `c2_quant` | `float(53)` | não | `'0'` |  |
| `c2_quje` | `float(53)` | não | `'0'` |  |
| `c2_perda` | `float(53)` | não | `'0'` |  |
| `c2_emissao` | `nchar(8)` | não | `''` |  |
| `c2_datpri` | `nchar(8)` | não | `''` |  |
| `c2_datprf` | `nchar(8)` | não | `''` |  |
| `c2_datrf` | `nchar(8)` | não | `''` |  |
| `c2_tpop` | `nchar(1)` | não | `''` |  |
| `c2_status` | `nchar(1)` | não | `''` |  |
| `c2_obs` | `nvarchar(120)` | não | `''` |  |
| `d_e_l_e_t_` | `nchar(1)` | não | `' '` |  |
| `r_e_c_n_o_` | `int` | sim |  |  |
| `status` | `nvarchar(20)` | não | `'aberta'` |  |
| `status_em` | `datetime` | sim |  |  |
| `sincronizado_em` | `datetime` | sim |  |  |
| `custo_meta` | `float(53)` | sim |  |  |
| `c2_roteiro` | `nvarchar(2)` | não | `''` |  |
| `c2_revisao` | `nvarchar(3)` | não | `''` |  |
| `quantidade_real` | `decimal(18,` | sim |  |  |
| `quantidade_perda` | `decimal(18,` | sim |  |  |

**Índices:** `ordens_producao_c2_filial_c2_num_c2_item_c2_sequen_unique` único (c2_filial, c2_num, c2_item, c2_sequen) · `ordens_producao_numero_index` (numero) · `ordens_producao_status_c2_datprf_index` (status, c2_datprf) · `ordens_producao_c2_produto_index` (c2_produto) · `ordens_producao_r_e_c_n_o__index` (r_e_c_n_o_)

**Regras (CHECK, só no SQL Server):** `ck_ordens_producao_status`: `status IN ('aberta', 'andamento', 'fechada', 'parada')`

### ordens_servico

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `unidade` | `nvarchar(20)` | não |  |  |
| `solicitacao_id` | `bigint` | sim |  | FK → `solicitacoes_servico.id` |
| `plano_preventiva_id` | `bigint` | sim |  | FK → `os_planos_preventiva.id` |
| `prevista_para` | `date` | sim |  |  |
| `tipo` | `nvarchar(15)` | não |  |  |
| `maquina_id` | `bigint` | sim |  | FK → `maquinas.id` |
| `area_executante_id` | `bigint` | não |  | FK → `os_areas_executantes.id` |
| `centro_custo` | `nvarchar(20)` | sim |  | código → `centros_custo.ctt_custo` (sem FK) |
| `centro_custo_solicitante` | `nvarchar(120)` | sim |  |  |
| `prioridade` | `nvarchar(10)` | não |  |  |
| `maquina_parada` | `bit` | não | `'0'` |  |
| `descricao` | `nvarchar(2000)` | não |  |  |
| `solicitante_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `aprovado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `responsavel_id` | `bigint` | sim |  | FK → `os_mantenedores.id` |
| `status` | `nvarchar(20)` | não | `'aberta'` |  |
| `iniciada_em` | `datetime` | sim |  |  |
| `concluida_em` | `datetime` | sim |  |  |
| `solucao` | `nvarchar(2000)` | sim |  |  |
| `aceite_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `aceite_em` | `datetime` | sim |  |  |
| `aceite_observacao` | `nvarchar(500)` | sim |  |  |
| `cancelamento_motivo` | `nvarchar(500)` | sim |  |  |
| `ordem_producao_id` | `bigint` | sim |  | FK → `ordens_producao.id` |
| `apontamento_estado_id` | `bigint` | sim |  | FK → `apontamentos_estado.id` |
| `total_minutos` | `int` | não | `'0'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `titulo` | `nvarchar(120)` | sim |  |  |
| `avaliacao` | `nvarchar(10)` | sim |  |  |
| `risco_integridade` | `bit` | não | `'0'` |  |
| `automatica` | `bit` | não | `'0'` |  |
| `origem_iot` | `nvarchar(120)` | sim |  |  |
| `ferramenta_id` | `bigint` | sim |  | FK → `ferramentas.id` |

**Índices:** `ux_ordens_servico_solicitacao` único (solicitacao_id) filtrado `WHERE solicitacao_id IS NOT NULL` (só SQL Server) · `ux_ordens_servico_plano_prevista` único (plano_preventiva_id, prevista_para) filtrado `WHERE plano_preventiva_id IS NOT NULL` (só SQL Server) · `ordens_servico_unidade_status_id_index` (unidade, status, id) · `ordens_servico_responsavel_id_status_index` (responsavel_id, status) · `ordens_servico_maquina_id_criado_em_index` (maquina_id, criado_em) · `ordens_servico_status_prevista_para_index` (status, prevista_para) · `ordens_servico_solicitante_id_index` (solicitante_id) · `ordens_servico_ferramenta_id_index` (ferramenta_id)

**Regras (CHECK, só no SQL Server):** `ck_ordens_servico_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria')` · `ck_ordens_servico_tipo`: `tipo IN ('corretiva', 'preventiva', 'servico_geral')` · `ck_ordens_servico_prioridade`: `prioridade IN ('baixa', 'media', 'alta', 'urgente')` · `ck_ordens_servico_status`: `status IN ('aberta', 'em_execucao', 'aguardando_material', 'concluida', 'encerrada', 'cancelada')` · `ck_ordens_servico_total_minutos`: `total_minutos >= 0` · `ck_ordens_servico_avaliacao`: `avaliacao IS NULL OR avaliacao IN ('excelente', 'boa', 'ruim')`

### os_apontamentos_horas

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `ordem_servico_id` | `bigint` | não |  | FK → `ordens_servico.id` |
| `mantenedor_id` | `bigint` | não |  | FK → `os_mantenedores.id` |
| `inicio` | `datetime` | não |  |  |
| `fim` | `datetime` | sim |  |  |
| `minutos_normais` | `int` | sim |  |  |
| `minutos_extras` | `int` | sim |  |  |
| `descricao` | `nvarchar(1000)` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `ux_os_apontamentos_horas_aberto` único (mantenedor_id) filtrado `WHERE fim IS NULL` (só SQL Server) · `os_apontamentos_horas_ordem_servico_id_inicio_index` (ordem_servico_id, inicio) · `os_apontamentos_horas_mantenedor_id_inicio_index` (mantenedor_id, inicio) · `os_apontamentos_horas_inicio_index` (inicio)

**Regras (CHECK, só no SQL Server):** `ck_os_apontamentos_horas_periodo`: `fim IS NULL OR fim >= inicio` · `ck_os_apontamentos_horas_minutos`: `(minutos_normais IS NULL OR minutos_normais >= 0) AND (minutos_extras IS NULL OR minutos_extras >= 0)`

### os_areas_executantes

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |
| `codigo` | `nvarchar(20)` | não |  |  |
| `nome` | `nvarchar(80)` | não |  |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `meta_atendimento_min` | `int` | sim |  |  |
| `meta_reparo_min` | `int` | sim |  |  |

**Índices:** `os_areas_executantes_codigo_unique` único (codigo) · `os_areas_executantes_unidade_index` (unidade)

**Regras (CHECK, só no SQL Server):** `ck_os_areas_executantes_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')`

### os_assinaturas

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `ordem_servico_id` | `bigint` | não |  | FK → `ordens_servico.id` |
| `assinado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `registrado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `nome_assinante` | `nvarchar(120)` | sim |  |  |
| `avaliacao` | `nvarchar(10)` | não |  |  |
| `imagem_png` | `nvarchar(max)` | não |  |  |
| `criado_em` | `datetime` | sim |  |  |

**Índices:** `os_assinaturas_ordem_servico_id_index` (ordem_servico_id)

### os_checklist

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `ordem_servico_id` | `bigint` | não |  | FK → `ordens_servico.id` |
| `plano_item_id` | `bigint` | sim |  | FK → `os_planos_itens.id` |
| `ordem` | `smallint` | não | `'0'` |  |
| `descricao` | `nvarchar(300)` | não |  |  |
| `resposta` | `nvarchar(3)` | sim |  |  |
| `observacao` | `nvarchar(300)` | sim |  |  |
| `respondido_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `respondido_em` | `datetime` | sim |  |  |

**Índices:** `os_checklist_ordem_servico_id_ordem_index` (ordem_servico_id, ordem)

**Regras (CHECK, só no SQL Server):** `ck_os_checklist_resposta`: `resposta IS NULL OR resposta IN ('ok', 'nok', 'na')`

### os_ferramentas

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |
| `codigo` | `nvarchar(30)` | não |  |  |
| `descricao` | `nvarchar(150)` | não |  |  |
| `marca_modelo` | `nvarchar(100)` | sim |  |  |
| `numero_serie` | `nvarchar(60)` | sim |  |  |
| `mantenedor_id` | `bigint` | sim |  | FK → `os_mantenedores.id` |
| `atribuida_em` | `datetime` | sim |  |  |
| `observacao` | `nvarchar(500)` | sim |  |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `os_ferramentas_codigo_unique` único (codigo) · `os_ferramentas_unidade_index` (unidade) · `os_ferramentas_mantenedor_id_ativo_index` (mantenedor_id, ativo)

**Regras (CHECK, só no SQL Server):** `ck_os_ferramentas_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')`

### os_ferramentas_atributos

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `ferramenta_id` | `bigint` | não |  | FK → `os_ferramentas.id` |
| `nome` | `nvarchar(60)` | não |  |  |
| `valor` | `nvarchar(255)` | sim |  |  |
| `ordem` | `smallint` | não | `'0'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `os_ferramentas_atributos_ferramenta_id_ordem_index` (ferramenta_id, ordem) · `os_ferramentas_atributos_nome_index` (nome)

### os_historico

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `ordem_servico_id` | `bigint` | sim |  | FK → `ordens_servico.id` |
| `solicitacao_id` | `bigint` | sim |  | FK → `solicitacoes_servico.id` |
| `acao` | `nvarchar(30)` | não |  |  |
| `status_anterior` | `nvarchar(20)` | sim |  |  |
| `status_novo` | `nvarchar(20)` | sim |  |  |
| `observacao` | `nvarchar(1000)` | sim |  |  |
| `usuario_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `criado_em` | `datetime` | sim |  |  |

**Índices:** `os_historico_ordem_servico_id_id_index` (ordem_servico_id, id) · `os_historico_solicitacao_id_id_index` (solicitacao_id, id)

**Regras (CHECK, só no SQL Server):** `ck_os_historico_origem`: `ordem_servico_id IS NOT NULL OR solicitacao_id IS NOT NULL`

### os_interacao_anexos

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `interacao_id` | `bigint` | não |  | FK → `os_interacoes.id` |
| `nome` | `nvarchar(200)` | não |  |  |
| `caminho` | `nvarchar(300)` | não |  |  |
| `mime` | `nvarchar(120)` | sim |  |  |
| `tamanho` | `int` | não | `'0'` |  |
| `criado_em` | `datetime` | sim |  |  |

**Índices:** `os_interacao_anexos_interacao_id_index` (interacao_id)

### os_interacoes

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `ordem_servico_id` | `bigint` | não |  | FK → `ordens_servico.id` |
| `usuario_id` | `bigint` | não |  | FK → `usuarios.id` |
| `texto` | `nvarchar(4000)` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |

**Índices:** `os_interacoes_ordem_servico_id_id_index` (ordem_servico_id, id)

### os_justificativas_setor

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `unidade` | `nvarchar(20)` | não |  |  |
| `centro_custo` | `nvarchar(120)` | não |  |  |
| `competencia` | `date` | não |  |  |
| `texto` | `nvarchar(1000)` | não |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `os_justificativas_setor_unidade_centro_custo_competencia_unique` único (unidade, centro_custo, competencia)

**Regras (CHECK, só no SQL Server):** `ck_os_justificativas_setor_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')` · `ck_os_justificativas_setor_competencia`: `DAY(competencia) = 1`

### os_mantenedor_areas

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `mantenedor_id` | `bigint` | não |  | FK → `os_mantenedores.id` |
| `area_executante_id` | `bigint` | não |  | FK → `os_areas_executantes.id` |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `os_mantenedor_areas_mantenedor_id_area_executante_id_unique` único (mantenedor_id, area_executante_id) · `os_mantenedor_areas_area_executante_id_index` (area_executante_id)

### os_mantenedores

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |
| `usuario_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `nome` | `nvarchar(120)` | não |  |  |
| `matricula` | `nvarchar(20)` | sim |  |  |
| `area_executante_id` | `bigint` | sim |  | FK → `os_areas_executantes.id` |
| `centro_custo` | `nvarchar(20)` | sim |  | código → `centros_custo.ctt_custo` (sem FK) |
| `jornada_inicio` | `nvarchar(5)` | não | `'07:00'` |  |
| `jornada_fim` | `nvarchar(5)` | não | `'17:00'` |  |
| `intervalo_min` | `smallint` | não | `'60'` |  |
| `dias_semana` | `nvarchar(7)` | não | `'12345'` |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `calendario_id` | `bigint` | sim |  | FK → `calendarios.id` |
| `horarios_semana` | `nvarchar(200)` | sim |  |  |

**Índices:** `ux_os_mantenedores_usuario` único (usuario_id) filtrado `WHERE usuario_id IS NOT NULL` (só SQL Server) · `os_mantenedores_unidade_index` (unidade) · `os_mantenedores_area_executante_id_index` (area_executante_id)

**Regras (CHECK, só no SQL Server):** `ck_os_mantenedores_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')` · `ck_os_mantenedores_jornada`: `jornada_inicio LIKE '[0-2][0-9]:[0-5][0-9]' AND jornada_fim LIKE '[0-2][0-9]:[0-5][0-9]'` · `ck_os_mantenedores_dias_semana`: `dias_semana NOT LIKE '%[^1-7]%'` · `ck_os_mantenedores_intervalo`: `intervalo_min >= 0`

### os_materiais

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `ordem_servico_id` | `bigint` | não |  | FK → `ordens_servico.id` |
| `produto_codigo` | `nvarchar(30)` | sim |  | código → `produtos_protheus.b1_cod` (sem FK) |
| `descricao` | `nvarchar(200)` | não |  |  |
| `quantidade` | `decimal(14,` | sim |  |  |
| `um` | `nvarchar(4)` | sim |  |  |
| `status` | `nvarchar(12)` | não | `'requisitado'` |  |
| `solicitacao_compra` | `nvarchar(30)` | sim |  |  |
| `custo_unitario` | `decimal(18,` | sim |  |  |
| `observacao` | `nvarchar(500)` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `os_materiais_status_id_index` (status, id) · `os_materiais_ordem_servico_id_index` (ordem_servico_id)

**Regras (CHECK, só no SQL Server):** `ck_os_materiais_status`: `status IN ('requisitado', 'disponivel', 'em_compra', 'recebido', 'entregue', 'cancelado')` · `ck_os_materiais_quantidade`: `quantidade > 0` · `ck_os_materiais_custo`: `custo_unitario IS NULL OR custo_unitario >= 0`

### os_ordem_mantenedores

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `ordem_servico_id` | `bigint` | não |  | FK → `ordens_servico.id` |
| `mantenedor_id` | `bigint` | não |  | FK → `os_mantenedores.id` |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `os_ordem_mantenedores_ordem_servico_id_mantenedor_id_unique` único (ordem_servico_id, mantenedor_id) · `os_ordem_mantenedores_mantenedor_id_ordem_servico_id_index` (mantenedor_id, ordem_servico_id)

### os_planos_itens

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `plano_preventiva_id` | `bigint` | não |  | FK → `os_planos_preventiva.id` |
| `ordem` | `smallint` | não | `'0'` |  |
| `descricao` | `nvarchar(300)` | não |  |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `os_planos_itens_plano_preventiva_id_ordem_index` (plano_preventiva_id, ordem)

### os_planos_preventiva

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |
| `codigo` | `nvarchar(20)` | não |  |  |
| `descricao` | `nvarchar(200)` | não |  |  |
| `maquina_id` | `bigint` | sim |  | FK → `maquinas.id` |
| `area_executante_id` | `bigint` | não |  | FK → `os_areas_executantes.id` |
| `responsavel_id` | `bigint` | sim |  | FK → `os_mantenedores.id` |
| `prioridade` | `nvarchar(10)` | não | `'media'` |  |
| `periodicidade` | `nvarchar(10)` | não |  |  |
| `meses` | `nvarchar(12)` | não | `'111111111111'` |  |
| `dia_mes` | `smallint` | não | `'1'` |  |
| `proxima_em` | `date` | sim |  |  |
| `antecedencia_dias` | `smallint` | não | `'7'` |  |
| `duracao_prevista_min` | `int` | sim |  |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `os_planos_preventiva_codigo_unique` único (codigo) · `os_planos_preventiva_unidade_index` (unidade) · `os_planos_preventiva_ativo_proxima_em_index` (ativo, proxima_em) · `os_planos_preventiva_maquina_id_index` (maquina_id)

**Regras (CHECK, só no SQL Server):** `ck_os_planos_preventiva_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')` · `ck_os_planos_preventiva_prioridade`: `prioridade IN ('baixa', 'media', 'alta', 'urgente')` · `ck_os_planos_preventiva_periodicidade`: `periodicidade IN ('semanal', 'quinzenal', 'meses')` · `ck_os_planos_preventiva_meses`: `LEN(meses) = 12 AND meses NOT LIKE '%[^01]%'` · `ck_os_planos_preventiva_dia_mes`: `dia_mes BETWEEN 1 AND 31` · `ck_os_planos_preventiva_antecedencia`: `antecedencia_dias >= 0` · `ck_os_planos_preventiva_duracao`: `duracao_prevista_min IS NULL OR duracao_prevista_min > 0`

### os_recusas_aplicativo

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `solicitacao_id` | `bigint` | não |  | FK → `solicitacoes_servico.id` |
| `usuario_id` | `bigint` | não |  | FK → `usuarios.id` |
| `criado_em` | `datetime` | sim |  |  |

**Índices:** `os_recusas_aplicativo_solicitacao_id_usuario_id_unique` único (solicitacao_id, usuario_id)

### os_sugestoes_descricao

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |
| `texto` | `nvarchar(200)` | não |  |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `os_sugestoes_descricao_unidade_index` (unidade)

**Regras (CHECK, só no SQL Server):** `ck_os_sugestoes_descricao_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')`

### paradas

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `maquina_id` | `bigint` | não |  | FK → `maquinas.id` |
| `motivo_id` | `bigint` | sim |  | FK → `motivos_parada.id` |
| `inicio` | `datetime` | não |  |  |
| `fim` | `datetime` | sim |  |  |
| `observacao` | `nvarchar(500)` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `paradas_maquina_id_inicio_index` (maquina_id, inicio)

**Regras (CHECK, só no SQL Server):** `ck_paradas_periodo`: `fim IS NULL OR fim > inicio`

### pedidos_compra

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `unidade` | `nvarchar(20)` | não |  |  |
| `solicitacao_material_id` | `bigint` | sim |  | FK → `solicitacoes_material.id` |
| `ordem_servico_id` | `bigint` | sim |  | FK → `ordens_servico.id` |
| `filial` | `nvarchar(8)` | não | `''` |  |
| `centro_custo_origem` | `nvarchar(20)` | não |  |  |
| `centro_custo_destino` | `nvarchar(20)` | não |  |  |
| `observacao` | `nvarchar(500)` | sim |  |  |
| `status` | `nvarchar(25)` | não | `'aguardando_protheus'` |  |
| `numero_pedido` | `nvarchar(10)` | sim |  |  |
| `mensagem` | `nvarchar(1000)` | sim |  |  |
| `integrado_em` | `datetime` | sim |  |  |
| `aprovador` | `nvarchar(120)` | sim |  |  |
| `aprovado_em` | `datetime` | sim |  |  |
| `concluido_em` | `datetime` | sim |  |  |
| `valor_total` | `decimal(18,` | sim |  |  |
| `consultado_em` | `datetime` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `tipo` | `nvarchar(12)` | não | `'pedido'` |  |

**Índices:** `pedidos_compra_solicitacao_material_id_id_index` (solicitacao_material_id, id) · `pedidos_compra_ordem_servico_id_id_index` (ordem_servico_id, id) · `pedidos_compra_status_id_index` (status, id)

**Regras (CHECK, só no SQL Server):** `ck_pedidos_compra_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria')` · `ck_pedidos_compra_status`: `status IN ('aguardando_protheus', 'aguardando_aprovacao', 'aprovado', 'concluido', 'reprovado', 'erro', 'cancelado')` · `ck_pedidos_compra_origem`: `solicitacao_material_id IS NOT NULL OR ordem_servico_id IS NOT NULL` · `ck_pedidos_compra_tipo`: `tipo IN ('pedido', 'solicitacao')`

### pedidos_compra_itens

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `pedido_compra_id` | `bigint` | não |  | FK → `pedidos_compra.id` |
| `solicitacao_material_item_id` | `bigint` | sim |  | FK → `solicitacoes_material_itens.id` |
| `item` | `nvarchar(4)` | não |  |  |
| `produto_codigo` | `nvarchar(30)` | não |  |  |
| `produto_descricao` | `nvarchar(120)` | não | `''` |  |
| `um` | `nvarchar(4)` | não | `''` |  |
| `quantidade` | `decimal(14,` | sim |  |  |
| `observacao` | `nvarchar(250)` | sim |  |  |
| `valor_unitario` | `decimal(18,` | sim |  |  |
| `valor_total` | `decimal(18,` | sim |  |  |
| `quantidade_entregue` | `decimal(14,` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `pedido_gerado` | `nvarchar(10)` | sim |  |  |

**Índices:** `pedidos_compra_itens_pedido_compra_id_id_index` (pedido_compra_id, id) · `pedidos_compra_itens_solicitacao_material_item_id_index` (solicitacao_material_item_id)

**Regras (CHECK, só no SQL Server):** `ck_pedidos_compra_itens_quantidade`: `quantidade > 0`

### perdas_op

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `ordem_producao_id` | `bigint` | não |  | FK → `ordens_producao.id` |
| `maquina_id` | `bigint` | sim |  | FK → `maquinas.id` |
| `dia` | `date` | não |  |  |
| `quantidade` | `decimal(18,` | sim |  |  |
| `peso` | `decimal(18,` | sim |  |  |
| `peso_unitario` | `decimal(18,` | sim |  |  |
| `origem` | `nvarchar(12)` | não | `'portal'` |  |
| `observacao` | `nvarchar(500)` | sim |  |  |
| `excluido_em` | `datetime` | sim |  |  |
| `excluido_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `turno` | `tinyint` | sim |  |  |

**Índices:** `perdas_op_ordem_producao_id_excluido_em_index` (ordem_producao_id, excluido_em)

### plantas

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `codigo` | `nvarchar(20)` | não |  |  |
| `nome` | `nvarchar(120)` | não |  |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `unidade` | `nvarchar(20)` | não | `'manutencao'` |  |

**Índices:** `plantas_codigo_unique` único (codigo) · `plantas_unidade_index` (unidade)

**Regras (CHECK, só no SQL Server):** `ck_plantas_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria', 'ambos')`

### produtos

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `codigo` | `nvarchar(40)` | não |  |  |
| `descricao` | `nvarchar(200)` | não |  |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `produtos_codigo_unique` único (codigo)

### produtos_protheus

**Origem:** Protheus — sincronizada de `Compras.SB1` · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `b1_filial` | `nvarchar(8)` | não | `''` |  |
| `b1_cod` | `nvarchar(30)` | não |  |  |
| `b1_desc` | `nvarchar(120)` | não | `''` |  |
| `b1_tipo` | `nvarchar(2)` | não | `''` |  |
| `b1_um` | `nvarchar(2)` | não | `''` |  |
| `b1_grupo` | `nvarchar(10)` | não | `''` |  |
| `b1_msblql` | `nchar(1)` | não | `'2'` |  |
| `d_e_l_e_t_` | `nchar(1)` | não | `' '` |  |
| `r_e_c_n_o_` | `int` | sim |  |  |
| `sincronizado_em` | `datetime` | sim |  |  |
| `b1_qb` | `float(53)` | sim |  |  |
| `b1_peso` | `float(53)` | sim |  |  |

**Índices:** `produtos_protheus_b1_filial_b1_cod_unique` único (b1_filial, b1_cod) · `produtos_protheus_b1_cod_index` (b1_cod)

**Regras (CHECK, só no SQL Server):** `ck_produtos_protheus_msblql`: `b1_msblql IN ('1', '2')`

### registros_login

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `usuario_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `email` | `nvarchar(320)` | sim |  |  |
| `data_hora` | `datetime` | não |  |  |
| `sucesso` | `bit` | não |  |  |
| `motivo` | `nvarchar(200)` | sim |  |  |
| `ip` | `nvarchar(45)` | sim |  |  |
| `navegador` | `nvarchar(400)` | sim |  |  |

**Índices:** `registros_login_data_hora_index` (data_hora)

### roteiros_operacao

**Origem:** Protheus — sincronizada de `Compras.SG2` · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `g2_filial` | `nvarchar(8)` | não | `''` |  |
| `g2_produto` | `nvarchar(30)` | não |  | código → `produtos_protheus.b1_cod` (sem FK) |
| `g2_codigo` | `nvarchar(4)` | não | `''` |  |
| `g2_operac` | `nvarchar(4)` | não | `''` |  |
| `g2_descri` | `nvarchar(120)` | não | `''` |  |
| `g2_recurso` | `nvarchar(10)` | não | `''` |  |
| `g2_ctrab` | `nvarchar(10)` | não | `''` | código → `maquinas.codigo` (sem FK) |
| `g2_ferram` | `nvarchar(10)` | não | `''` | código → `ferramentas.h4_codigo` (sem FK) |
| `g2_tpoper` | `nchar(1)` | não | `''` |  |
| `g2_setup` | `float(53)` | não | `'0'` |  |
| `g2_tempad` | `float(53)` | não | `'0'` |  |
| `g2_lotepad` | `float(53)` | não | `'0'` |  |
| `g2_maoobra` | `float(53)` | não | `'0'` |  |
| `d_e_l_e_t_` | `nchar(1)` | não | `' '` |  |
| `r_e_c_n_o_` | `int` | sim |  |  |
| `sincronizado_em` | `datetime` | sim |  |  |

**Índices:** `roteiros_operacao_g2_filial_g2_produto_g2_codigo_g2_operac_unique` único (g2_filial, g2_produto, g2_codigo, g2_operac) · `roteiros_operacao_g2_produto_index` (g2_produto) · `roteiros_operacao_g2_ferram_index` (g2_ferram) · `roteiros_operacao_g2_ctrab_index` (g2_ctrab)

### sessions

**Origem:** Infraestrutura do Laravel · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `nvarchar(255)` | não |  | PK |
| `user_id` | `bigint` | sim |  |  |
| `ip_address` | `nvarchar(45)` | sim |  |  |
| `user_agent` | `nvarchar(max)` | sim |  |  |
| `payload` | `nvarchar(max)` | não |  |  |
| `last_activity` | `int` | não |  |  |

**Índices:** `sessions_user_id_index` (user_id) · `sessions_last_activity_index` (last_activity)

### sincronizacoes

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `chave` | `nvarchar(40)` | não |  |  |
| `origem` | `nvarchar(160)` | não |  |  |
| `disparo` | `nvarchar(20)` | não |  |  |
| `usuario_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `iniciado_em` | `datetime` | não |  |  |
| `terminado_em` | `datetime` | sim |  |  |
| `situacao` | `nvarchar(20)` | não |  |  |
| `lidos` | `int` | não | `'0'` |  |
| `novos` | `int` | não | `'0'` |  |
| `atualizados` | `int` | não | `'0'` |  |
| `ignorados` | `int` | não | `'0'` |  |
| `mensagem` | `nvarchar(1000)` | sim |  |  |
| `excluidos` | `int` | não | `'0'` |  |
| `sinal_em` | `datetime` | sim |  |  |
| `ultimo_recno` | `bigint` | sim |  |  |

**Índices:** `sincronizacoes_chave_iniciado_em_index` (chave, iniciado_em)

**Regras (CHECK, só no SQL Server):** `ck_sincronizacoes_situacao`: `situacao IN ('executando', 'sucesso', 'erro')` · `ck_sincronizacoes_disparo`: `disparo IN ('manual', 'agendado', 'comando')`

### solicitacoes_material

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `unidade` | `nvarchar(20)` | não |  |  |
| `solicitante_id` | `bigint` | não |  | FK → `usuarios.id` |
| `status` | `nvarchar(12)` | não | `'pendente'` |  |
| `observacao` | `nvarchar(1000)` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `solicitacoes_material_unidade_status_id_index` (unidade, status, id) · `solicitacoes_material_solicitante_id_id_index` (solicitante_id, id)

**Regras (CHECK, só no SQL Server):** `ck_solicitacoes_material_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria')` · `ck_solicitacoes_material_status`: `status IN ('pendente', 'em_analise', 'analisada', 'cancelada')`

### solicitacoes_material_itens

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `solicitacao_material_id` | `bigint` | não |  | FK → `solicitacoes_material.id` |
| `produto_codigo` | `nvarchar(30)` | não |  |  |
| `produto_descricao` | `nvarchar(120)` | não | `''` |  |
| `um` | `nvarchar(4)` | não | `''` |  |
| `centro_custo` | `nvarchar(20)` | não |  |  |
| `quantidade_solicitada` | `decimal(14,` | sim |  |  |
| `prioridade` | `nvarchar(10)` | não | `'media'` |  |
| `observacao` | `nvarchar(1000)` | sim |  |  |
| `status` | `nvarchar(12)` | não | `'pendente'` |  |
| `quantidade_aprovada` | `decimal(14,` | sim |  |  |
| `motivo_reprovacao` | `nvarchar(500)` | sim |  |  |
| `aprovado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `aprovado_em` | `datetime` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `solicitacoes_material_itens_solicitacao_material_id_id_index` (solicitacao_material_id, id) · `solicitacoes_material_itens_status_prioridade_index` (status, prioridade) · `solicitacoes_material_itens_produto_codigo_index` (produto_codigo)

**Regras (CHECK, só no SQL Server):** `ck_solicitacoes_material_itens_prioridade`: `prioridade IN ('alta', 'media', 'baixa')` · `ck_solicitacoes_material_itens_status`: `status IN ('pendente', 'aprovado', 'reprovado')` · `ck_solicitacoes_material_itens_quantidades`: `quantidade_solicitada > 0 AND (quantidade_aprovada IS NULL OR quantidade_aprovada >= 0)`

### solicitacoes_servico

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `unidade` | `nvarchar(20)` | não |  |  |
| `solicitante_id` | `bigint` | não |  | FK → `usuarios.id` |
| `tipo` | `nvarchar(15)` | não |  |  |
| `maquina_id` | `bigint` | sim |  | FK → `maquinas.id` |
| `area_executante_id` | `bigint` | não |  | FK → `os_areas_executantes.id` |
| `centro_custo` | `nvarchar(20)` | sim |  | código → `centros_custo.ctt_custo` (sem FK) |
| `centro_custo_solicitante` | `nvarchar(120)` | sim |  |  |
| `prioridade` | `nvarchar(10)` | não | `'media'` |  |
| `maquina_parada` | `bit` | não | `'0'` |  |
| `descricao` | `nvarchar(2000)` | não |  |  |
| `status` | `nvarchar(12)` | não | `'pendente'` |  |
| `analisado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `analisado_em` | `datetime` | sim |  |  |
| `motivo_rejeicao` | `nvarchar(500)` | sim |  |  |
| `apontamento_estado_id` | `bigint` | sim |  | FK → `apontamentos_estado.id` |
| `ordem_producao_id` | `bigint` | sim |  | FK → `ordens_producao.id` |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `titulo` | `nvarchar(120)` | sim |  |  |
| `risco_integridade` | `bit` | não | `'0'` |  |
| `automatica` | `bit` | não | `'0'` |  |
| `origem_iot` | `nvarchar(120)` | sim |  |  |
| `ferramenta_id` | `bigint` | sim |  | FK → `ferramentas.id` |

**Índices:** `solicitacoes_servico_unidade_status_id_index` (unidade, status, id) · `solicitacoes_servico_solicitante_id_id_index` (solicitante_id, id) · `solicitacoes_servico_maquina_id_index` (maquina_id) · `solicitacoes_servico_ferramenta_id_index` (ferramenta_id)

**Regras (CHECK, só no SQL Server):** `ck_solicitacoes_servico_unidade`: `unidade IN ('manutencao', 'tecnologia', 'ferramentaria')` · `ck_solicitacoes_servico_tipo`: `tipo IN ('corretiva', 'preventiva', 'servico_geral')` · `ck_solicitacoes_servico_prioridade`: `prioridade IN ('baixa', 'media', 'alta', 'urgente')` · `ck_solicitacoes_servico_status`: `status IN ('pendente', 'aprovada', 'rejeitada', 'cancelada')`

### tokens_aplicativo

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `usuario_id` | `bigint` | não |  | FK → `usuarios.id` |
| `token_hash` | `nchar(64)` | não |  |  |
| `dispositivo` | `nvarchar(120)` | sim |  |  |
| `versao_app` | `nvarchar(20)` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `ultimo_uso_em` | `datetime` | sim |  |  |
| `revogado_em` | `datetime` | sim |  |  |

**Índices:** `tokens_aplicativo_token_hash_unique` único (token_hash) · `tokens_aplicativo_usuario_id_revogado_em_index` (usuario_id, revogado_em)

### turnos

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `nome` | `nvarchar(60)` | não |  |  |
| `inicio` | `time` | não |  |  |
| `fim` | `time` | não |  |  |
| `ativo` | `bit` | não | `'1'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `turnos_nome_unique` único (nome)

### usuario_perfis

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `usuario_id` | `bigint` | não |  | FK → `usuarios.id` |
| `perfil` | `nvarchar(30)` | não |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `usuario_perfis_usuario_id_perfil_unique` único (usuario_id, perfil)

**Regras (CHECK, só no SQL Server):** `ck_usuario_perfis_perfil`: `perfil IN ('operador', 'admin_producao', 'admin_tecnologia', 'mantenedor_producao', 'mantenedor_tecnologia', 'solicitante_manutencao', 'solicitante_tecnologia', 'solicitante_ferramentaria', 'mantenedor_ferramentaria', 'admin_ferramentaria')`

### usuarios

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `entra_oid` | `nvarchar(36)` | não |  |  |
| `entra_tenant_id` | `nvarchar(36)` | sim |  |  |
| `nome` | `nvarchar(200)` | sim |  |  |
| `email` | `nvarchar(320)` | sim |  |  |
| `perfil` | `nvarchar(20)` | não | `'sem_acesso'` |  |
| `ativo` | `bit` | não | `'1'` |  |
| `ultimo_login_em` | `datetime` | sim |  |  |
| `criado_em` | `datetime` | sim |  |  |
| `atualizado_em` | `datetime` | sim |  |  |
| `setor` | `nvarchar(120)` | sim |  |  |
| `cargo` | `nvarchar(120)` | sim |  |  |
| `email_contato` | `nvarchar(320)` | sim |  |  |
| `celular` | `nvarchar(40)` | sim |  |  |
| `matricula` | `nvarchar(60)` | sim |  |  |
| `foto` | `nvarchar(255)` | sim |  |  |
| `foto_atualizada_em` | `datetime` | sim |  |  |
| `perfil_entra_em` | `datetime` | sim |  |  |
| `departamento` | `nvarchar(120)` | sim |  |  |
| `local_escritorio` | `nvarchar(120)` | sim |  |  |
| `gerente_nome` | `nvarchar(200)` | sim |  |  |
| `gerente_email` | `nvarchar(320)` | sim |  |  |
| `usuario_protheus` | `nvarchar(25)` | sim |  |  |
| `id_protheus` | `nvarchar(20)` | sim |  |  |

**Índices:** `usuarios_entra_oid_unique` único (entra_oid) · `usuarios_email_index` (email) · `usuarios_usuario_protheus_index` (usuario_protheus) · `usuarios_id_protheus_index` (id_protheus)

**Regras (CHECK, só no SQL Server):** `ck_usuarios_perfil`: `perfil IN ('sem_acesso', 'operador', 'admin')`

### versoes_aplicativo

**Origem:** Sistema OEE · **Chave primária:** `id`

| Coluna | Tipo (SQL Server) | Nulo | Padrão | Chave |
|---|---|---|---|---|
| `id` | `bigint` | não |  | PK (identity) |
| `codigo` | `int` | não |  |  |
| `nome` | `nvarchar(40)` | não |  |  |
| `arquivo` | `nvarchar(255)` | não |  |  |
| `tamanho` | `bigint` | não |  |  |
| `sha256` | `nvarchar(64)` | não |  |  |
| `obrigatoria` | `bit` | não | `'0'` |  |
| `ativo` | `bit` | não | `'1'` |  |
| `observacao` | `nvarchar(1000)` | sim |  |  |
| `unidade` | `nvarchar(20)` | não | `'ambos'` |  |
| `criado_em` | `datetime` | sim |  |  |
| `criado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |
| `atualizado_em` | `datetime` | sim |  |  |
| `atualizado_por_id` | `bigint` | sim |  | FK → `usuarios.id` |

**Índices:** `versoes_aplicativo_codigo_unique` único (codigo)
