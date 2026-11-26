<?php

return [
    // Nome do portal exibido nas telas (não depende do APP_NAME do .env).
    'nome' => 'OEE Alumbra',

    // Nome curto do sistema, exibido ao lado do logotipo da Alumbra no topo e no menu lateral.
    'sigla' => 'OEE',
    'descricao' => 'Eficiência Global dos Equipamentos',

    // E-mails que NASCEM administradores no primeiro login. Todos os outros nascem "sem acesso" e um
    // administrador libera pelo menu Administração > Logins > Usuários.
    // Aplicativo Android (app-movel/): login pelo portal e API /api/app.
    // Integrador MQTT (repositório marcatao/MQTT): depois de gravar os apontamentos reais direto no banco, ele chama
    // POST /api/iot/apontamentos/processar com este token (Authorization: Bearer) para o portal abrir a SS automática.
    // Vazio = rota desligada. Gere com: php -r "echo bin2hex(random_bytes(32));" e ponha o MESMO valor no integrador.
    'iot' => [
        'token' => env('IOT_TOKEN', ''),
        'max_apontamentos' => 100, // por chamada (cada SS automática pode esperar a trava da máquina)
    ],

    'app' => [
        'minutos_codigo' => 3,  // validade do código de uso único que o portal devolve ao app no login
        'dias_sem_uso' => 7,    // token sem uso por mais dias que isso deixa de valer (o app bloqueia antes: 48 h)
        'dias_validade_token' => 30, // validade máxima do token desde o login (celular perdido não vale para sempre)
        'horas_offline' => 48,  // o app bloqueia se ficar mais que isso sem sincronizar com o portal
        // Tela da máquina (operador): legenda do apontamento "sem motivo" que o IoT grava e que o operador tem de
        // trocar por um motivo; tipos de legenda oferecidos como motivo; até quantos dias para trás a pendência vale.
        'legenda_sem_motivo' => env('APP_LEGENDA_SEM_MOTIVO', '98'),
        'tipos_legenda_motivo' => ['parada'],
        'dias_pendencia_sem_motivo' => 7,
        'segundos_atualizacao' => 10, // de quanto em quanto tempo a tela da máquina se atualiza
        'pacote' => 'br.com.alumbra.oee', // só APK deste pacote pode ser publicado em Segurança › Aplicativo
        'tamanho_max_apk_mb' => 20, // upload_max_filesize do php.ini do servidor (php8.3/php.ini) é 20M
    ],

    'admins' => array_values(array_filter(array_map(
        fn ($e) => strtolower(trim($e)),
        explode(',', (string) env('PORTAL_ADMINS', 'marcato@alumbra.com.br'))
    ))),

    // Catálogo de permissões (telas) que os grupos podem dar. Ao criar uma tela nova, acrescente aqui
    // e proteja a rota com ->middleware('permissao:chave'). O administrador sempre tem todas.
    'permissoes' => [
        'dashboard_maquina' => 'Dashboard por máquina',
        'timeline' => 'Timeline das máquinas',
        'producao_hora' => 'Produção por hora (peças por hora do turno, meta, OP e produto, máquina a máquina)',
        'apontamentos' => 'Apontamento real (registrar estados das máquinas)',
        'apontamentos_producao' => 'Apontamentos OP (produções, baixas e notas das OPs no Protheus)',
        'ordens_producao' => 'Ordens de produção (consulta, custos e kardex)',
        'perdas_op' => 'Apontamento de perdas (registrar e excluir perdas das OPs, no portal e no aplicativo)',
        'empenhos_op' => 'Empenhos OP (SD4: materiais reservados para cada OP, consulta)',
        'falta_produtos' => 'Falta de produtos (ZB5: produtos faltando, com OP, máquina e apontamento real; painéis)',
        'estruturas' => 'Estruturas de produto (SG1, consulta)',
        'roteiros' => 'Roteiros de operação (SG2, consulta)',
        'ferramentas' => 'Ferramentas (SH4: consulta; administradores editam as cavidades ativas)',
        'mao_obra' => 'Mão de obra (SB1 tipo MO: consulta; administradores editam o fator de serviço da meta de produção)',
        'calculadora' => 'Calculadora de OEE',
        // Faturamento
        'faturamento_pedidos' => 'Faturamento: pedidos de venda (SC5/SC6/SC9 do Protheus por etapa: carteira, expedição, separação, conferência, logística, aguardando faturamento, faturados)',
        'faturamento_etapas' => 'Faturamento: etapas dos pedidos (regras dos status do WMS que definem separação, conferência, logística e aguardando faturamento)',
        'assistente' => 'Pergunte para Alumbra (assistente de IA na página inicial; responde só com os dados que o usuário pode ver)',
        // Ordens de serviço (manutenção de produção e de tecnologia; cada um vê a fila da sua unidade)
        'os_material' => 'Ordens de serviço: solicitação de material (mantenedores pedem; o administrador da área aprova item a item)',
        'os_painel' => 'Ordens de serviço: painel da área (chamados, OS por mantenedor, mensagens sem resposta)',
        'os_solicitar' => 'Ordens de serviço: abrir solicitações de serviço (SS) e acompanhar as suas',
        'os_aprovar' => 'Ordens de serviço: analisar SS (aprovar/rejeitar), distribuir e editar OS',
        'solicitacoes_manutencao' => 'Ordens de serviço: executar OS (apontar horas, materiais, checklist e concluir)',
        'os_almoxarifado' => 'Ordens de serviço: atender requisições de material (almoxarifado e compras)',
        'os_preventivas' => 'Ordens de serviço: preventivas (PAMP, planos e checklist)',
        'os_relatorios' => 'Ordens de serviço: relatórios de horas (intranet, contabilidade e diretoria)',
        // Tecnologia
        'inventario_ti' => 'Inventário de TI (equipamentos, licenças e linhas; consulta — os administradores da unidade cadastram)',
    ],

    // Prioridades da solicitação de material (código => descrição), na ordem da lista. "nivel" pinta a prioridade na tela:
    // critica (vermelho), alta (laranja) ou normal.
    'prioridades_material' => [
        'AD' => ['descricao' => 'Atendimento administrativo prioritário', 'nivel' => 'alta'],
        'AL' => ['descricao' => 'Atendimento à legislação', 'nivel' => 'alta'],
        'DG' => ['descricao' => 'Solicitado por diretoria/gerência — prioritário', 'nivel' => 'alta'],
        'IO' => ['descricao' => 'Impede a operação com contorno', 'nivel' => 'alta'],
        'IP' => ['descricao' => 'Iminente paralisação de máquina', 'nivel' => 'critica'],
        'MP' => ['descricao' => 'Máquina parada', 'nivel' => 'critica'],
        'NO' => ['descricao' => 'Normal', 'nivel' => 'normal'],
        'PD' => ['descricao' => 'Projeto e desenvolvimento', 'nivel' => 'normal'],
        'PF' => ['descricao' => 'Paralisa faturamento', 'nivel' => 'critica'],
        'PU' => ['descricao' => 'Manutenção predial de urgência', 'nivel' => 'critica'],
        'RI' => ['descricao' => 'Risco à integridade física', 'nivel' => 'critica'],
        'SE' => ['descricao' => 'Sem estoque', 'nivel' => 'alta'],
    ],

    // Custo da OP × custo meta (SB9): divergência dentro desta faixa (0,05 = ±5%) fica neutra; acima, em vermelho;
    // abaixo, em verde.
    'custo_tolerancia' => (float) env('OEE_CUSTO_TOLERANCIA', 0.05),

    // Cronômetro de horas da manutenção esquecido rodando: ao encerrar, conta no máximo estas horas (o resto, à mão).
    'cronometro_max_horas' => (int) env('OEE_CRONOMETRO_MAX_HORAS', 12),

    // Canais de cada dispositivo IoT. Os dados das máquinas virão da tabela MQTT.dbo.IOT001 (integração futura):
    // cada máquina usa dispositivo + canal, e um mesmo dispositivo + canal não pode estar em duas máquinas.
    // Leituras do IoT gravadas pelo Integrador MQTT (gráfico de ciclos do Dashboard por máquina; só leitura). No SQL
    // Server é outro banco da mesma instância: o login do portal precisa de SELECT nela
    // (database/scripts/permissao_leitura_iot001.sql). Conexão vazia = a padrão do portal.
    'iot_leituras' => [
        'conexao' => env('IOT_LEITURAS_CONEXAO'),
        'tabela' => env('IOT_LEITURAS_TABELA', 'MQTT.dbo.IOT001'),
    ],

    'iot_canais' => ['chd1_value', 'chd2_value', 'chd3_value', 'chd4_value', 'chd5_value', 'chd6_value'],

    // Perfis de acesso (um usuário pode ter vários). "area" = em que área o perfil aparece no cadastro de usuários;
    // "unidades" = ambientes dos cadastros que o perfil enxerga; "solicita" = áreas para as quais pode ABRIR SS (os
    // Solicitantes e os Administradores, cada um para a sua área); "permissoes" = telas ('*' = todas). Os grupos
    // continuam somando permissões. Três áreas: Manutenção, Tecnologia e Ferramentaria. O Administrador de Tecnologia vê
    // tudo de tudo, inclusive a Segurança.
    // Unidades em que a SS/OS pode ser aberta para uma FERRAMENTA/MOLDE (SH4) no lugar da máquina.
    'os_unidades_ferramenta' => ['manutencao', 'ferramentaria'],
    // Unidades em que a SS/OS pode ser aberta para um ITEM DO INVENTÁRIO DE TI no lugar da máquina.
    'os_unidades_item_ti' => ['tecnologia'],

    'perfis_acesso' => [
        // ----- Área Manutenção (produção) -----
        'operador' => [
            'rotulo' => 'Operador',
            'area' => 'manutencao',
            'descricao' => 'Informa o apontamento real, vê as ordens de produção e a produção por hora das máquinas da unidade.',
            'unidades' => ['manutencao'],
            'permissoes' => ['apontamentos', 'ordens_producao', 'producao_hora', 'perdas_op'],
        ],
        'solicitante_manutencao' => [
            'rotulo' => 'Solicitante de Manutenção',
            'area' => 'manutencao',
            'descricao' => 'Abre solicitações de serviço (SS) para as áreas da Manutenção e acompanha as suas e as do seu centro de custo.',
            'unidades' => [],
            'solicita' => ['manutencao'],
            'permissoes' => ['os_solicitar'],
        ],
        'mantenedor_producao' => [
            'rotulo' => 'Mantenedor de Produção',
            'area' => 'manutencao',
            'descricao' => 'Vê, aprova e executa as SS/OS das áreas executantes dele e as máquinas de manutenção nos dashboards.',
            'unidades' => ['manutencao'],
            'permissoes' => ['dashboard_maquina', 'timeline', 'producao_hora', 'solicitacoes_manutencao', 'os_aprovar', 'os_painel', 'os_material'],
        ],
        'admin_producao' => [
            'rotulo' => 'Administrador de Produção',
            'area' => 'manutencao',
            'descricao' => 'Vê e cadastra tudo da produção/manutenção (abre e vê todas as SS/OS da Manutenção), exceto a Segurança.',
            'unidades' => ['manutencao'],
            'solicita' => ['manutencao'],
            'permissoes' => ['*'],
        ],
        // ----- Área Tecnologia -----
        'solicitante_tecnologia' => [
            'rotulo' => 'Solicitante de Tecnologia',
            'area' => 'tecnologia',
            'descricao' => 'Abre solicitações de serviço (SS) para as áreas de Tecnologia e acompanha as suas e as do seu centro de custo.',
            'unidades' => [],
            'solicita' => ['tecnologia'],
            'permissoes' => ['os_solicitar'],
        ],
        'mantenedor_tecnologia' => [
            'rotulo' => 'Mantenedor de Tecnologia',
            'area' => 'tecnologia',
            'descricao' => 'Vê, aprova e executa as SS/OS das áreas executantes dele e vê equipamentos, o inventário de TI e dashboards de tecnologia.',
            'unidades' => ['tecnologia'],
            'permissoes' => ['dashboard_maquina', 'timeline', 'producao_hora', 'solicitacoes_manutencao', 'os_aprovar', 'os_painel', 'os_material', 'inventario_ti'],
        ],
        'admin_tecnologia' => [
            'rotulo' => 'Administrador de Tecnologia',
            'area' => 'tecnologia',
            'descricao' => 'Vê e cadastra tudo da tecnologia (abre e vê todas as SS/OS da Tecnologia) e cuida da Segurança (usuários, grupos, acessos e banco de dados). Outras unidades só com os perfis delas.',
            'unidades' => ['tecnologia'],
            'solicita' => ['tecnologia'],
            'permissoes' => ['*'],
        ],
        // ----- Área Ferramentaria -----
        'solicitante_ferramentaria' => [
            'rotulo' => 'Solicitante de Ferramentaria',
            'area' => 'ferramentaria',
            'descricao' => 'Abre solicitações de serviço (SS) para as áreas da Ferramentaria e acompanha as suas e as do seu centro de custo.',
            'unidades' => [],
            'solicita' => ['ferramentaria'],
            'permissoes' => ['os_solicitar'],
        ],
        'mantenedor_ferramentaria' => [
            'rotulo' => 'Mantenedor de Ferramentaria',
            'area' => 'ferramentaria',
            'descricao' => 'Vê, aprova e executa as SS/OS das áreas executantes dele e vê as máquinas da ferramentaria nos dashboards.',
            'unidades' => ['ferramentaria'],
            'permissoes' => ['dashboard_maquina', 'timeline', 'producao_hora', 'solicitacoes_manutencao', 'os_aprovar', 'os_painel', 'os_material'],
        ],
        'admin_ferramentaria' => [
            'rotulo' => 'Administrador de Ferramentaria',
            'area' => 'ferramentaria',
            'descricao' => 'Vê e cadastra tudo da ferramentaria (abre e vê todas as SS/OS da Ferramentaria), exceto a Segurança.',
            'unidades' => ['ferramentaria'],
            'solicita' => ['ferramentaria'],
            'permissoes' => ['*'],
        ],
    ],

    // Unidades (áreas) dos cadastros. "ambos" (valor gravado) = cadastro de TODAS as áreas.
    'unidades' => [
        'manutencao' => 'Manutenção',
        'tecnologia' => 'Tecnologia',
        'ferramentaria' => 'Ferramentaria',
        'ambos' => 'Todas',
    ],

    // Perfil de administrador de cada área (cadastros da área, aprovações e tudo o que é da área).
    'admins_area' => [
        'manutencao' => 'admin_producao',
        'tecnologia' => 'admin_tecnologia',
        'ferramentaria' => 'admin_ferramentaria',
    ],

    // Perfil de mantenedor de cada área.
    'mantenedores_area' => [
        'manutencao' => 'mantenedor_producao',
        'tecnologia' => 'mantenedor_tecnologia',
        'ferramentaria' => 'mantenedor_ferramentaria',
    ],

    // Hora em que o "dia de produção" começa na Timeline (o dia vai desta hora até a mesma hora do dia seguinte).
    'timeline_inicio_dia' => env('TIMELINE_INICIO_DIA', '06:00'),

    // Dispositivo IoT sem sinal há mais que estes minutos aparece como "sem sinal".
    'iot_minutos_sem_sinal' => (int) env('IOT_MINUTOS_SEM_SINAL', 5),

    // Datas ficam em UTC no banco e são exibidas neste fuso.
    'fuso_exibicao' => env('FUSO_EXIBICAO', 'America/Sao_Paulo'),
];
