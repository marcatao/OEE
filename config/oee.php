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
        'apontamentos' => 'Apontamentos das máquinas (registrar estados)',
        'ordens_producao' => 'Ordens de produção (consulta)',
        'calculadora' => 'Calculadora de OEE',
    ],

    // Hora em que o "dia de produção" começa na Timeline (o dia vai desta hora até a mesma hora do dia seguinte).
    'timeline_inicio_dia' => env('TIMELINE_INICIO_DIA', '06:00'),

    // Dispositivo IoT sem sinal há mais que estes minutos aparece como "sem sinal".
    'iot_minutos_sem_sinal' => (int) env('IOT_MINUTOS_SEM_SINAL', 5),

    // Datas ficam em UTC no banco e são exibidas neste fuso.
    'fuso_exibicao' => env('FUSO_EXIBICAO', 'America/Sao_Paulo'),
];
