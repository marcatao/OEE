<?php

namespace App\Support;

use App\Models\CentroCusto;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\Mantenedor;
use App\Models\Maquina;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Quem vê o quê nas Ordens de serviço. A equipe (aprova, executa ou atende materiais) vê a fila das unidades dela
 * (Manutenção de Produção e/ou de Tecnologia); quem só solicita vê apenas as próprias SS/OS — de qualquer unidade.
 */
final class AcessoOs
{
    public static function ehEquipe(Usuario $u): bool
    {
        return $u->pode('os_aprovar') || $u->pode('solicitacoes_manutencao') || $u->pode('os_almoxarifado');
    }

    public static function podeAprovar(Usuario $u): bool
    {
        return $u->pode('os_aprovar');
    }

    public static function podeExecutar(Usuario $u): bool
    {
        return $u->pode('solicitacoes_manutencao') || $u->pode('os_aprovar');
    }

    /** Filtra uma consulta de SS ou OS pelo que o usuário pode ver. */
    public static function filtrar(Builder $consulta, Usuario $u): Builder
    {
        return self::ehEquipe($u)
            ? $consulta->visiveisPara($u)
            : $consulta->where($consulta->getModel()->qualifyColumn('solicitante_id'), $u->id);
    }

    /** Mantenedor ligado ao login do usuário (para apontar as próprias horas). */
    public static function mantenedorDo(Usuario $u): ?Mantenedor
    {
        return Mantenedor::withoutGlobalScopes()->where('usuario_id', $u->id)->where('ativo', true)->first();
    }

    /** Máquinas ativas de todas as unidades (quem solicita pode pedir serviço para qualquer área). */
    public static function maquinas(): Collection
    {
        return Maquina::withoutGlobalScope('unidade')->where('ativo', true)->orderBy('codigo')->get(['id', 'codigo', 'nome', 'centro_custo', 'unidade']);
    }

    public static function areas(): Collection
    {
        return AreaExecutante::withoutGlobalScope('unidade')->where('ativo', true)->orderBy('nome')->get();
    }

    public static function centrosCusto(): Collection
    {
        return CentroCusto::withoutGlobalScope('unidade')->selecionaveis()->orderBy('ctt_custo')->get(['ctt_custo', 'ctt_desc01']);
    }

    /** Mantenedores ativos das unidades que o usuário enxerga. */
    public static function mantenedores(): Collection
    {
        return Mantenedor::where('ativo', true)->orderBy('nome')->get();
    }
}
