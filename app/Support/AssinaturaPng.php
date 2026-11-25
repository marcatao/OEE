<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Assinatura feita com o dedo/mouse num quadro (canvas): PNG em base64, com ou sem "data:image/png;base64,", até 600 KB.
 * A mesma regra para a avaliação da OS no aplicativo e o termo de responsabilidade do inventário de TI.
 */
final class AssinaturaPng
{
    /** Devolve o PNG em base64 (sem o prefixo) ou recusa com a mensagem no campo informado. */
    public static function conferir(?string $assinatura, string $campo = 'assinatura', string $mensagem = 'Assine no quadro.'): string
    {
        $base64 = preg_replace('#^data:image/png;base64,#', '', trim((string) $assinatura));
        $bytes = base64_decode((string) $base64, true);
        if ($bytes === false || ! str_starts_with($bytes, "\x89PNG") || strlen($bytes) > 600 * 1024 || strlen($bytes) < 100) {
            throw ValidationException::withMessages([$campo => $mensagem]);
        }

        return base64_encode($bytes);
    }
}
