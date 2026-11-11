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
    ],

    // Custo da OP × custo meta (SB9): divergência dentro desta faixa (0,05 = ±5%) fica neutra; acima, em vermelho;
    // abaixo, em verde.
    'custo_tolerancia' => (float) env('OEE_CUSTO_TOLERANCIA', 0.05),

    // Perfis de acesso (um usuário pode ter vários). "unidade" = ambiente que o perfil enxerga (manutencao, tecnologia
    // ou as duas); "permissoes" = telas liberadas pelo perfil ('*' = todas do catálogo acima). Os grupos continuam
    // somando permissões. O Administrador de Tecnologia vê tudo de tudo, inclusive a Segurança.
    'perfis_acesso' => [
        'operador' => [
            'rotulo' => 'Operador',
            'descricao' => 'Informa o apontamento real e vê as ordens de produção.',
            'unidades' => ['manutencao'],
            'permissoes' => ['apontamentos', 'ordens_producao'],
        ],
        'admin_producao' => [
            'rotulo' => 'Administrador de Produção',
            'descricao' => 'Vê e cadastra tudo da produção/manutenção, exceto a Segurança.',
            'unidades' => ['manutencao'],
            'permissoes' => ['*'],
        ],
        'admin_tecnologia' => [
            'rotulo' => 'Administrador de Tecnologia',
            'descricao' => 'Vê tudo de tudo, inclusive a Segurança (usuários, grupos, acessos e banco de dados).',
            'unidades' => ['manutencao', 'tecnologia'],
            'permissoes' => ['*'],
        ],
        'mantenedor_producao' => [
            'rotulo' => 'Mantenedor de Produção',
            'descricao' => 'Solicitações de manutenção e as máquinas de manutenção nos dashboards.',
            'unidades' => ['manutencao'],
            'permissoes' => ['dashboard_maquina', 'timeline', 'solicitacoes_manutencao'],
        ],
        'mantenedor_tecnologia' => [
            'rotulo' => 'Mantenedor de Tecnologia',
            'descricao' => 'Vê o que é de tecnologia: equipamentos e dashboards de tecnologia.',
            'unidades' => ['tecnologia'],
            'permissoes' => ['dashboard_maquina', 'timeline', 'solicitacoes_manutencao'],
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
