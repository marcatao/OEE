<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Falta de produto (cópia da ZB5 do Protheus): um produto que está faltando ser produzido, com a quantidade
 * solicitada e a atendida, quem pediu e quem atendeu. Só a sincronização grava aqui.
 * Status (ZB5_STATUS): P = pendente, A = atendida parcialmente, C = concluída, D = cancelada.
 */
class FaltaProduto extends Model
{
    public $timestamps = false;

    protected $table = 'faltas_produto';

    protected $guarded = [];

    /** Legendas do Protheus (browse da ZB5), na ordem da tela. "cor" vira a classe falta-<cor> (portal.css). */
    public const STATUS = [
        'P' => ['rotulo' => 'Solicitação pendente', 'curto' => 'Pendente', 'cor' => 'verde'],
        'A' => ['rotulo' => 'Atendida parcialmente', 'curto' => 'Parcial', 'cor' => 'amarelo'],
        'C' => ['rotulo' => 'Solicitação concluída', 'curto' => 'Concluída', 'cor' => 'vermelho'],
        'D' => ['rotulo' => 'Solicitação cancelada', 'curto' => 'Cancelada', 'cor' => 'preto'],
    ];

    /** Status em que o produto ainda falta (a produção importa). */
    public const EM_ABERTO = ['P', 'A'];

    protected function casts(): array
    {
        return ['zb5_qtdsol' => 'float', 'zb5_qtdate' => 'float', 'zb5_vlped' => 'float', 'r_e_c_n_o_' => 'integer',
            'solicitado_em' => 'datetime', 'atendido_em' => 'datetime', 'sincronizado_em' => 'datetime'];
    }

    /** Não excluídas no Protheus. */
    public function scopeValidas(Builder $q): Builder
    {
        return $q->where('d_e_l_e_t_', '<>', '*');
    }

    public function status(): string
    {
        $s = strtoupper(trim((string) $this->zb5_status));

        return isset(self::STATUS[$s]) ? $s : 'P';
    }

    public function emAberto(): bool
    {
        return in_array($this->status(), self::EM_ABERTO, true);
    }

    public function produto(): string
    {
        return rtrim((string) $this->zb5_produt);
    }

    /** Quanto ainda falta atender (solicitada − atendida); 0 se encerrada. */
    public function saldo(): float
    {
        return $this->emAberto() ? max(0.0, $this->zb5_qtdsol - $this->zb5_qtdate) : 0.0;
    }

    /** Minutos entre a solicitação e o atendimento (null sem as duas datas ou com atendimento antes do pedido). */
    public function minutosAtendimento(): ?float
    {
        if (! $this->solicitado_em || ! $this->atendido_em || $this->atendido_em->lt($this->solicitado_em)) {
            return null;
        }

        return ($this->atendido_em->getTimestamp() - $this->solicitado_em->getTimestamp()) / 60;
    }

    /** Há quanto tempo está aberta (minutos até agora); null se encerrada ou sem data. */
    public function minutosEmAberto(?CarbonInterface $agora = null): ?float
    {
        if (! $this->emAberto() || ! $this->solicitado_em) {
            return null;
        }

        return max(0, (($agora ?? now())->getTimestamp() - $this->solicitado_em->getTimestamp()) / 60);
    }
}
