<?php

namespace App\Services\Notificacoes;

use App\Models\Usuario;
use App\Services\Aplicativo\OrdensServicoAplicativo;
use App\Services\InventarioTi\TermosResponsabilidade;
use App\Support\AcessoOs;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Throwable;

/**
 * Notificações do usuário logado (sino do topo, pedido do usuário em 26/11/2026): o que antes aparecia em faixas no
 * topo de todas as páginas, num lugar só, e o que o navegador mostra em pop-up no Windows (public/js/componentes.js,
 * `[data-notificacoes]`, consultando a rota `notificacoes` a cada minuto).
 *
 * Cada item tem um `id` estável (tipo + registro): o navegador só mostra o pop-up de ids que ainda não viu. Uma fonte
 * que falha (banco sem a tabela) fica de fora sem derrubar a página.
 */
class NotificacoesUsuario
{
    public function __construct(private readonly OrdensServicoAplicativo $os, private readonly TermosResponsabilidade $termos) {}

    /** @return list<array{id: string, tipo: string, titulo: string, texto: string, url: string, quando: string|null, urgente: bool}> */
    public function para(Usuario $u): array
    {
        $itens = [];

        // SS esperando quem atende (mantenedor / administrador da área executante): a mesma lista do alerta do app.
        foreach ($this->fonte(fn () => $this->os->pendentesParaAprovar($u)) as $ss) {
            $maquina = $ss->maquina ? trim(rtrim((string) $ss->maquina->codigo).' · '.$ss->maquina->nome) : null;
            $itens[] = $this->item('ss-'.$ss->id, 'ss', $ss->maquina_parada ? 'Nova SS — máquina parada' : 'Nova SS para atender',
                $ss->numeroFormatado().' — '.Str::limit((string) $ss->titulo, 60).($maquina ? ' ('.$maquina.')' : ''),
                route('os.solicitacoes.show', $ss->id), $ss->criado_em ?? null, (bool) $ss->maquina_parada);
        }

        // OS concluídas esperando a avaliação do solicitante.
        foreach ($this->fonte(fn () => AcessoOs::pendentesDeAvaliacao($u)) as $os) {
            $itens[] = $this->item('os-avaliar-'.$os->id, 'avaliacao', 'OS concluída esperando a sua avaliação',
                $os->numeroFormatado().' — '.Str::limit((string) ($os->titulo ?: $os->descricao), 60),
                route('os.ordens.show', $os->id).'#avaliar', $os->concluida_em ?? null);
        }

        // Termos de responsabilidade do inventário de TI para assinar (como comodatário ou testemunha).
        foreach ($this->fonte(fn () => $this->termos->pendentesDe($u)) as $termo) {
            $comoTestemunha = (int) $termo->usuario_id !== (int) $u->id;
            $itens[] = $this->item('termo-'.$termo->id.($comoTestemunha ? '-t' : ''), 'termo',
                $comoTestemunha ? 'Termo para assinar como testemunha' : 'Termo de responsabilidade para assinar',
                $termo->numeroFormatado().' — '.Str::limit((string) $termo->item?->descricao, 60),
                route('inventario-ti.termos.show', $termo), $termo->criado_em ?? null);
        }

        return $itens;
    }

    private function item(string $id, string $tipo, string $titulo, string $texto, string $url, mixed $quando, bool $urgente = false): array
    {
        return ['id' => $id, 'tipo' => $tipo, 'titulo' => $titulo, 'texto' => $texto, 'url' => $url,
            'quando' => $quando instanceof CarbonInterface ? $quando->toIso8601String() : null, 'urgente' => $urgente];
    }

    /** @return iterable<mixed> */
    private function fonte(callable $ler): iterable
    {
        try {
            return $ler();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }
}
