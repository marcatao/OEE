<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CentroCusto;
use App\Models\Maquina;
use App\Support\Unidades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administração > Centros de custo (setores), no formato da CTT do Protheus. Os dados podem vir do Protheus
 * (ver README); aqui dá para consultar, cadastrar e bloquear. Não há exclusão: bloqueie (CTT_BLOQ = 1).
 */
class CentroCustoController extends Controller
{
    public function index(Request $request): View
    {
        $busca = trim((string) $request->query('busca'));

        return view('admin.centros-custo.index', [
            'busca' => $busca,
            'centros' => CentroCusto::validos()
                ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('ctt_custo', 'like', "%{$busca}%")->orWhere('ctt_desc01', 'like', "%{$busca}%")))
                ->orderBy('ctt_custo')->orderBy('ctt_filial')->paginate(30)->withQueryString(),
            'usoPorCodigo' => Maquina::whereNotNull('centro_custo')
                ->selectRaw('centro_custo, count(*) as total')->groupBy('centro_custo')->pluck('total', 'centro_custo'),
        ]);
    }

    public function create(): View
    {
        return view('admin.centros-custo.form', ['centro' => new CentroCusto]);
    }

    public function store(Request $request): RedirectResponse
    {
        $centro = new CentroCusto;
        $this->salvar($request, $centro);

        return redirect()->route('admin.centros-custo.index')->with('ok', "Centro de custo {$centro->codigo()} cadastrado.");
    }

    public function edit(CentroCusto $centro): View
    {
        return view('admin.centros-custo.form', ['centro' => $centro]);
    }

    public function update(Request $request, CentroCusto $centro): RedirectResponse
    {
        $this->salvar($request, $centro);

        return redirect()->route('admin.centros-custo.index')->with('ok', "Centro de custo {$centro->codigo()} atualizado.");
    }

    private function salvar(Request $request, CentroCusto $centro): void
    {
        $request->merge([
            'ctt_filial' => trim((string) $request->input('ctt_filial')),
            'ctt_custo' => trim((string) $request->input('ctt_custo')),
        ]);
        $dados = $request->validate([
            'unidade' => Unidades::regras($request->user(), $centro->exists ? $centro->unidade : null),
            'ctt_filial' => ['nullable', 'string', 'max:8'],
            'ctt_custo' => ['required', 'string', 'max:20', Rule::unique('centros_custo', 'ctt_custo')
                ->where(fn ($q) => $q->where('ctt_filial', (string) $request->input('ctt_filial')))->ignore($centro->id)],
            'ctt_desc01' => ['required', 'string', 'max:40'],
            'ctt_classe' => ['required', Rule::in(array_keys(CentroCusto::CLASSES))],
            'ctt_bloq' => ['required', Rule::in(['1', '2'])],
            'ctt_ccsup' => ['nullable', 'string', 'max:20'],
            'ctt_dtexis' => ['nullable', 'date_format:Y-m-d'],
            'ctt_dtexsf' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:ctt_dtexis'],
        ], [
            'ctt_custo.required' => 'Informe o código do centro de custo.',
            'ctt_custo.unique' => 'Já existe esse código nesta filial.',
            'ctt_custo.max' => 'O código pode ter até 20 caracteres.',
            'ctt_desc01.required' => 'Informe a descrição.',
            'ctt_desc01.max' => 'A descrição pode ter até 40 caracteres (como no Protheus).',
            'ctt_dtexsf.after_or_equal' => 'O fim da existência deve ser depois do início.',
            '*.max' => 'Texto muito longo.',
        ]);

        // Mesmo formato do Protheus: caracteres sem nulos e datas 'AAAAMMDD'.
        $dados['ctt_filial'] = $dados['ctt_filial'] ?? '';
        $dados['ctt_ccsup'] = trim((string) ($dados['ctt_ccsup'] ?? ''));
        foreach (['ctt_dtexis', 'ctt_dtexsf'] as $campo) {
            $dados[$campo] = isset($dados[$campo]) ? str_replace('-', '', $dados[$campo]) : '';
        }

        $centro->fill($dados)->save();
    }
}
