<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistroLogin extends Model
{
    public $timestamps = false;

    protected $table = 'registros_login';

    protected $fillable = ['usuario_id', 'email', 'data_hora', 'sucesso', 'motivo', 'ip', 'navegador'];

    protected function casts(): array
    {
        return ['sucesso' => 'boolean', 'data_hora' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
