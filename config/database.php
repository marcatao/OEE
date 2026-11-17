<?php

use Illuminate\Support\Str;

// Opções do driver SQL Server (Encrypt / TrustServerCertificate): "true"/"false" do .env viram texto, porque um
// false booleano entraria vazio na conexão ("TrustServerCertificate=") e o driver recusaria. Vazio = padrão do driver.
$opcaoSqlServer = static fn ($valor) => is_bool($valor) ? ($valor ? 'true' : 'false') : ($valor === '' ? null : $valor);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlsrv'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            // Instância nomeada: DB_HOST=SERVIDOR\INSTANCIA com DB_PORT vazio (o SQL Browser informa a porta).
            // Com porta preenchida o driver ignora o nome da instância e conecta direto em SERVIDOR,PORTA.
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT') ?: null, // vazio = 1433 na instância padrão
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => $opcaoSqlServer(env('DB_ENCRYPT')),
            'trust_server_certificate' => $opcaoSqlServer(env('DB_TRUST_SERVER_CERTIFICATE')),
        ],

        // Banco do Protheus (só leitura), usado pela sincronização (botão em Administração > Banco de dados e
        // "php artisan protheus:sincronizar"). Por padrão fica no MESMO servidor e usa o MESMO usuário do portal,
        // no banco "Compras"; preencha PROTHEUS_DB_* no .env só se for diferente.
        'protheus' => [
            'driver' => 'sqlsrv',
            'host' => env('PROTHEUS_DB_HOST') ?: env('DB_HOST', 'localhost'),
            'port' => env('PROTHEUS_DB_HOST') ? (env('PROTHEUS_DB_PORT') ?: null) : (env('DB_PORT') ?: null),
            'database' => env('PROTHEUS_DB_DATABASE') ?: 'Compras',
            'username' => env('PROTHEUS_DB_USERNAME') ?: env('DB_USERNAME', ''),
            'password' => env('PROTHEUS_DB_USERNAME') ? env('PROTHEUS_DB_PASSWORD', '') : env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => $opcaoSqlServer(env('PROTHEUS_DB_ENCRYPT') ?? env('DB_ENCRYPT')),
            'trust_server_certificate' => $opcaoSqlServer(env('PROTHEUS_DB_TRUST_SERVER_CERTIFICATE') ?? env('DB_TRUST_SERVER_CERTIFICATE')),
        ],

        // Tabelas MURO da integração de pedidos de compra (config/compras.php). Ficam no banco do Protheus e são as
        // ÚNICAS em que o portal grava por lá (INSERT e UPDATE só nelas; o resto do Protheus continua só leitura).
        // Por padrão usa o servidor, o banco e o usuário do Protheus; MURO_DB_* no .env só se for diferente.
        'muro' => [
            'driver' => 'sqlsrv',
            'host' => env('MURO_DB_HOST') ?: (env('PROTHEUS_DB_HOST') ?: env('DB_HOST', 'localhost')),
            'port' => env('MURO_DB_HOST') ? (env('MURO_DB_PORT') ?: null) : (env('PROTHEUS_DB_HOST') ? (env('PROTHEUS_DB_PORT') ?: null) : (env('DB_PORT') ?: null)),
            'database' => env('MURO_DB_DATABASE') ?: (env('PROTHEUS_DB_DATABASE') ?: 'Compras'),
            'username' => env('MURO_DB_USERNAME') ?: (env('PROTHEUS_DB_USERNAME') ?: env('DB_USERNAME', '')),
            'password' => env('MURO_DB_USERNAME') ? env('MURO_DB_PASSWORD', '') : (env('PROTHEUS_DB_USERNAME') ? env('PROTHEUS_DB_PASSWORD', '') : env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => $opcaoSqlServer(env('MURO_DB_ENCRYPT') ?? env('PROTHEUS_DB_ENCRYPT') ?? env('DB_ENCRYPT')),
            'trust_server_certificate' => $opcaoSqlServer(env('MURO_DB_TRUST_SERVER_CERTIFICATE') ?? env('PROTHEUS_DB_TRUST_SERVER_CERTIFICATE') ?? env('DB_TRUST_SERVER_CERTIFICATE')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
