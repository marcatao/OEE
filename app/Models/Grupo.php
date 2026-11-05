<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Grupo de usuários. Os grupos dão as permissões de tela (catálogo em config/oee.php).
 */
class Grupo extends Model
{
    use Auditavel;

    protected $table = 'grupos';

    protected $fillable = ['nome', 'descricao', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'grupo_usuario');
    }

    /** @return list<string> */
    public function permissoes(): array
    {
        return DB::table('grupo_permissoes')->where('grupo_id', $this->id)->orderBy('permissao')->pluck('permissao')->all();
    }

    /** Troca as permissões do grupo pelas informadas (só chaves do catálogo). */
    public function definirPermissoes(array $permissoes): void
    {
        $validas = array_values(array_intersect(array_keys(config('oee.permissoes')), $permissoes));

        DB::transaction(function () use ($validas) {
            DB::table('grupo_permissoes')->where('grupo_id', $this->id)->delete();
            DB::table('grupo_permissoes')->insert(
                array_map(fn ($p) => ['grupo_id' => $this->id, 'permissao' => $p], $validas)
            );
        });
    }
}
