<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legenda (estado) da timeline: Produzindo, Parada, Desligada, Setup... A cor é um dado do cadastro
 * (escolhida pelo administrador), por isso vai direto no estilo da barra.
 */
class Legenda extends Model
{
    use Auditavel;

    public const TIPOS = [
        'produzindo' => 'Produzindo',
        'parada' => 'Parada não planejada',
        'planejada' => 'Parada planejada',
        'desligada' => 'Desligada / sem programação',
    ];

    protected $table = 'legendas';

    protected $fillable = ['codigo', 'descricao', 'tipo', 'cor', 'ordem', 'ativo'];

    protected $attributes = ['ativo' => true, 'ordem' => 0];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'ordem' => 'integer'];
    }

    public function apontamentos(): HasMany
    {
        return $this->hasMany(ApontamentoEstado::class);
    }

    public function rotuloTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    /** Classe CSS do texto sobre a cor da legenda: escuro em cores claras, claro em cores escuras. */
    public function classeTexto(): string
    {
        $hex = ltrim((string) $this->cor, '#');
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return 'tl-texto-claro';
        }
        [$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split($hex, 2));
        $luminancia = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        return $luminancia > 0.6 ? 'tl-texto-escuro' : 'tl-texto-claro';
    }
}
