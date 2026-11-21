<?php

namespace App\Services\Assistente;

use Anthropic\Core\Exceptions;
use Throwable;

/**
 * Traduz uma falha ao chamar a Claude em: mensagem para o usuário, detalhe técnico (classe + mensagem da API) e dica
 * de como resolver. O detalhe e a dica vão para o log, para a pergunta (assistente_perguntas.erro), para o
 * Administrador de Tecnologia no box e para o comando "php artisan assistente:testar".
 */
final readonly class DiagnosticoErro
{
    public function __construct(
        public string $mensagem,
        public string $detalhe,
        public ?string $dica,
    ) {}

    public static function de(Throwable $e): self
    {
        $texto = self::mensagemApi($e);
        $detalhe = class_basename($e).': '.$texto;
        $tem = fn (string ...$trechos) => (bool) array_filter($trechos, fn ($t) => stripos($texto, $t) !== false);

        [$mensagem, $dica] = match (true) {
            $e instanceof \Error && $tem('Anthropic\\') && $tem('not found') => ['O assistente não está instalado no servidor.',
                'Rode "composer install" na pasta do portal (falta o pacote anthropic-ai/sdk).'],
            str_starts_with($e::class, 'Http\\Discovery\\') => ['O assistente não está instalado corretamente no servidor.',
                'Falta o cliente HTTP do PHP: rode "composer install" na pasta do portal.'],
            $e instanceof \RuntimeException && $tem('ANTHROPIC_API_KEY') => ['O assistente não está configurado.',
                'Coloque ANTHROPIC_API_KEY no .env e rode "php artisan config:clear" (ou config:cache, se usa cache).'],
            $e instanceof Exceptions\AuthenticationException => ['O assistente não está autorizado na Anthropic.',
                'A chave ANTHROPIC_API_KEY é inválida ou foi apagada: gere outra no console.anthropic.com e rode "php artisan config:clear".'],
            $e instanceof Exceptions\PermissionDeniedException => ['A chave da Anthropic não tem permissão para este uso.',
                'Confira no console.anthropic.com se a chave está ativa e se o modelo está liberado para a organização.'],
            $e instanceof Exceptions\NotFoundException => ['O modelo configurado para o assistente não existe.',
                'Confira ASSISTENTE_MODELO no .env (ex.: claude-opus-5-5 ou claude-haiku-5-5) e rode "php artisan config:clear".'],
            $tem('credit balance', 'billing', 'spend limit') => ['A conta da Anthropic está sem créditos ou atingiu o limite de gasto.',
                'Coloque créditos ou aumente o limite em console.anthropic.com › Settings › Billing / Limits.'],
            $tem('fallback') => ['O modelo configurado não aceita a troca automática de modelo.',
                'Coloque ASSISTENTE_FALLBACK=false no .env e rode "php artisan config:clear".'],
            $e instanceof Exceptions\RateLimitException => ['O assistente está com muitas perguntas agora. Tente de novo em instantes.',
                'Limite de requisições da conta Anthropic (aumenta conforme o uso; veja Settings › Limits).'],
            $e instanceof Exceptions\OverloadedException, $e instanceof Exceptions\ServiceUnavailableException,
            $e instanceof Exceptions\InternalServerException => ['O serviço da Claude está sobrecarregado. Tente de novo em instantes.', null],
            $tem('SSL', 'certificate', 'cURL error 60', 'cURL error 77') => ['Não foi possível falar com a Claude (certificado de segurança).',
                'O PHP não validou o certificado de api.anthropic.com: confira curl.cainfo e openssl.cafile no php.ini (cacert.pem).'],
            $e instanceof Exceptions\APITimeoutException, $tem('timed out', 'timeout') => ['A Claude demorou demais para responder. Tente de novo.',
                'Aumente ASSISTENTE_TIMEOUT (e o activityTimeout do FastCGI no IIS) se acontecer sempre.'],
            $e instanceof Exceptions\APIConnectionException, $tem('Could not resolve', 'Failed to connect', 'Connection refused') => [
                'Não foi possível falar com a Claude (rede).',
                'O servidor precisa acessar https://api.anthropic.com (porta 443): confira firewall e proxy.'],
            $e instanceof Exceptions\BadRequestException => ['A Claude não aceitou a pergunta. Comece uma nova conversa e tente de novo.', null],
            default => ['Não foi possível responder agora. Tente de novo.', null],
        };

        return new self($mensagem, mb_substr($detalhe, 0, 700), $dica);
    }

    /** Texto do erro: a mensagem da API ("error.message") quando houver; senão a mensagem da exceção, numa linha. */
    private static function mensagemApi(Throwable $e): string
    {
        $texto = $e->getMessage();
        if (preg_match('/"message"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $texto, $m)) {
            $status = preg_match('/"status"\s*:\s*(\d+)/', $texto, $s) ? "HTTP {$s[1]} - " : '';
            $texto = $status.stripcslashes($m[1]);
        }
        if ($e->getPrevious() && $e->getPrevious()->getMessage() !== '' && ! str_contains($texto, $e->getPrevious()->getMessage())) {
            $texto .= ' | '.$e->getPrevious()->getMessage();
        }

        return trim(preg_replace('/\s+/', ' ', $texto));
    }

    /** Linha completa para o log e para a coluna "erro" da pergunta. */
    public function registro(): string
    {
        return mb_substr($this->detalhe.($this->dica ? ' | Dica: '.$this->dica : ''), 0, 1000);
    }
}
