<?php

return [
    // Nome do portal exibido nas telas (não depende do APP_NAME do .env).
    'nome' => 'OEE Alumbra',

    // Nome curto do sistema, exibido ao lado do logotipo da Alumbra no topo e no menu lateral.
    'sigla' => 'OEE',
    'descricao' => 'Eficiência Global dos Equipamentos',

    // E-mails que NASCEM administradores no primeiro login. Todos os outros nascem "sem acesso" e um
    // administrador libera pelo menu Administração > Logins > Usuários.
    'admins' => array_values(array_filter(array_map(
        fn ($e) => strtolower(trim($e)),
        explode(',', (string) env('PORTAL_ADMINS', 'marcato@alumbra.com.br'))
    ))),

    // Catálogo de permissões (telas) que os grupos podem dar. Ao criar uma tela nova, acrescente aqui
    // e proteja a rota com ->middleware('permissao:chave'). O administrador sempre tem todas.
    'permissoes' => [
        'dashboard_maquina' => 'Dashboard por máquina',
        'timeline' => 'Timeline das máquinas',
        'apontamentos' => 'Apontamento real (registrar estados das máquinas)',
        'apontamentos_producao' => 'Apontamentos OP (produções, baixas e notas das OPs no Protheus)',
        'ordens_producao' => 'Ordens de produção (consulta, custos e kardex)',
        'calculadora' => 'Calculadora de OEE',
        // Ordens de serviço (manutenção de produção e de tecnologia; cada um vê a fila da sua unidade)
        'os_solicitar' => 'Ordens de serviço: abrir solicitações de serviço (SS) e acompanhar as suas',
        'os_aprovar' => 'Ordens de serviço: analisar SS (aprovar/rejeitar), distribuir e editar OS',
        'solicitacoes_manutencao' => 'Ordens de serviço: executar OS (apontar horas, materiais, checklist e concluir)',
        'os_almoxarifado' => 'Ordens de serviço: atender requisições de material (almoxarifado e compras)',
        'os_preventivas' => 'Ordens de serviço: preventivas (PAMP, planos e checklist)',
        'os_relatorios' => 'Ordens de serviço: relatórios de horas (intranet, contabilidade e diretoria)',
    ],

    // Custo da OP × custo meta (SB9): divergência dentro desta faixa (0,05 = ±5%) fica neutra; acima, em vermelho;
    // abaixo, em verde.
    'custo_tolerancia' => (float) env('OEE_CUSTO_TOLERANCIA', 0.05),

    // Perfis de acesso (um usuário pode ter vários). "area" = em que área o perfil aparece no cadastro de usuários;
    // "unidades" = ambientes dos cadastros que o perfil enxerga; "solicita" = áreas para as quais pode ABRIR SS (só os
    // Solicitantes, e o Administrador de Tecnologia, que pode tudo); "permissoes" = telas ('*' = todas). Os grupos
    // continuam somando permissões. O Administrador de Tecnologia vê tudo de tudo, inclusive a Segurança.
    'perfis_acesso' => [
        // ----- Área Manutenção (produção) -----
        'operador' => [
            'rotulo' => 'Operador',
            'area' => 'manutencao',
            'descricao' => 'Informa o apontamento real e vê as ordens de produção.',
            'unidades' => ['manutencao'],
            'permissoes' => ['apontamentos', 'ordens_producao'],
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
            'permissoes' => ['dashboard_maquina', 'timeline', 'solicitacoes_manutencao', 'os_aprovar'],
        ],
        'admin_producao' => [
            'rotulo' => 'Administrador de Produção',
            'area' => 'manutencao',
            'descricao' => 'Vê e cadastra tudo da produção/manutenção (todas as SS/OS da Manutenção), exceto a Segurança.',
            'unidades' => ['manutencao'],
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
            'descricao' => 'Vê, aprova e executa as SS/OS das áreas executantes dele e vê equipamentos e dashboards de tecnologia.',
            'unidades' => ['tecnologia'],
            'permissoes' => ['dashboard_maquina', 'timeline', 'solicitacoes_manutencao', 'os_aprovar'],
        ],
        'admin_tecnologia' => [
            'rotulo' => 'Administrador de Tecnologia',
            'area' => 'tecnologia',
            'descricao' => 'Vê tudo de tudo, inclusive a Segurança (usuários, grupos, acessos e banco de dados).',
            'unidades' => ['manutencao', 'tecnologia'],
            'solicita' => ['manutencao', 'tecnologia'],
            'permissoes' => ['*'],
        ],
    ],

    // Unidades (ambientes) dos cadastros.
    'unidades' => [
        'manutencao' => 'Manutenção',
        'tecnologia' => 'Tecnologia',
        'ambos' => 'Ambos',
    ],

    // Hora em que o "dia de produção" começa na Timeline (o dia vai desta hora até a mesma hora do dia seguinte).
    'timeline_inicio_dia' => env('TIMELINE_INICIO_DIA', '06:00'),

    // Dispositivo IoT sem sinal há mais que estes minutos aparece como "sem sinal".
    'iot_minutos_sem_sinal' => (int) env('IOT_MINUTOS_SEM_SINAL', 5),

    // Datas ficam em UTC no banco e são exibidas neste fuso.
    'fuso_exibicao' => env('FUSO_EXIBICAO', 'America/Sao_Paulo'),
];
