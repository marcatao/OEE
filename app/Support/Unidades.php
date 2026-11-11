<?php

namespace App\Support;

use App\Models\Usuario;
use Illuminate\Validation\Rule;

/**
 * Campo "unidade" (ambiente) dos cadastros: manutencao | tecnologia | ambos.
 * Opções no formulário: Manutenção só para o Administrador de Produção, Tecnologia só para o Administrador de
 * Tecnologia e Ambos só para quem tem os dois. Ao editar, a unidade atual do registro continua aceita (não se perde).
 */
final class Unidades
{
    /** @return array<string, string> valor => rótulo */
    public static function opcoes(Usuario $usuario, ?string $atual = null): array
    {
        $todas = config('oee.unidades');
        $valores = $usuario->unidadesParaCadastro();
        if ($atual !== null && isset($todas[$atual]) && ! in_array($atual, $valores, true)) {
            $valores[] = $atual;
        }

        return array_intersect_key($todas, array_flip($valores));
    }

    /**
     * Regras de validação do campo. Se o formulário não mandar a unidade, vale a atual do registro (ou, num cadastro
     * novo, a padrão do usuário).
     */
    public static function regras(Usuario $usuario, ?string $atual = null): array
    {
        request()->mergeIfMissing(['unidade' => $atual ?? self::padrao($usuario)]);

        return ['required', Rule::in(array_keys(self::opcoes($usuario, $atual)))];
    }

    /** Unidade sugerida num cadastro novo: a primeira que o usuário pode escolher. */
    public static function padrao(Usuario $usuario): string
    {
        return $usuario->unidadesParaCadastro()[0] ?? 'manutencao';
    }
}
