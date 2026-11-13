<?php

namespace App\Http\Controllers\Manutencao;

use App\Http\Controllers\Controller;
use App\Models\Calendario;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\Ferramenta;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\SugestaoDescricao;
use App\Models\Usuario;
use App\Services\Manutencao\HorasManutencao;
use App\Support\AcessoOs;
use App\Support\Unidades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Cadastros só da manutenção (Administrador de Produção ou de Tecnologia, cada um na sua unidade): áreas executantes,
 * mantenedores (com a jornada, base de hora normal × extra e das horas disponíveis) e o banco de sugestões de descrição.
 * Os demais cadastros (máquinas, usuários, centros de custo) são os comuns do portal. Desativados, nunca excluídos.
 */
class CadastroManutencaoController extends Controller
{
    // ---------- Áreas executantes ----------
    public function areas(): View
    {
        return view('manutencao.cadastros.areas', ['areas' => AreaExecutante::orderByDesc('ativo')->orderBy('nome')->get(), 'nova' => new AreaExecutante(['ativo' => true])]);
    }

    public function salvarArea(Request $request, ?int $area = null): RedirectResponse
    {
        $registro = $area ? AreaExecutante::findOrFail($area) : new AreaExecutante;
        $request->merge(['codigo' => mb_strtoupper(trim((string) $request->input('codigo')))]);
        $dados = $request->validate([
            'unidade' => Unidades::regras($request->user(), $registro->exists ? $registro->unidade : null),
            'codigo' => ['required', 'string', 'max:20', Rule::unique('os_areas_executantes', 'codigo')->ignore($registro->id)],
            'nome' => ['required', 'string', 'max:80'],
            'ativo' => ['required', 'boolean'],
        ], ['codigo.unique' => 'Já existe uma área com esse código.', 'codigo.required' => 'Informe o código.', 'nome.required' => 'Informe o nome.']);
        $registro->fill($dados)->save();

        return redirect()->route('os.cadastros.areas')->with('ok', "Área {$registro->nome} salva.");
    }

    // ---------- Mantenedores ----------
    // O mantenedor é um USUÁRIO do portal (Segurança › Usuários): nome, foto, e-mail e departamento vêm do login
    // (Entra ID). Aqui ficam só os dados da manutenção: áreas executantes, matrícula, centro de custo e jornada.
    public function mantenedores(): View
    {
        return view('manutencao.cadastros.mantenedores', [
            'mantenedores' => Mantenedor::with(['usuario', 'areas', 'ferramentas' => fn ($q) => $q->where('ativo', true)->orderBy('codigo')])
                ->orderByDesc('ativo')->orderBy('nome')->get(),
        ]);
    }

    public function mantenedor(?int $mantenedor = null): View
    {
        $m = $mantenedor ? Mantenedor::with(['usuario', 'areas'])->findOrFail($mantenedor)
            : new Mantenedor(['ativo' => true, 'jornada_inicio' => '07:00', 'jornada_fim' => '17:00', 'intervalo_min' => 60, 'dias_semana' => '12345']);
        // Usuários ativos que ainda não são mantenedores (e o próprio, ao editar).
        $ocupados = Mantenedor::withoutGlobalScopes()->whereNotNull('usuario_id')->when($m->exists, fn ($q) => $q->where('id', '<>', $m->id))->pluck('usuario_id');

        return view('manutencao.cadastros.mantenedor', [
            'm' => $m,
            'usuarios' => Usuario::where('ativo', true)->whereNotIn('id', $ocupados)->orderBy('nome')->get(),
            'areas' => AreaExecutante::where('ativo', true)->orWhereIn('id', $m->exists ? $m->areas->pluck('id') : [])->orderBy('nome')->get(),
            'centros' => AcessoOs::centrosCusto(),
            // Calendários que o usuário enxerga (ativos) + o que o mantenedor já usa.
            'calendarios' => Calendario::where(fn ($q) => $q->where('ativo', true)->orWhere('id', (int) $m->calendario_id))
                ->orderByDesc('ano')->orderBy('nome')->get(),
            // Ferramentas que podem ser amarradas: as avulsas e as que já estão com ele.
            'ferramentas' => Ferramenta::where('ativo', true)
                ->where(fn ($q) => $q->whereNull('mantenedor_id')->when($m->exists, fn ($q) => $q->orWhere('mantenedor_id', $m->id)))
                ->orderBy('codigo')->get(),
        ]);
    }

    public function salvarMantenedor(Request $request, ?int $mantenedor = null): RedirectResponse
    {
        $m = $mantenedor ? Mantenedor::findOrFail($mantenedor) : new Mantenedor;
        $dados = $request->validate([
            'unidade' => Unidades::regras($request->user(), $m->exists ? $m->unidade : null),
            // Cadastro antigo sem login continua editável; os novos precisam do usuário.
            'usuario_id' => [$m->exists && $m->usuario_id === null ? 'nullable' : 'required', 'integer', Rule::exists('usuarios', 'id'),
                Rule::unique('os_mantenedores', 'usuario_id')->ignore($m->id)],
            'matricula' => ['nullable', 'string', 'max:20'],
            'areas' => ['required', 'array', 'min:1'],
            'areas.*' => ['integer', Rule::exists('os_areas_executantes', 'id')],
            'centro_custo' => ['nullable', 'string', 'max:20'],
            'jornada_inicio' => ['required', 'date_format:H:i'],
            'jornada_fim' => ['required', 'date_format:H:i'],
            'intervalo_min' => ['required', 'integer', 'between:0,600'],
            'dias' => ['required', 'array', 'min:1'], 'dias.*' => ['integer', 'between:1,7'],
            'ferramentas' => ['nullable', 'array'], 'ferramentas.*' => ['integer', Rule::exists('os_ferramentas', 'id')],
            'ativo' => ['required', 'boolean'],
            'calendario_id' => ['nullable', 'integer'],
        ], [
            'usuario_id.required' => 'Escolha o usuário (login) do mantenedor.',
            'usuario_id.unique' => 'Esse usuário já é mantenedor.',
            'areas.required' => 'Marque pelo menos uma área executante.',
            'dias.required' => 'Marque os dias de trabalho.',
        ]);
        // Calendário: obrigatório para ativar; só os que o usuário enxerga (ativos) ou o que ele já usa.
        // Campo ausente (o formulário sempre envia) mantém o calendário gravado.
        $calendarioId = $request->has('calendario_id') ? ($dados['calendario_id'] ?? null) : $m->calendario_id;
        if ($calendarioId && ! Calendario::whereKey($calendarioId)->where(fn ($q) => $q->where('ativo', true)->orWhere('id', (int) $m->calendario_id))->exists()) {
            throw ValidationException::withMessages(['calendario_id' => 'Calendário não encontrado ou inativo.']);
        }
        if ($dados['ativo'] && ! $calendarioId) {
            throw ValidationException::withMessages(['calendario_id' => 'Para ativar o mantenedor, escolha o calendário.']);
        }
        $dias = array_unique(array_map('intval', $dados['dias']));
        sort($dias);
        $usuario = ! empty($dados['usuario_id']) ? Usuario::find($dados['usuario_id']) : null;

        DB::transaction(function () use ($m, $dados, $dias, $usuario, $calendarioId) {
            $m->fill([
                'unidade' => $dados['unidade'],
                'usuario_id' => $usuario?->id,
                // Cópia do nome do login, para relatórios e para quem não tem usuário.
                'nome' => $usuario?->nome ?: ($m->nome ?: 'Sem nome'),
                'matricula' => $dados['matricula'] ?? null,
                'centro_custo' => $dados['centro_custo'] ?? null,
                'jornada_inicio' => $dados['jornada_inicio'],
                'jornada_fim' => $dados['jornada_fim'],
                'intervalo_min' => $dados['intervalo_min'],
                'dias_semana' => implode('', $dias),
                'ativo' => (bool) $dados['ativo'],
                'calendario_id' => $calendarioId,
            ])->save();
            $m->definirAreas($dados['areas']);
            $this->amarrarFerramentas($m, $dados['ferramentas'] ?? []);
        });

        return redirect()->route('os.cadastros.mantenedores')->with('ok', "Mantenedor {$m->nome} salvo. Jornada: ".HorasManutencao::hhmm(HorasManutencao::de($m)->minutosPorDia()).' por dia.');
    }

    /** Deixa com o mantenedor exatamente estas ferramentas: as desmarcadas voltam a ficar avulsas. */
    private function amarrarFerramentas(Mantenedor $m, array $ids): void
    {
        $ids = array_map('intval', $ids);
        foreach (Ferramenta::withoutGlobalScopes()->where('mantenedor_id', $m->id)->whereNotIn('id', $ids ?: [0])->get() as $f) {
            $f->entregarPara(null);
            $f->save();
        }
        // Só ferramentas avulsas (ou já dele) podem ser amarradas aqui: as de outro mantenedor se trocam pelo cadastro da ferramenta.
        foreach (Ferramenta::withoutGlobalScopes()->whereIn('id', $ids ?: [0])->where(fn ($q) => $q->whereNull('mantenedor_id')->orWhere('mantenedor_id', $m->id))->get() as $f) {
            $f->entregarPara($m->id);
            $f->save();
        }
    }

    // ---------- Equipamentos (tabela os_ferramentas) ----------
    public function ferramentas(Request $request): View
    {
        $situacao = (string) $request->input('situacao', '');
        $busca = trim((string) $request->input('busca', ''));
        $consulta = Ferramenta::with(['mantenedor.usuario'])
            ->when($situacao === 'avulsas', fn ($q) => $q->whereNull('mantenedor_id')->where('ativo', true))
            ->when($situacao === 'com_mantenedor', fn ($q) => $q->whereNotNull('mantenedor_id')->where('ativo', true))
            ->when($situacao === 'inativas', fn ($q) => $q->where('ativo', false))
            ->when($request->integer('mantenedor'), fn ($q, $id) => $q->where('mantenedor_id', $id))
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('codigo', 'like', "%{$busca}%")->orWhere('descricao', 'like', "%{$busca}%")
                ->orWhere('marca_modelo', 'like', "%{$busca}%")->orWhere('numero_serie', 'like', "%{$busca}%")));
        $totais = [
            'todas' => Ferramenta::where('ativo', true)->count(),
            'avulsas' => Ferramenta::where('ativo', true)->whereNull('mantenedor_id')->count(),
            'com_mantenedor' => Ferramenta::where('ativo', true)->whereNotNull('mantenedor_id')->count(),
        ];

        return view('manutencao.cadastros.ferramentas', [
            'ferramentas' => $consulta->orderByDesc('ativo')->orderBy('codigo')->paginate(50)->withQueryString(),
            'situacao' => $situacao, 'busca' => $busca, 'totais' => $totais,
            'mantenedores' => AcessoOs::mantenedores(), 'mantenedorFiltro' => $request->integer('mantenedor') ?: null,
        ]);
    }

    public function ferramenta(?int $ferramenta = null): View
    {
        return view('manutencao.cadastros.ferramenta', [
            'f' => $ferramenta ? Ferramenta::with('mantenedor.usuario')->findOrFail($ferramenta) : new Ferramenta(['ativo' => true]),
            'mantenedores' => AcessoOs::mantenedores(),
        ]);
    }

    public function salvarFerramenta(Request $request, ?int $ferramenta = null): RedirectResponse
    {
        $f = $ferramenta ? Ferramenta::findOrFail($ferramenta) : new Ferramenta;
        $request->merge(['codigo' => mb_strtoupper(trim((string) $request->input('codigo')))]);
        $dados = $request->validate([
            'unidade' => Unidades::regras($request->user(), $f->exists ? $f->unidade : null),
            'codigo' => ['required', 'string', 'max:30', Rule::unique('os_ferramentas', 'codigo')->ignore($f->id)],
            'descricao' => ['required', 'string', 'max:150'],
            'marca_modelo' => ['nullable', 'string', 'max:100'],
            'numero_serie' => ['nullable', 'string', 'max:60'],
            'mantenedor_id' => ['nullable', 'integer', Rule::exists('os_mantenedores', 'id')],
            'observacao' => ['nullable', 'string', 'max:500'],
            'ativo' => ['required', 'boolean'],
        ], ['codigo.required' => 'Informe o código ou patrimônio.', 'codigo.unique' => 'Já existe um equipamento com esse código.',
            'descricao.required' => 'Informe a descrição.']);
        $f->fill(collect($dados)->except('mantenedor_id')->all());
        $f->entregarPara($dados['mantenedor_id'] ?? null);
        $f->save();

        return redirect()->route('os.cadastros.ferramentas')->with('ok', "Equipamento {$f->codigo} salvo".($f->mantenedor_id ? ' com '.$f->mantenedor?->nomeExibicao() : ' (avulso)').'.');
    }

    // ---------- Sugestões de descrição ----------
    public function sugestoes(): View
    {
        return view('manutencao.cadastros.sugestoes', ['sugestoes' => SugestaoDescricao::orderByDesc('ativo')->orderBy('texto')->get(), 'nova' => new SugestaoDescricao(['ativo' => true])]);
    }

    public function salvarSugestao(Request $request, ?int $sugestao = null): RedirectResponse
    {
        $registro = $sugestao ? SugestaoDescricao::findOrFail($sugestao) : new SugestaoDescricao;
        $dados = $request->validate([
            'unidade' => Unidades::regras($request->user(), $registro->exists ? $registro->unidade : null),
            'texto' => ['required', 'string', 'max:200'],
            'ativo' => ['required', 'boolean'],
        ], ['texto.required' => 'Escreva a sugestão.']);
        $registro->fill($dados)->save();

        return redirect()->route('os.cadastros.sugestoes')->with('ok', 'Sugestão salva.');
    }
}
