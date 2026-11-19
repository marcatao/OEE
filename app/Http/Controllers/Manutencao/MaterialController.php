<?php

namespace App\Http\Controllers\Manutencao;

use App\Http\Controllers\Controller;
use App\Models\Manutencao\MaterialOs;
use App\Models\Manutencao\OrdemServico;
use App\Models\ProdutoProtheus;
use App\Services\Manutencao\FluxoOrdemServico;
use App\Support\AcessoOs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Materiais da OS: o mantenedor requisita ao almoxarifado; o almoxarifado disponibiliza (tinha em estoque) ou manda
 * para compra; compras registra a solicitação de compra e o recebimento; a entrega à manutenção fecha o ciclo.
 * Enquanto houver material pendente a OS pode ficar "aguardando material".
 */
class MaterialController extends Controller
{
    public const PENDENTES = ['requisitado', 'em_compra', 'recebido', 'disponivel'];

    public function __construct(private FluxoOrdemServico $fluxo) {}

    /** Fila do almoxarifado/compras. */
    public function index(Request $request): View
    {
        $status = (string) $request->input('status', 'pendentes');
        $consulta = MaterialOs::query()
            ->whereIn('ordem_servico_id', AcessoOs::filtrar(OrdemServico::query(), $request->user())->select('id'))
            ->with(['ordemServico.maquina' => fn ($q) => $q->withoutGlobalScopes(), 'criadoPor']);
        if ($status === 'pendentes') {
            $consulta->whereIn('status', self::PENDENTES);
        } elseif (isset(MaterialOs::STATUS[$status])) {
            $consulta->where('status', $status);
        }

        return view('manutencao.materiais.index', [
            'materiais' => $consulta->orderBy('id')->paginate(50)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function store(Request $request, int $ordem): RedirectResponse
    {
        $os = AcessoOs::filtrar(OrdemServico::query(), $request->user())->findOrFail($ordem);
        AcessoOs::exigirAberta($os);
        abort_unless(AcessoOs::podeExecutar($request->user(), $os), 403, 'Esta OS não é de uma área executante que você atende.');
        $dados = $request->validate([
            'produto_codigo' => ['nullable', 'string', 'max:30'],
            'descricao' => ['nullable', 'string', 'max:200'],
            'quantidade' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'um' => ['nullable', 'string', 'max:4'],
            'observacao' => ['nullable', 'string', 'max:500'],
        ], ['quantidade.required' => 'Informe a quantidade.', 'quantidade.gt' => 'A quantidade precisa ser maior que zero.']);
        $codigo = trim((string) ($dados['produto_codigo'] ?? ''));
        if ($codigo !== '') {
            $produto = ProdutoProtheus::where('b1_cod', $codigo)->first();
            $dados['descricao'] = ($dados['descricao'] ?? '') !== '' ? $dados['descricao'] : rtrim((string) $produto?->b1_desc);
            $dados['um'] = ($dados['um'] ?? '') !== '' ? $dados['um'] : rtrim((string) $produto?->b1_um);
        }
        if (trim((string) ($dados['descricao'] ?? '')) === '') {
            return back()->withErrors(['descricao' => 'Informe o código do produto ou a descrição do material.'])->withInput();
        }
        $material = MaterialOs::create($dados + ['ordem_servico_id' => $os->id, 'status' => 'requisitado']);
        $this->fluxo->registrar($os, null, 'material_requisitado', $os->status, $os->status,
            number_format((float) $material->quantidade, 2, ',', '.')." {$material->um} {$material->descricao}", $request->user());

        return back()->with('ok', 'Material requisitado ao almoxarifado.');
    }

    public function update(Request $request, int $material): RedirectResponse
    {
        $m = MaterialOs::findOrFail($material);
        $os = AcessoOs::filtrar(OrdemServico::query(), $request->user())->findOrFail($m->ordem_servico_id);
        AcessoOs::exigirAberta($os);
        // Status e custo do material: só o almoxarifado ou quem aprova OS da área (ver a OS não basta).
        abort_unless($request->user()->pode('os_almoxarifado') || AcessoOs::podeAprovar($request->user(), $os), 403,
            'Só o almoxarifado ou quem aprova OS desta área altera o material.');
        $dados = $request->validate([
            'status' => ['required', Rule::in(array_keys(MaterialOs::STATUS))],
            'solicitacao_compra' => ['nullable', 'string', 'max:30'],
            'custo_unitario' => ['nullable', 'numeric', 'min:0'],
            'observacao' => ['nullable', 'string', 'max:500'],
        ]);
        $anterior = $m->status;
        $m->fill($dados)->save();
        if ($anterior !== $m->status) {
            $this->fluxo->registrar($os, null, 'material_'.$m->status, $os->status, $os->status,
                "{$m->descricao}: ".(MaterialOs::STATUS[$anterior] ?? $anterior).' → '.MaterialOs::STATUS[$m->status], $request->user());
        }

        return back()->with('ok', 'Material atualizado.');
    }
}
