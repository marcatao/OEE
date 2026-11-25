<?php

namespace App\Models\InventarioTi;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\TemUnidade;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Item do Inventário de TI (cadastrado só no portal): equipamento (hardware), licença de software ou linha/chip de
 * celular. Nunca é excluído: sai de uso com a situação "baixado". Mudanças de responsável, local, centro de custo e
 * situação vão para o histórico (ti_movimentacoes) pelo serviço App\Services\InventarioTi\MovimentacaoInventario.
 */
class ItemTi extends Model
{
    use Auditavel;
    use TemUnidade;

    public const CATEGORIAS = ['hardware' => 'Equipamento', 'licenca' => 'Licença de software', 'linha' => 'Linha / chip'];

    public const SITUACOES = ['em_uso' => 'Em uso', 'estoque' => 'Em estoque', 'manutencao' => 'Em manutenção', 'baixado' => 'Baixado'];

    protected $table = 'ti_itens';

    protected $fillable = ['unidade', 'categoria', 'tipo_id', 'patrimonio', 'descricao', 'marca', 'modelo', 'numero_serie', 'hostname',
        'especificacao', 'acessorios', 'centro_custo', 'local', 'responsavel_id', 'responsavel_desde', 'situacao', 'data_compra',
        'nota_fiscal', 'fornecedor', 'garantia_ate', 'valor', 'licenca_chave', 'quantidade_licencas', 'vencimento', 'linha_numero',
        'operadora', 'plano', 'vinculado_item_id', 'observacao', 'baixado_em', 'motivo_baixa'];

    protected $attributes = ['unidade' => 'tecnologia', 'situacao' => 'estoque', 'categoria' => 'hardware'];

    protected function casts(): array
    {
        return ['data_compra' => 'date', 'garantia_ate' => 'date', 'vencimento' => 'date', 'valor' => 'decimal:2',
            'quantidade_licencas' => 'integer', 'responsavel_desde' => 'datetime', 'baixado_em' => 'datetime'];
    }

    public function scopeAtivos(Builder $q): Builder
    {
        return $q->where('situacao', '<>', 'baixado');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoItemTi::class, 'tipo_id')->withoutGlobalScopes();
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsavel_id');
    }

    /** Linha/chip: o aparelho (celular, tablet) onde está. */
    public function vinculado(): BelongsTo
    {
        return $this->belongsTo(self::class, 'vinculado_item_id')->withoutGlobalScopes();
    }

    /** Aparelho: os chips que estão nele. */
    public function chips(): HasMany
    {
        return $this->hasMany(self::class, 'vinculado_item_id')->withoutGlobalScopes();
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(MovimentacaoItemTi::class, 'item_id');
    }

    public function termos(): HasMany
    {
        return $this->hasMany(TermoResponsabilidade::class, 'item_id');
    }

    /** Licença: onde/por quem está sendo usada. */
    public function atribuicoes(): HasMany
    {
        return $this->hasMany(AtribuicaoLicenca::class, 'licenca_id');
    }

    /** Equipamento: as licenças instaladas nele. */
    public function licencasInstaladas(): HasMany
    {
        return $this->hasMany(AtribuicaoLicenca::class, 'item_id');
    }

    public function solicitacoes(): HasMany
    {
        return $this->hasMany(SolicitacaoServico::class, 'item_ti_id');
    }

    public function ordensServico(): HasMany
    {
        return $this->hasMany(OrdemServico::class, 'item_ti_id');
    }

    public function ehHardware(): bool
    {
        return $this->categoria === 'hardware';
    }

    public function ehLicenca(): bool
    {
        return $this->categoria === 'licenca';
    }

    public function ehLinha(): bool
    {
        return $this->categoria === 'linha';
    }

    /** Recebe termo de responsabilidade ao ser entregue a alguém (equipamentos e linhas; licença não). */
    public function temTermo(): bool
    {
        return ! $this->ehLicenca();
    }

    public function rotuloCategoria(): string
    {
        return self::CATEGORIAS[$this->categoria] ?? (string) $this->categoria;
    }

    public function rotuloSituacao(): string
    {
        return self::SITUACOES[$this->situacao] ?? (string) $this->situacao;
    }

    /** Patrimônio (ou nº da linha, ou #id) — o "código" do item nas listas. */
    public function codigo(): string
    {
        return (string) ($this->patrimonio ?: ($this->ehLinha() && $this->linha_numero ? $this->linha_numero : '#'.$this->id));
    }

    /** Código + descrição, numa linha. */
    public function identificacao(): string
    {
        return $this->codigo().' — '.$this->descricao;
    }

    public function marcaModelo(): string
    {
        return trim(($this->marca ?? '').' '.($this->modelo ?? ''));
    }

    public function garantiaVencida(): bool
    {
        return $this->garantia_ate !== null && $this->garantia_ate->lt(today());
    }

    /** Licença: quantas estão em uso agora. */
    public function licencasEmUso(): int
    {
        return $this->atribuicoes()->ativas()->count();
    }

    /** O usuário pode cadastrar/alterar este item? Só o administrador da unidade dele ("Todas": quem administra alguma). */
    public function podeEditar(?Usuario $u): bool
    {
        if (! $u) {
            return false;
        }
        $areas = $u->areasAdministradas();

        return $this->unidade === 'ambos' ? $areas !== [] : in_array((string) $this->unidade, $areas, true);
    }
}
