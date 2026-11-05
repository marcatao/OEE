<?php

return [
    // Nome do portal exibido nas telas (não depende do APP_NAME do .env).
    'nome' => 'OEE Alumbra',

    // E-mails que NASCEM administradores no primeiro login. Todos os outros nascem "sem acesso" e um
    // administrador libera pelo menu Cadastros > Logins > Usuários.
    'admins' => array_values(array_filter(array_map(
        fn ($e) => strtolower(trim($e)),
        explode(',', (string) env('PORTAL_ADMINS', 'marcato@alumbra.com.br'))
    ))),

    // Catálogo de permissões (telas) que os grupos podem dar. Ao criar uma tela nova, acrescente aqui
    // e proteja a rota com ->middleware('permissao:chave'). O administrador sempre tem todas.
    'permissoes' => [
        'calculadora' => 'Calculadora de OEE',
    ],

    // Datas ficam em UTC no banco e são exibidas neste fuso.
    'fuso_exibicao' => env('FUSO_EXIBICAO', 'America/Sao_Paulo'),
];
