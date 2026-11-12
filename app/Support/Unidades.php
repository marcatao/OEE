<?php

namespace App\Support;

use App\Models\Usuario;
use Illuminate\Validation\Rule;

/**
 * Campo "unidade" (área) dos cadastros: manutencao | tecnologia | ferramentaria | ambos (= todas).
 * Opções no formulário: cada área só para o administrador dela e "Todas" só para quem administra mais de uma. Ao editar, a unidade atual do registro continua aceita (não se perde).
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

    /** As áreas (sem o "ambos" = todas): manutencao, tecnologia, ferramentaria. @return list<string> */
    public static function areas(): array
    {
        return array_keys(config('oee.admins_area'));
    }

    /** Unidade sugerida num cadastro novo: a primeira que o usuário pode escolher. */
    public static function padrao(Usuario $usuario): string
    {
        return $usuario->unidadesParaCadastro()[0] ?? 'manutencao';
    }
}
