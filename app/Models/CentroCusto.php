<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Centro de custo (setor), no formato da tabela CTT do Protheus (mesmos nomes de coluna, em minúsculas).
 * Registros com d_e_l_e_t_ = '*' estão excluídos no Protheus; ctt_bloq = '1' é bloqueado.
 */
class CentroCusto extends Model
{
    use Auditavel;

    public const CLASSES = ['1' => 'Sintético', '2' => 'Analítico'];

    protected $table = 'centros_custo';

    protected $fillable = ['ctt_filial', 'ctt_custo', 'ctt_desc01', 'ctt_classe', 'ctt_bloq', 'ctt_ccsup', 'ctt_dtexis', 'ctt_dtexsf'];

    protected $attributes = ['ctt_filial' => '', 'ctt_classe' => '2', 'ctt_bloq' => '2', 'ctt_ccsup' => '', 'ctt_dtexis' => '', 'ctt_dtexsf' => '', 'd_e_l_e_t_' => ' '];

    /** Não excluídos no Protheus. */
    public function scopeValidos(Builder $q): Builder
    {
        return $q->where('d_e_l_e_t_', '<>', '*');
    }

    /** Disponíveis para escolher na máquina: válidos, não bloqueados e analíticos. */
    public function scopeSelecionaveis(Builder $q): Builder
    {
        return $q->validos()->where('ctt_bloq', '<>', '1')->where('ctt_classe', '2');
    }

    /** Campos caractere do Protheus vêm com espaços à direita. */
    public function codigo(): string
    {
        return rtrim((string) $this->ctt_custo);
    }

    public function descricao(): string
    {
        return rtrim((string) $this->ctt_desc01);
    }

    public function bloqueado(): bool
    {
        return $this->ctt_bloq === '1';
    }

    public function excluido(): bool
    {
        return $this->d_e_l_e_t_ === '*';
    }

    public function veioDoProtheus(): bool
    {
        return $this->r_e_c_n_o_ !== null;
    }

    /** 'AAAAMMDD' → 'dd/mm/aaaa'. */
    public static function dataProtheus(?string $valor): string
    {
        $v = trim((string) $valor);

        return strlen($v) === 8 ? substr($v, 6, 2).'/'.substr($v, 4, 2).'/'.substr($v, 0, 4) : '-';
    }
}
