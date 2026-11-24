<?php

namespace App\Services;

use App\Models\OrdemProducao;
use App\Models\PerdaOp;
use App\Models\ProdutoProtheus;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Apontamento de perdas da OP (pedido do usuário em 24/11/2026) — regra única do portal e do aplicativo.
 *
 * - PU (peso unitário, kg por peça) = B1_PESO do produto da OP na SB1 (produtos_protheus.b1_peso).
 * - Calculadora: informada a QUANTIDADE, peso = quantidade × PU; informado só o PESO, quantidade = peso ÷ PU (sem PU,
 *   só pela quantidade). O PU usado fica gravado na perda (mudar a SB1 depois não muda o que já foi apontado).
 * - Total: ordens_producao.quantidade_perda = soma das perdas NÃO excluídas (campo só do portal; C2_PERDA não muda).
 * - Excluir não apaga: grava quem e quando; a lista mostra "Excluído por Fulano" e o total desconta.
 */
class PerdasOrdemProducao
{
    /** PU do produto da OP (kg por peça) ou null se a SB1 não tiver peso. */
    public function pesoUnitario(OrdemProducao $op): ?float
    {
        $peso = ProdutoProtheus::where('b1_cod', rtrim((string) $op->c2_produto))->where('d_e_l_e_t_', '<>', '*')
            ->orderByRaw('CASE WHEN b1_filial = ? THEN 0 ELSE 1 END', [(string) $op->c2_filial])->value('b1_peso');

        return $peso !== null && (float) $peso > 0 ? (float) $peso : null;
    }

    /**
     * Grava uma perda. $dados: quantidade e/ou peso (pelo menos um > 0), dia (Y-m-d, padrão hoje; nunca no futuro),
     * maquina_id e observacao opcionais.
     *
     * @throws ValidationException
     */
    public function registrar(OrdemProducao $op, Usuario $usuario, array $dados, string $origem = 'portal'): PerdaOp
    {
        if ($op->d_e_l_e_t_ === '*') {
            throw ValidationException::withMessages(['quantidade' => 'Esta OP foi excluída no Protheus.']);
        }
        $pu = $this->pesoUnitario($op);
        $quantidade = self::numero($dados['quantidade'] ?? null);
        $peso = self::numero($dados['peso'] ?? null);
        if ($quantidade > 0) {
            $peso = $pu !== null ? round($quantidade * $pu, 4) : ($peso > 0 ? $peso : null);
        } elseif ($peso > 0) {
            if ($pu === null) {
                throw ValidationException::withMessages(['quantidade' => 'O produto não tem peso unitário (B1_PESO) no Protheus: informe a quantidade de peças.']);
            }
            $quantidade = round($peso / $pu, 4);
        } else {
            throw ValidationException::withMessages(['quantidade' => 'Informe a quantidade de peças perdidas ou o peso.']);
        }
        if ($quantidade > 9_999_999 || ($peso ?? 0) > 99_999_999) {
            throw ValidationException::withMessages(['quantidade' => 'Quantidade ou peso grande demais: confira o valor digitado.']);
        }
        $fuso = HorarioApontamento::fuso();
        $dia = isset($dados['dia']) && $dados['dia'] !== '' ? CarbonImmutable::parse($dados['dia'], $fuso)->startOfDay() : CarbonImmutable::now($fuso)->startOfDay();
        if ($dia->greaterThan(CarbonImmutable::now($fuso)->startOfDay())) {
            throw ValidationException::withMessages(['dia' => 'O dia da perda não pode ser no futuro.']);
        }

        return DB::transaction(function () use ($op, $usuario, $dados, $origem, $pu, $quantidade, $peso, $dia) {
            $perda = new PerdaOp([
                'ordem_producao_id' => $op->id,
                'maquina_id' => ! empty($dados['maquina_id']) ? (int) $dados['maquina_id'] : null,
                'dia' => $dia->format('Y-m-d'),
                'quantidade' => $quantidade,
                'peso' => $peso,
                'peso_unitario' => $pu,
                'origem' => $origem === 'aplicativo' ? 'aplicativo' : 'portal',
                'observacao' => isset($dados['observacao']) ? mb_substr(trim((string) $dados['observacao']), 0, 500) ?: null : null,
            ]);
            $perda->criado_por_id = $usuario->id;
            $perda->save();
            $this->recalcular((int) $op->id);

            return $perda;
        });
    }

    /** Exclui (marca como excluída por quem e quando) e desconta do total da OP. */
    public function excluir(PerdaOp $perda, Usuario $usuario): void
    {
        if ($perda->excluida()) {
            throw ValidationException::withMessages(['perda' => 'Esta perda já foi excluída.']);
        }
        DB::transaction(function () use ($perda, $usuario) {
            $perda->forceFill(['excluido_em' => now(), 'excluido_por_id' => $usuario->id])->save();
            $this->recalcular((int) $perda->ordem_producao_id);
        });
    }

    /** Soma as perdas não excluídas em ordens_producao.quantidade_perda. */
    public function recalcular(int $opId): float
    {
        $total = (float) PerdaOp::where('ordem_producao_id', $opId)->whereNull('excluido_em')->sum('quantidade');
        DB::table('ordens_producao')->where('id', $opId)->update(['quantidade_perda' => $total]);

        return $total;
    }

    /** Perdas da OP (as excluídas também, para a lista), das mais recentes para as mais antigas. */
    public function daOp(OrdemProducao $op): Collection
    {
        return PerdaOp::with(['criadoPor:id,nome', 'excluidoPor:id,nome', 'maquina:id,codigo,nome'])
            ->where('ordem_producao_id', $op->id)->orderByDesc('dia')->orderByDesc('id')->limit(500)->get();
    }

    /** Resumo para telas e API: PU, total (peças e kg) e quantidade de apontamentos ativos. */
    public function resumo(OrdemProducao $op, ?Collection $perdas = null): array
    {
        $perdas ??= $this->daOp($op);
        $ativas = $perdas->filter(fn (PerdaOp $p) => ! $p->excluida());

        return [
            'peso_unitario' => $this->pesoUnitario($op),
            'total_quantidade' => round((float) $ativas->sum('quantidade'), 4),
            'total_peso' => round((float) $ativas->sum(fn ($p) => (float) $p->peso), 4),
            'apontamentos' => $ativas->count(),
        ];
    }

    /** Número digitado com vírgula ou ponto ("1.234,5", "12,5", "12.5"). */
    public static function numero(mixed $valor): float
    {
        if ($valor === null || $valor === '') {
            return 0.0;
        }
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        $texto = trim((string) $valor);
        if (str_contains($texto, ',')) {
            $texto = str_replace(['.', ','], ['', '.'], $texto);
        }

        return is_numeric($texto) ? max(0.0, (float) $texto) : 0.0;
    }
}
