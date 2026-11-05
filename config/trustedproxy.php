<?php

return [
    // Atrás de proxy reverso com HTTPS (Caddy, IIS, Nginx): IPs dos proxies confiáveis, separados por vírgula
    // (ex.: 127.0.0.1), ou * se o portal só é acessível pelo proxy. Assim o Laravel enxerga o endereço https
    // original e monta certo os links e o retorno do login.
    'proxies' => env('TRUSTED_PROXIES'),
];
