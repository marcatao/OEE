<?php

namespace App\Models\Manutencao;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ferramenta da manutenção. Com mantenedor_id = está com aquele mantenedor; vazio = avulsa. */
class Ferramenta extends Model
{
    use Auditavel;
    use TemUnidade;

    protected $table = 'os_ferramentas';

    protected $fillable = ['unidade', 'codigo', 'descricao', 'marca_modelo', 'numero_serie', 'mantenedor_id', 'atribuida_em', 'observacao', 'ativo'];

    protected $attributes = ['ativo' => true, 'unidade' => 'manutencao'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'atribuida_em' => 'datetime'];
    }

    public function mantenedor(): BelongsTo
    {
        return $this->belongsTo(Mantenedor::class, 'mantenedor_id')->withoutGlobalScope('unidade');
    }

    /** Atributos livres (nome e valor), na ordem em que foram informados. */
    public function atributos(): HasMany
    {
        return $this->hasMany(AtributoFerramenta::class)->orderBy('ordem')->orderBy('id');
    }

    /**
     * Grava a lista de atributos do formulário: atualiza os que continuam, cria os novos e tira os que o usuário removeu
     * da tela. Linhas sem nome são ignoradas.
     *
     * @param  list<array{nome?: ?string, valor?: ?string}>  $lista
     */
    public function gravarAtributos(array $lista): void
    {
        $linhas = collect($lista)->map(fn ($a) => ['nome' => trim((string) ($a['nome'] ?? '')), 'valor' => trim((string) ($a['valor'] ?? ''))])
            ->filter(fn ($a) => $a['nome'] !== '')->values();
        $atuais = $this->atributos()->get()->values();
        foreach ($linhas as $i => $a) {
            $registro = $atuais[$i] ?? new AtributoFerramenta(['ferramenta_id' => $this->id]);
            $registro->fill(['nome' => mb_substr($a['nome'], 0, 60), 'valor' => $a['valor'] !== '' ? mb_substr($a['valor'], 0, 255) : null, 'ordem' => $i])->save();
        }
        $atuais->slice($linhas->count())->each->delete(); // linhas removidas na tela
        $this->unsetRelation('atributos');
    }

    /** Troca o dono (ou deixa avulsa); a data só muda quando o dono muda. */
    public function entregarPara(?int $mantenedorId): void
    {
        if ((int) $this->mantenedor_id !== (int) $mantenedorId) {
            $this->mantenedor_id = $mantenedorId;
            $this->atribuida_em = $mantenedorId ? now() : null;
        }
    }

    public function avulsa(): bool
    {
        return $this->mantenedor_id === null;
    }
}
