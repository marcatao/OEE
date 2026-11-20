<?php

namespace App\Http\Controllers\Manutencao;

use App\Http\Controllers\Controller;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\PlanoPreventiva;
use App\Models\Manutencao\PlanoPreventivaItem;
use App\Models\Manutencao\SolicitacaoServico;
use App\Services\Manutencao\GeradorPreventivas;
use App\Support\AcessoOs;
use App\Support\Unidades;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ordens de serviço › Preventivas (PAMP): planos com checklist e periodicidade e o calendário anual (meses programados
 * × OS geradas). O sistema gera as OS sozinho (comando os:gerar-preventivas, todo dia) ou pelo botão.
 */
class PlanoPreventivaController extends Controller
{
    public function index(Request $request): View
    {
        $ano = max(2000, min(2100, $request->integer('ano') ?: (int) now(config('oee.fuso_exibicao'))->year));
        // Filtro de unidade: só as do login; escolhida uma, os planos dela e os de "Todas".
        [$unidades, $unidade] = AcessoOs::filtroUnidade($request->user(), $request->query('unidade'));
        $planos = PlanoPreventiva::when($unidade !== '', fn ($q) => $q->whereIn('unidade', [$unidade, 'ambos']))->with(['maquina' => fn ($q) => $q->withoutGlobalScopes(), 'areaExecutante' => fn ($q) => $q->withoutGlobalScopes(),
            'responsavel' => fn ($q) => $q->withoutGlobalScopes()])->withCount('itens')
            ->orderByDesc('ativo')->orderBy('codigo')->get();

        // OS geradas no ano, por plano e mês (situação para o calendário).
        $ordens = OrdemServico::whereIntegerInRaw('plano_preventiva_id', $planos->pluck('id')->map(fn ($id) => (int) $id)->all())
            ->whereBetween('prevista_para', ["{$ano}-01-01", "{$ano}-12-31"])
            ->get(['id', 'plano_preventiva_id', 'prevista_para', 'status']);
        $calendario = [];
        foreach ($ordens as $os) {
            $calendario[$os->plano_preventiva_id][(int) $os->prevista_para->format('n')][] = $os;
        }

        return view('manutencao.preventivas.index', compact('planos', 'ano', 'calendario', 'unidades', 'unidade'));
    }

    public function create(Request $request): View
    {
        return view('manutencao.preventivas.form', $this->dados(new PlanoPreventiva([
            'ativo' => true, 'periodicidade' => 'meses', 'meses' => '111111111111', 'dia_mes' => 1, 'antecedencia_dias' => 7, 'prioridade' => 'baixa',
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $plano = new PlanoPreventiva;
        $this->salvar($request, $plano);

        return redirect()->route('os.preventivas.index')->with('ok', "Preventiva {$plano->codigo} cadastrada. Próxima: ".($plano->proxima_em?->format('d/m/Y') ?? '-').'.');
    }

    public function edit(int $preventiva): View
    {
        return view('manutencao.preventivas.form', $this->dados(PlanoPreventiva::with('itens')->findOrFail($preventiva)));
    }

    public function update(Request $request, int $preventiva): RedirectResponse
    {
        $plano = PlanoPreventiva::findOrFail($preventiva);
        $this->salvar($request, $plano);

        return redirect()->route('os.preventivas.index')->with('ok', "Preventiva {$plano->codigo} atualizada.");
    }

    public function gerar(GeradorPreventivas $gerador): RedirectResponse
    {
        $criadas = $gerador->gerar();

        return back()->with('ok', $criadas ? count($criadas).' OS preventiva(s) gerada(s).' : 'Nenhuma preventiva para gerar agora.');
    }

    private function dados(PlanoPreventiva $plano): array
    {
        return [
            'plano' => $plano,
            'itens' => $plano->exists ? $plano->itens()->orderBy('ordem')->orderBy('id')->get() : collect(),
            'maquinas' => AcessoOs::maquinas(),
            'areas' => AcessoOs::areas(),
            'mantenedores' => AcessoOs::mantenedores(),
        ];
    }

    private function salvar(Request $request, PlanoPreventiva $plano): void
    {
        $dados = $request->validate([
            'unidade' => Unidades::regras($request->user(), $plano->exists ? $plano->unidade : null),
            'codigo' => ['required', 'string', 'max:20', Rule::unique('os_planos_preventiva', 'codigo')->ignore($plano->id)],
            'descricao' => ['required', 'string', 'max:200'],
            'maquina_id' => ['nullable', 'integer', Rule::exists('maquinas', 'id')],
            'area_executante_id' => ['required', 'integer', Rule::exists('os_areas_executantes', 'id')],
            'responsavel_id' => ['nullable', 'integer', Rule::exists('os_mantenedores', 'id')],
            'prioridade' => ['required', Rule::in(array_keys(SolicitacaoServico::PRIORIDADES))],
            'periodicidade' => ['required', Rule::in(array_keys(PlanoPreventiva::PERIODICIDADES))],
            'meses' => ['array'], 'meses.*' => ['integer', 'between:1,12'],
            'dia_mes' => ['nullable', 'integer', 'between:1,28'],
            'proxima_em' => ['nullable', 'date'],
            'antecedencia_dias' => ['nullable', 'integer', 'between:0,90'],
            'duracao_prevista_min' => ['nullable', 'integer', 'between:1,100000'],
            'ativo' => ['required', 'boolean'],
            'itens' => ['array'], 'itens.*.id' => ['nullable', 'integer'], 'itens.*.descricao' => ['nullable', 'string', 'max:300'],
            'itens.*.ativo' => ['nullable', 'boolean'],
        ], [
            'codigo.required' => 'Informe o código da preventiva.', 'codigo.unique' => 'Já existe uma preventiva com esse código.',
            'descricao.required' => 'Informe a descrição.', 'area_executante_id.required' => 'Escolha a área executante.',
        ]);
        $marcados = array_map('intval', $dados['meses'] ?? []);
        $mascara = implode('', array_map(fn ($m) => in_array($m, $marcados, true) ? '1' : '0', range(1, 12)));
        if ($dados['periodicidade'] === 'meses' && ! str_contains($mascara, '1')) {
            back()->withErrors(['meses' => 'Marque pelo menos um mês.'])->withInput()->throwResponse();
        }
        if ($dados['periodicidade'] !== 'meses' && empty($dados['proxima_em'])) {
            back()->withErrors(['proxima_em' => 'Informe a data da primeira preventiva (define o dia da semana).'])->withInput()->throwResponse();
        }

        DB::transaction(function () use ($plano, $dados, $mascara) {
            $mudouProgramacao = ! $plano->exists || $plano->periodicidade !== $dados['periodicidade'] || $plano->meses !== $mascara
                || (int) $plano->dia_mes !== (int) ($dados['dia_mes'] ?? 1);
            $plano->fill([
                'unidade' => $dados['unidade'], 'codigo' => mb_strtoupper(trim($dados['codigo'])), 'descricao' => $dados['descricao'],
                'maquina_id' => $dados['maquina_id'] ?? null, 'area_executante_id' => $dados['area_executante_id'],
                'responsavel_id' => $dados['responsavel_id'] ?? null, 'prioridade' => $dados['prioridade'],
                'periodicidade' => $dados['periodicidade'], 'meses' => $mascara, 'dia_mes' => (int) ($dados['dia_mes'] ?? 1),
                'antecedencia_dias' => (int) ($dados['antecedencia_dias'] ?? 7), 'duracao_prevista_min' => $dados['duracao_prevista_min'] ?? null,
                'ativo' => (bool) $dados['ativo'],
            ]);
            if (! empty($dados['proxima_em'])) {
                $plano->proxima_em = $dados['proxima_em'];
            } elseif ($mudouProgramacao) {
                $plano->proxima_em = null;
                $plano->proxima_em = GeradorPreventivas::proximaData($plano, CarbonImmutable::now(config('oee.fuso_exibicao'))->subDay()->startOfDay());
            }
            $plano->save();

            $ordem = 0;
            foreach ($dados['itens'] ?? [] as $linha) {
                $texto = trim((string) ($linha['descricao'] ?? ''));
                $item = ! empty($linha['id']) ? PlanoPreventivaItem::where('plano_preventiva_id', $plano->id)->find($linha['id']) : null;
                if ($texto === '' && ! $item) {
                    continue;
                }
                $item ??= new PlanoPreventivaItem(['plano_preventiva_id' => $plano->id]);
                $item->fill(['descricao' => $texto !== '' ? $texto : $item->descricao, 'ordem' => $ordem += 10,
                    'ativo' => $texto !== '' && (bool) ($linha['ativo'] ?? true)])->save();
            }
        });
    }
}
