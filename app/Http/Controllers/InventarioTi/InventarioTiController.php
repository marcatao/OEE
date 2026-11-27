<?php

namespace App\Http\Controllers\InventarioTi;

use App\Http\Controllers\Controller;
use App\Models\InventarioTi\AtribuicaoLicenca;
use App\Models\InventarioTi\AtributoItemTi;
use App\Models\InventarioTi\DescricaoItemTi;
use App\Models\InventarioTi\ItemTi;
use App\Models\InventarioTi\TipoItemTi;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Usuario;
use App\Services\InventarioTi\MovimentacaoInventario;
use App\Services\InventarioTi\TermosResponsabilidade;
use App\Support\AcessoOs;
use App\Support\Unidades;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Tecnologia › Inventário de TI: equipamentos, licenças de software e linhas/chips (cadastro só no portal). Consulta com
 * a permissão `inventario_ti` (escopo de unidade do TemUnidade); cadastro e movimentação só pelo administrador da
 * unidade do item. Regras de gravação em App\Services\InventarioTi\MovimentacaoInventario.
 */
class InventarioTiController extends Controller
{
    public function __construct(private MovimentacaoInventario $movimentacao, private TermosResponsabilidade $termos) {}

    public function index(Request $request): View
    {
        $f = [
            'situacao' => (string) $request->input('situacao', ''),
            'categoria' => (string) $request->input('categoria', ''),
            'tipo' => $request->integer('tipo') ?: null,
            'responsavel' => $request->integer('responsavel') ?: null,
            'busca' => trim((string) $request->input('busca', '')),
        ];
        $base = fn () => ItemTi::query()
            ->when(isset(ItemTi::CATEGORIAS[$f['categoria']]), fn ($q) => $q->where('categoria', $f['categoria']))
            ->when($f['tipo'], fn ($q, $t) => $q->where('tipo_id', $t))
            ->when($f['responsavel'], fn ($q, $r) => $q->where('responsavel_id', $r))
            ->when($f['busca'] !== '', fn ($q) => $this->buscar($q, $f['busca']));

        $consulta = $base()->with(['tipo', 'responsavel']);
        if ($f['situacao'] === 'vencendo') {
            $consulta->ativos()->where(fn ($q) => $q->whereBetween('garantia_ate', [today(), today()->addDays(30)])
                ->orWhereBetween('vencimento', [today(), today()->addDays(30)]));
        } elseif (isset(ItemTi::SITUACOES[$f['situacao']])) {
            $consulta->where('situacao', $f['situacao']);
        } else {
            $consulta->ativos();
        }
        $totais = $base()->selectRaw('situacao, count(*) as total')->groupBy('situacao')->pluck('total', 'situacao')->map(fn ($t) => (int) $t);

        return view('inventario-ti.index', [
            'itens' => $consulta->orderBy('categoria')->orderBy('descricao')->orderBy('id')->paginate(50)->withQueryString(),
            'f' => $f,
            'totais' => $totais,
            'tipos' => TipoItemTi::orderBy('categoria')->orderBy('nome')->get(),
            'responsaveis' => Usuario::whereIn('id', ItemTi::query()->whereNotNull('responsavel_id')->select('responsavel_id'))->orderBy('nome')->get(['id', 'nome']),
            'podeCadastrar' => $request->user()->areasAdministradas() !== [],
        ]);
    }

    public function show(Request $request, int $item): View
    {
        $i = ItemTi::with(['tipo', 'responsavel', 'vinculado', 'chips', 'atributos', 'criadoPor', 'atualizadoPor'])->findOrFail($item);
        $u = $request->user();

        return view('inventario-ti.show', [
            'i' => $i,
            'podeEditar' => $i->podeEditar($u),
            'movimentacoes' => $i->movimentacoes()->with('usuario')->orderByDesc('criado_em')->orderByDesc('id')->limit(200)->get(),
            'termos' => $i->termos()->with(['usuario', 'registradoPor', 'testemunhas'])->orderByDesc('criado_em')->orderByDesc('id')->get(),
            'atribuicoes' => $i->ehLicenca() ? $i->atribuicoes()->with(['item', 'usuario'])->orderByRaw('CASE WHEN removida_em IS NULL THEN 0 ELSE 1 END')
                ->orderByDesc('atribuida_em')->get() : collect(),
            'licencas' => $i->ehHardware() ? $i->licencasInstaladas()->ativas()->with('licenca')->get() : collect(),
            // Chamados do item que o usuário pode ver (mesmas regras das telas de SS/OS).
            'solicitacoes' => AcessoOs::filtrar(SolicitacaoServico::query(), $u)->where('item_ti_id', $i->id)->with('ordemServico')
                ->orderByDesc('criado_em')->limit(50)->get(),
            'ordens' => AcessoOs::filtrar(OrdemServico::query(), $u)->where('item_ti_id', $i->id)->orderByDesc('criado_em')->limit(50)->get(),
            'podeAbrirSs' => $this->podeAbrirSs($u, $i),
            // Gerar termo: só o Administrador de TI, com as testemunhas (sugeridas as do último termo).
            'podeGerarTermo' => TermosResponsabilidade::podeGerar($u) && $i->temTermo() && $i->responsavel_id && $i->situacao !== 'baixado',
            'testemunhasSugeridas' => $this->termos->testemunhasSugeridas(),
            'usuariosTestemunha' => TermosResponsabilidade::podeGerar($u) && $i->temTermo() && $i->responsavel_id
                ? Usuario::where('ativo', true)->where('id', '<>', $i->responsavel_id)->orderBy('nome')->get(['id', 'nome', 'email']) : collect(),
            'equipamentos' => $i->ehLicenca() ? $this->equipamentos() : collect(),
            'usuarios' => $i->ehLicenca() ? $this->usuarios() : collect(),
        ]);
    }

    public function create(Request $request): View
    {
        $i = new ItemTi(['unidade' => Unidades::padrao($request->user())]);
        if ($request->filled('tipo')) {
            $tipo = TipoItemTi::find($request->integer('tipo'));
            $i->tipo_id = $tipo?->id;
            $i->categoria = $tipo?->categoria ?? 'hardware';
        }

        return view('inventario-ti.form', $this->dadosFormulario($i));
    }

    public function store(Request $request): RedirectResponse
    {
        $i = new ItemTi;
        $this->movimentacao->salvar($i, $this->validar($request, $i), $request->user(), $request->input('observacao_movimentacao'));

        return redirect()->route('inventario-ti.show', $i)->with('ok', "Item {$i->codigo()} cadastrado.".($i->responsavel_id && $i->temTermo()
            ? ' Gere o termo de responsabilidade de '.$i->responsavel?->nome.' (Administrador de TI, com as testemunhas).' : ''));
    }

    public function edit(Request $request, int $item): View
    {
        $i = $this->editavel($request, $item);

        return view('inventario-ti.form', $this->dadosFormulario($i));
    }

    public function update(Request $request, int $item): RedirectResponse
    {
        $i = $this->editavel($request, $item);
        $responsavelAntes = (int) $i->responsavel_id;
        $this->movimentacao->salvar($i, $this->validar($request, $i), $request->user(), $request->input('observacao_movimentacao'));
        $novoTermo = $i->responsavel_id && $responsavelAntes !== (int) $i->responsavel_id && $i->temTermo();

        return redirect()->route('inventario-ti.show', $i)->with('ok', "Item {$i->codigo()} salvo.".($novoTermo
            ? ' Gere o termo de responsabilidade de '.$i->responsavel?->nome.' (Administrador de TI, com as testemunhas).' : ''));
    }

    /** Administrador de TI gera o termo do responsável atual, escolhendo as duas testemunhas. */
    public function gerarTermo(Request $request, int $item): RedirectResponse
    {
        $i = ItemTi::findOrFail($item);
        $dados = $request->validate([
            'testemunha_1' => ['required', 'integer', Rule::exists('usuarios', 'id')],
            'testemunha_2' => ['required', 'integer', Rule::exists('usuarios', 'id')],
        ], ['testemunha_1.required' => 'Escolha a testemunha 1.', 'testemunha_2.required' => 'Escolha a testemunha 2.']);
        $termo = $this->termos->gerar($i, $request->user(), (int) $dados['testemunha_1'], (int) $dados['testemunha_2']);

        return redirect()->route('inventario-ti.termos.show', $termo)->with('ok', "Termo {$termo->numeroFormatado()} gerado. ".
            'O responsável e as testemunhas veem o aviso no portal e cada um assina com o próprio login.');
    }

    public function atribuirLicenca(Request $request, int $item): RedirectResponse
    {
        $i = $this->editavel($request, $item);
        $dados = $request->validate([
            'item_id' => ['nullable', 'integer', Rule::exists('ti_itens', 'id')],
            'usuario_id' => ['nullable', 'integer', Rule::exists('usuarios', 'id')],
            'observacao' => ['nullable', 'string', 'max:255'],
        ]);
        if (! empty($dados['item_id']) && ! $this->equipamentos()->contains('id', (int) $dados['item_id'])) {
            throw ValidationException::withMessages(['item_id' => 'Escolha um equipamento da lista.']);
        }
        $this->movimentacao->atribuirLicenca($i, $dados['item_id'] ?? null, $dados['usuario_id'] ?? null, $dados['observacao'] ?? null, $request->user());

        return redirect()->route('inventario-ti.show', $i)->with('ok', 'Licença atribuída.');
    }

    public function removerAtribuicao(Request $request, int $item, int $atribuicao): RedirectResponse
    {
        $i = $this->editavel($request, $item);
        $a = AtribuicaoLicenca::where('licenca_id', $i->id)->findOrFail($atribuicao);
        $this->movimentacao->removerAtribuicao($a, $request->user());

        return redirect()->route('inventario-ti.show', $i)->with('ok', 'Licença liberada.');
    }

    // ---------- Tipos de item ----------
    public function tipos(): View
    {
        return view('inventario-ti.tipos', [
            'tipos' => TipoItemTi::withCount(['itens' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('categoria')->orderBy('nome')->get(),
            'novo' => new TipoItemTi,
        ]);
    }

    public function salvarTipo(Request $request, ?int $tipo = null): RedirectResponse
    {
        $t = $tipo ? TipoItemTi::findOrFail($tipo) : new TipoItemTi;
        $dados = $request->validate([
            'unidade' => Unidades::regras($request->user(), $t->exists ? $t->unidade : null),
            'categoria' => [$t->exists ? 'prohibited' : 'required', Rule::in(array_keys(ItemTi::CATEGORIAS))],
            'nome' => ['required', 'string', 'max:60'],
            'ativo' => ['required', 'boolean'],
        ], ['categoria.prohibited' => 'A categoria de um tipo não muda (os itens dependem dela).', 'nome.required' => 'Informe o nome do tipo.']);
        $t->fill($dados)->save();

        return redirect()->route('inventario-ti.tipos')->with('ok', "Tipo \"{$t->nome}\" salvo.");
    }

    // ---------- Descrições padrão ----------
    public function descricoes(Request $request): View
    {
        $busca = trim((string) $request->input('busca', ''));
        $tipo = $request->input('tipo', '');
        $situacao = (string) $request->input('situacao', '');

        return view('inventario-ti.descricoes', [
            'descricoes' => DescricaoItemTi::with('tipo')->withCount(['itens' => fn ($q) => $q->withoutGlobalScopes()])
                // Cada palavra em qualquer parte do texto (ex.: "dell 5440").
                ->when($busca !== '', function ($q) use ($busca) {
                    foreach (preg_split('/\s+/', $busca) as $palavra) {
                        $q->where('descricao', 'like', "%{$palavra}%");
                    }
                })
                ->when($tipo === 'sem', fn ($q) => $q->whereNull('tipo_id'))
                ->when(ctype_digit((string) $tipo), fn ($q) => $q->where('tipo_id', (int) $tipo))
                ->when($situacao === 'ativas', fn ($q) => $q->where('ativo', true))
                ->when($situacao === 'inativas', fn ($q) => $q->where('ativo', false))
                ->orderByDesc('ativo')->orderBy('descricao')->orderBy('id')->paginate(100)->withQueryString(),
            'busca' => $busca, 'tipoFiltro' => (string) $tipo, 'situacao' => $situacao,
            'tipos' => TipoItemTi::orderBy('categoria')->orderBy('nome')->get(),
            'nova' => new DescricaoItemTi,
        ]);
    }

    public function salvarDescricao(Request $request, ?int $descricao = null): RedirectResponse
    {
        $d = $descricao ? DescricaoItemTi::findOrFail($descricao) : new DescricaoItemTi;
        $request->merge(['descricao' => preg_replace('/\s+/', ' ', trim((string) $request->input('descricao')))]);
        $dados = $request->validate([
            'unidade' => Unidades::regras($request->user(), $d->exists ? $d->unidade : null),
            'tipo_id' => ['nullable', 'integer', Rule::exists('ti_tipos', 'id')],
            'descricao' => ['required', 'string', 'max:150', Rule::unique('ti_descricoes', 'descricao')->ignore($d->id)],
            'ativo' => ['required', 'boolean'],
        ], ['descricao.required' => 'Informe a descrição.', 'descricao.unique' => 'Essa descrição já está cadastrada.']);
        $d->fill($dados)->save();

        return redirect()->route('inventario-ti.descricoes')->with('ok', "Descrição \"{$d->descricao}\" salva. Os itens já gravados não mudam.");
    }

    // ---------- Apoio ----------
    /** Abrir SS para o item: solicitante de uma unidade que aceita chamado para item de TI (a do item, ou qualquer uma se "Todas"). */
    private function podeAbrirSs(Usuario $u, ItemTi $i): bool
    {
        $unidades = array_intersect(AcessoOs::unidadesSolicitante($u), config('oee.os_unidades_item_ti'));

        return $u->pode('os_solicitar') && $i->situacao !== 'baixado'
            && ($i->unidade === 'ambos' ? $unidades !== [] : in_array((string) $i->unidade, $unidades, true));
    }

    private function buscar(Builder $q, string $busca): Builder
    {
        return $q->where(function ($w) use ($busca) {
            foreach (['patrimonio', 'descricao', 'marca', 'modelo', 'numero_serie', 'hostname', 'linha_numero', 'local', 'nota_fiscal'] as $c) {
                $w->orWhere($c, 'like', "%{$busca}%");
            }
            $w->orWhereHas('responsavel', fn ($r) => $r->where('nome', 'like', "%{$busca}%"));
            // Também nos atributos (ex.: digitar o IP).
            $w->orWhereHas('atributos', fn ($a) => $a->where('valor', 'like', "%{$busca}%")->orWhere('nome', 'like', "%{$busca}%"));
        });
    }

    private function editavel(Request $request, int $id): ItemTi
    {
        $i = ItemTi::findOrFail($id);
        abort_unless($i->podeEditar($request->user()), 403, 'Só o administrador da unidade deste item altera o cadastro.');

        return $i;
    }

    private function validar(Request $request, ItemTi $i): array
    {
        $request->merge(['patrimonio' => mb_strtoupper(trim((string) $request->input('patrimonio'))) ?: null]);
        $dados = $request->validate([
            'unidade' => Unidades::regras($request->user(), $i->exists ? $i->unidade : null),
            'tipo_id' => ['required', 'integer', Rule::exists('ti_tipos', 'id')],
            'patrimonio' => ['nullable', 'string', 'max:30', Rule::unique('ti_itens', 'patrimonio')->ignore($i->id)],
            'descricao_id' => ['nullable', 'integer', Rule::exists('ti_descricoes', 'id')],
            'marca' => ['nullable', 'string', 'max:60'],
            'modelo' => ['nullable', 'string', 'max:80'],
            'numero_serie' => ['nullable', 'string', 'max:80'],
            'hostname' => ['nullable', 'string', 'max:60'],
            'especificacao' => ['nullable', 'string', 'max:2000'],
            'acessorios' => ['nullable', 'string', 'max:255'],
            'centro_custo' => ['nullable', 'string', 'max:20'],
            'local' => ['nullable', 'string', 'max:100'],
            'responsavel_id' => ['nullable', 'integer', Rule::exists('usuarios', 'id')],
            'situacao' => ['required', Rule::in(array_keys(ItemTi::SITUACOES))],
            'data_compra' => ['nullable', 'date', 'before_or_equal:today'],
            'nota_fiscal' => ['nullable', 'string', 'max:30'],
            'fornecedor' => ['nullable', 'string', 'max:100'],
            'garantia_ate' => ['nullable', 'date'],
            'valor' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'licenca_chave' => ['nullable', 'string', 'max:255'],
            'quantidade_licencas' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'vencimento' => ['nullable', 'date'],
            'linha_numero' => ['nullable', 'string', 'max:20'],
            'operadora' => ['nullable', 'string', 'max:40'],
            'plano' => ['nullable', 'string', 'max:80'],
            'vinculado_item_id' => ['nullable', 'integer', Rule::exists('ti_itens', 'id')],
            'observacao' => ['nullable', 'string', 'max:500'],
            'motivo_baixa' => ['nullable', 'string', 'max:255', 'required_if:situacao,baixado'],
            'atributos' => ['nullable', 'array', 'max:100'],
            'atributos.*.nome' => ['nullable', 'string', 'max:60', 'required_with:atributos.*.valor'],
            'atributos.*.valor' => ['nullable', 'string', 'max:255'],
        ], [
            'atributos.*.nome.required_with' => 'Informe o nome do atributo (ex.: IP).', 'atributos.*.nome.max' => 'Nome do atributo: até 60 letras.',
            'atributos.*.valor.max' => 'Valor do atributo: até 255 letras.',
            'tipo_id.required' => 'Escolha o tipo do item.',
            'patrimonio.unique' => 'Já existe um item com esse patrimônio.', 'motivo_baixa.required_if' => 'Informe o motivo da baixa.',
            'data_compra.before_or_equal' => 'A data de compra não pode ser no futuro.',
        ]);
        $tipo = TipoItemTi::withoutGlobalScopes()->findOrFail($dados['tipo_id']);
        if (! $tipo->ativo && (int) $i->tipo_id !== (int) $tipo->id) {
            throw ValidationException::withMessages(['tipo_id' => 'Este tipo está desativado.']);
        }
        if ($i->exists && $i->categoria !== $tipo->categoria) {
            throw ValidationException::withMessages(['tipo_id' => 'O tipo tem de ser da mesma categoria do item ('.$i->rotuloCategoria().').']);
        }
        $dados['categoria'] = $tipo->categoria;
        // Descrição: só as cadastradas em "Descrições" (padronizar). Item antigo sem descrição padrão pode manter a dele.
        $padrao = ! empty($dados['descricao_id']) ? DescricaoItemTi::find($dados['descricao_id']) : null;
        if ($padrao) {
            if (! $padrao->ativo && (int) $i->descricao_id !== (int) $padrao->id) {
                throw ValidationException::withMessages(['descricao_id' => 'Esta descrição está desativada.']);
            }
            if ($padrao->tipo_id && (int) $padrao->tipo_id !== (int) $tipo->id) {
                throw ValidationException::withMessages(['descricao_id' => 'Esta descrição é de outro tipo de item ('.$padrao->tipo?->nome.').']);
            }
            $dados['descricao'] = $padrao->descricao;
        } elseif ($i->exists && ! $i->descricao_id && filled($i->descricao)) {
            $dados['descricao_id'] = null; // mantém a descrição antiga
        } else {
            throw ValidationException::withMessages(['descricao_id' => 'Escolha a descrição da lista (cadastre em Inventário de TI › Descrições).']);
        }
        $dados['atributos'] = $dados['atributos'] ?? [];
        $cc = trim((string) ($dados['centro_custo'] ?? ''));
        if ($cc !== '' && ! AcessoOs::centroCustoExiste($cc)) {
            throw ValidationException::withMessages(['centro_custo' => 'Escolha um centro de custo da lista.']);
        }
        $dados['centro_custo'] = $cc ?: null;
        // Campos que não valem para a categoria ficam vazios.
        $somente = ['hardware' => ['hostname', 'especificacao', 'acessorios'], 'licenca' => ['licenca_chave', 'quantidade_licencas'], 'linha' => ['linha_numero', 'operadora', 'plano', 'vinculado_item_id']];
        foreach ($somente as $categoria => $campos) {
            if ($tipo->categoria !== $categoria) {
                foreach ($campos as $c) {
                    $dados[$c] = null;
                }
            }
        }
        if ($tipo->categoria === 'hardware') {
            $dados['vencimento'] = null;
        }
        if (! empty($dados['vinculado_item_id'])) {
            $aparelho = $this->equipamentos()->firstWhere('id', (int) $dados['vinculado_item_id']);
            if (! $aparelho || (int) $aparelho->id === (int) $i->id) {
                throw ValidationException::withMessages(['vinculado_item_id' => 'Escolha um equipamento ativo do inventário.']);
            }
        }

        return $dados;
    }

    private function dadosFormulario(ItemTi $i): array
    {
        return [
            'i' => $i,
            'descricoes' => DescricaoItemTi::where(fn ($q) => $q->where('ativo', true)->orWhere('id', (int) $i->descricao_id))
                ->orderBy('descricao')->orderBy('id')->get(['id', 'descricao', 'tipo_id']),
            'tipos' => TipoItemTi::where(fn ($q) => $q->where('ativo', true)->orWhere('id', (int) $i->tipo_id))
                ->when($i->exists, fn ($q) => $q->where('categoria', $i->categoria))->orderBy('categoria')->orderBy('nome')->get(),
            'usuarios' => $this->usuarios($i->responsavel_id),
            'centros' => AcessoOs::centrosCusto(),
            'equipamentos' => $this->equipamentos()->reject(fn ($e) => (int) $e->id === (int) $i->id),
            // Nomes de atributo já usados (sugestões ao digitar, para todos escreverem "IP" do mesmo jeito).
            'nomesAtributos' => AtributoItemTi::query()->select('nome')->distinct()->orderBy('nome')->limit(200)->pluck('nome'),
            'locais' => ItemTi::query()->whereNotNull('local')->select('local')->distinct()->orderBy('local')->limit(300)->pluck('local'),
        ];
    }

    /** Equipamentos ativos que o usuário enxerga (para o chip e a licença). */
    private function equipamentos(): Collection
    {
        return ItemTi::ativos()->where('categoria', 'hardware')->orderBy('descricao')->orderBy('id')
            ->get(['id', 'patrimonio', 'descricao', 'categoria', 'linha_numero', 'responsavel_id']);
    }

    /** Usuários ativos do portal (responsáveis); o atual continua na lista mesmo se foi inativado. */
    private function usuarios(?int $atual = null): Collection
    {
        return Usuario::where(fn ($q) => $q->where('ativo', true)->when($atual, fn ($w) => $w->orWhere('id', $atual)))
            ->orderBy('nome')->get(['id', 'nome', 'email']);
    }
}
