<?php

namespace App\Services\InventarioTi;

use App\Models\CentroCusto;
use App\Models\InventarioTi\AtribuicaoLicenca;
use App\Models\InventarioTi\ItemTi;
use App\Models\InventarioTi\MovimentacaoItemTi;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regra única de gravação do Inventário de TI: salva o item e registra no histórico cada mudança de responsável,
 * local, centro de custo, situação e aparelho do chip. Mudou o responsável: os termos pendentes do item são
 * cancelados e, se houver um novo responsável (equipamento ou linha), nasce um termo PENDENTE para ele assinar.
 * Baixar o item tira o responsável e devolve as licenças que estavam instaladas nele.
 */
class MovimentacaoInventario
{
    /** Campos acompanhados no histórico (campo do item => campo da movimentação). */
    private const ACOMPANHADOS = ['responsavel_id' => 'responsavel', 'local' => 'local', 'centro_custo' => 'centro_custo',
        'situacao' => 'situacao', 'vinculado_item_id' => 'vinculado'];

    public function __construct(private TermosResponsabilidade $termos) {}

    public function salvar(ItemTi $item, array $dados, Usuario $por, ?string $observacao = null): ItemTi
    {
        return DB::transaction(function () use ($item, $dados, $por, $observacao) {
            $novo = ! $item->exists;
            $antes = $novo ? [] : $item->only(array_keys(self::ACOMPANHADOS));
            $item->fill($dados);

            if ($item->situacao === 'baixado') {
                $item->responsavel_id = null;
                $item->baixado_em ??= now();
            } else {
                $item->baixado_em = null;
                $item->motivo_baixa = null;
            }
            if ($item->situacao === 'em_uso' && ! $item->responsavel_id && blank($item->local) && ! $item->ehLicenca()) {
                throw ValidationException::withMessages(['responsavel_id' => 'Item em uso: informe o responsável ou o local.']);
            }
            $trocouResponsavel = $novo ? (bool) $item->responsavel_id : (int) $antes['responsavel_id'] !== (int) $item->responsavel_id;
            if ($trocouResponsavel) {
                $item->responsavel_desde = $item->responsavel_id ? now() : null;
            }
            $item->save();

            if ($novo) {
                $this->registrar($item, 'cadastro', null, $item->rotuloSituacao(), $observacao, $por);
                foreach (self::ACOMPANHADOS as $campo => $mov) {
                    if ($campo !== 'situacao' && filled($item->{$campo})) {
                        $this->registrar($item, $mov, null, $this->texto($campo, $item->{$campo}), null, $por);
                    }
                }
            } else {
                foreach (self::ACOMPANHADOS as $campo => $mov) {
                    if ((string) ($antes[$campo] ?? '') !== (string) ($item->{$campo} ?? '')) {
                        $this->registrar($item, $mov, $this->texto($campo, $antes[$campo] ?? null), $this->texto($campo, $item->{$campo}), $observacao, $por);
                    }
                }
            }

            if ($item->situacao === 'baixado') {
                $this->devolverLicencasDo($item, $por);
            }
            if ($trocouResponsavel) {
                $this->termos->cancelarPendentes($item, 'Responsável trocado', $por);
                if ($item->responsavel_id && $item->temTermo()) {
                    $this->termos->gerar($item, $por);
                }
            }

            return $item;
        });
    }

    /** Usa uma licença num computador do inventário e/ou para uma pessoa (sem passar da quantidade comprada). */
    public function atribuirLicenca(ItemTi $licenca, ?int $itemId, ?int $usuarioId, ?string $observacao, Usuario $por): AtribuicaoLicenca
    {
        if (! $licenca->ehLicenca()) {
            throw ValidationException::withMessages(['licenca' => 'Este item não é uma licença.']);
        }
        if ($licenca->situacao === 'baixado') {
            throw ValidationException::withMessages(['licenca' => 'Licença baixada não pode ser atribuída.']);
        }
        if (! $itemId && ! $usuarioId) {
            throw ValidationException::withMessages(['item_id' => 'Escolha o equipamento ou a pessoa que vai usar a licença.']);
        }

        return DB::transaction(function () use ($licenca, $itemId, $usuarioId, $observacao, $por) {
            $licenca = ItemTi::withoutGlobalScopes()->lockForUpdate()->findOrFail($licenca->id);
            if ($licenca->quantidade_licencas !== null && $licenca->licencasEmUso() >= $licenca->quantidade_licencas) {
                throw ValidationException::withMessages(['item_id' => "Todas as {$licenca->quantidade_licencas} licença(s) já estão em uso. Libere uma antes."]);
            }
            $a = AtribuicaoLicenca::create(['licenca_id' => $licenca->id, 'item_id' => $itemId, 'usuario_id' => $usuarioId,
                'atribuida_em' => now(), 'atribuida_por_id' => $por->id, 'observacao' => $observacao]);
            $this->registrar($licenca, 'licenca', null, 'Atribuída: '.$a->descricao(), $observacao, $por);
            if ($a->item) {
                $this->registrar($a->item, 'licenca', null, 'Licença instalada: '.$licenca->identificacao(), $observacao, $por);
            }

            return $a;
        });
    }

    public function removerAtribuicao(AtribuicaoLicenca $a, Usuario $por, ?string $observacao = null): void
    {
        if ($a->removida_em) {
            return;
        }
        DB::transaction(function () use ($a, $por, $observacao) {
            $a->forceFill(['removida_em' => now(), 'removida_por_id' => $por->id])->save();
            $this->registrar($a->licenca, 'licenca', 'Atribuída: '.$a->descricao(), 'Liberada', $observacao, $por);
            if ($a->item) {
                $this->registrar($a->item, 'licenca', 'Licença instalada: '.$a->licenca->identificacao(), 'Removida', $observacao, $por);
            }
        });
    }

    public function registrar(ItemTi $item, string $campo, ?string $de, ?string $para, ?string $observacao, ?Usuario $por): MovimentacaoItemTi
    {
        return MovimentacaoItemTi::create(['item_id' => $item->id, 'campo' => $campo,
            'valor_anterior' => $de !== null ? mb_substr($de, 0, 255) : null, 'valor_novo' => $para !== null ? mb_substr($para, 0, 255) : null,
            'observacao' => $observacao !== null ? mb_substr($observacao, 0, 500) : null, 'usuario_id' => $por?->id]);
    }

    private function devolverLicencasDo(ItemTi $item, Usuario $por): void
    {
        foreach (AtribuicaoLicenca::ativas()->where('item_id', $item->id)->get() as $a) {
            $this->removerAtribuicao($a, $por, 'Equipamento baixado');
        }
    }

    /** Valor legível para o histórico. */
    private function texto(string $campo, mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return match ($campo) {
            'responsavel_id' => Usuario::find((int) $valor)?->nome ?? "#{$valor}",
            'situacao' => ItemTi::SITUACOES[$valor] ?? (string) $valor,
            'vinculado_item_id' => ItemTi::withoutGlobalScopes()->find((int) $valor)?->identificacao() ?? "#{$valor}",
            'centro_custo' => trim($valor.' '.(CentroCusto::withoutGlobalScopes()->where('ctt_custo', $valor)->value('ctt_desc01') ?? '')),
            default => (string) $valor,
        };
    }
}
