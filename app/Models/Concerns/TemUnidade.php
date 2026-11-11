<?php

namespace App\Models\Concerns;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Cadastro com "unidade" (ambiente): manutencao | tecnologia | ambos (config oee.unidades).
 * Filtro automático: o usuário logado só enxerga os registros das unidades dele e os de "ambos"; o Administrador de
 * Tecnologia (ou quem tem os dois ambientes) vê tudo. Sem usuário logado (comandos, sincronização agendada), sem filtro.
 */
trait TemUnidade
{
    public static function bootTemUnidade(): void
    {
        static::addGlobalScope('unidade', function (Builder $consulta) {
            $usuario = Auth::hasUser() ? Auth::user() : null;
            if (! $usuario instanceof Usuario) {
                return;
            }
            $visiveis = $usuario->unidadesVisiveis();
            if ($visiveis !== null) {
                $consulta->whereIn($consulta->getModel()->qualifyColumn('unidade'), $visiveis);
            }
        });
    }

    public function rotuloUnidade(): string
    {
        return config('oee.unidades')[$this->unidade ?? 'manutencao'] ?? (string) $this->unidade;
    }
}
