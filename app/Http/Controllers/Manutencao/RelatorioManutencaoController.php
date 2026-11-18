<?php

namespace App\Http\Controllers\Manutencao;

use App\Http\Controllers\Controller;
use App\Models\CentroCusto;
use App\Models\Manutencao\JustificativaSetor;
use App\Models\Manutencao\SolicitacaoServico;
use App\Services\Manutencao\HorasManutencao;
use App\Services\Manutencao\RelatorioHorasManutencao;
use App\Support\AcessoOs;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ordens de serviço › Relatórios: apontamentos de horas (intranet e planilha geral da contabilidade, com exportação
 * para Excel) e horas por setor × média mensal + aproveitamento da mão de obra (diretoria/gerência).
 */
class RelatorioManutencaoController extends Controller
{
    public function __construct(private RelatorioHorasManutencao $relatorio) {}

    public function horas(Request $request): View|StreamedResponse
    {
        $fuso = HorasManutencao::fuso();
        $hoje = CarbonImmutable::now($fuso);
        $de = $this->data($request->input('de'), $hoje->startOfMonth());
        $ate = $this->data($request->input('ate'), $hoje);
        if ($ate < $de) {
            [$de, $ate] = [$ate, $de];
        }
        $filtros = [
            'tipo' => isset(SolicitacaoServico::TIPOS[$request->input('tipo')]) ? $request->input('tipo') : null,
            'maquina' => $request->integer('maquina') ?: null,
            'mantenedor' => $request->integer('mantenedor') ?: null,
            'centro_custo' => trim((string) $request->input('centro_custo')) ?: null,
            // Só as unidades habilitadas no login (as outras nem aparecem na lista).
            'unidade' => in_array($request->input('unidade'), AcessoOs::unidadesHabilitadas($request->user()), true) ? $request->input('unidade') : null,
            'area' => $request->integer('area') ?: null,
        ];
        $consulta = $this->relatorio->apontamentos($request->user(), $de, $ate->addDay(), $filtros);

        if ($request->input('formato') === 'csv') {
            return $this->csv($consulta, "apontamentos-manutencao-{$de->format('Ymd')}-{$ate->format('Ymd')}.csv");
        }

        $linhas = $consulta->get();

        return view('manutencao.relatorios.horas', [
            'linhas' => $linhas,
            'de' => $de, 'ate' => $ate, 'filtros' => $filtros,
            'totalNormais' => (int) $linhas->sum('minutos_normais'),
            'totalExtras' => (int) $linhas->sum('minutos_extras'),
            'maquinas' => AcessoOs::maquinas(),
            'mantenedores' => AcessoOs::mantenedores(),
            'centros' => AcessoOs::centrosCusto(),
            'unidades' => AcessoOs::unidadesHabilitadas($request->user()),
            'areas' => AcessoOs::areas(),
            'aproveitamento' => $this->relatorio->aproveitamento($request->user(), $de->startOfDay(), $ate->addDay()->startOfDay(), $filtros),
        ]);
    }

    public function setores(Request $request): View
    {
        $mes = $this->mes($request->input('mes'));
        $linhas = $this->relatorio->setores($request->user(), $mes);
        $justificativas = JustificativaSetor::whereDate('competencia', $mes->format('Y-m-01'))->get()
            ->keyBy(fn ($j) => $j->unidade.'|'.trim($j->centro_custo));
        $centros = CentroCusto::withoutGlobalScope('unidade')->get(['ctt_custo', 'ctt_desc01'])->keyBy(fn ($c) => trim($c->ctt_custo));
        $aproveitamento = $this->relatorio->aproveitamento($request->user(), $mes->startOfMonth(), $mes->startOfMonth()->addMonth());

        return view('manutencao.relatorios.setores', compact('mes', 'linhas', 'justificativas', 'centros', 'aproveitamento'));
    }

    public function justificar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'mes' => ['required', 'date_format:Y-m'],
            'unidade' => ['required', Rule::in(array_keys(config('oee.unidades')))],
            'centro_custo' => ['nullable', 'string', 'max:120'],
            'texto' => ['nullable', 'string', 'max:1000'],
        ]);
        $visiveis = $request->user()->unidadesVisiveis();
        abort_unless($visiveis === null || in_array($dados['unidade'], $visiveis, true), 403);
        JustificativaSetor::updateOrCreate(
            ['unidade' => $dados['unidade'], 'centro_custo' => (string) ($dados['centro_custo'] ?? ''), 'competencia' => $dados['mes'].'-01'],
            ['texto' => (string) ($dados['texto'] ?? '')],
        );

        return back()->with('ok', 'Justificativa salva.');
    }

    private function csv($consulta, string $arquivo): StreamedResponse
    {
        $fuso = HorasManutencao::fuso();

        return response()->streamDownload(function () use ($consulta, $fuso) {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF"); // Excel abre acentos certos com o BOM
            fputcsv($saida, ['Cód. OS', 'Data', 'Unidade', 'Área executante', 'Máquina', 'Tipo OS', 'Hora inicial', 'Hora final', 'Total hrs sem hora extra', 'Total hora extra',
                'Total hora normal + extra', 'Décimo', 'Funcionário', 'Usuário (lançado por)', 'Centro de custo', 'C.C. solicitante', 'Descrição'], ';');
            $consulta->chunk(500, function ($linhas) use ($saida, $fuso) {
                foreach ($linhas as $a) {
                    $os = $a->ordemServico;
                    $total = (int) $a->minutos_normais + (int) $a->minutos_extras;
                    fputcsv($saida, [
                        $os->numeroFormatado(), $a->inicio->setTimezone($fuso)->format('d/m/Y'),
                        config('oee.unidades')[$os->unidade] ?? $os->unidade, $os->areaExecutante?->nome,
                        trim(($os->maquina?->codigo ?? '').' '.($os->maquina?->nome ?? '')), SolicitacaoServico::TIPOS[$os->tipo] ?? $os->tipo,
                        $a->inicio->setTimezone($fuso)->format('H:i'), $a->fim->setTimezone($fuso)->format('H:i'),
                        HorasManutencao::hhmm($a->minutos_normais), HorasManutencao::hhmm($a->minutos_extras), HorasManutencao::hhmm($total),
                        HorasManutencao::decimal($total), $a->mantenedor?->nome, $a->criadoPor?->nome, $os->centro_custo, $os->centro_custo_solicitante,
                        $a->descricao,
                    ], ';');
                }
            });
            fclose($saida);
        }, $arquivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function data(?string $valor, CarbonImmutable $padrao): CarbonImmutable
    {
        try {
            return $valor ? CarbonImmutable::createFromFormat('Y-m-d', $valor, HorasManutencao::fuso())->startOfDay() : $padrao->startOfDay();
        } catch (\Throwable) {
            return $padrao->startOfDay();
        }
    }

    private function mes(?string $valor): CarbonImmutable
    {
        try {
            return $valor ? CarbonImmutable::createFromFormat('Y-m-d', $valor.'-01', HorasManutencao::fuso())->startOfMonth() : CarbonImmutable::now(HorasManutencao::fuso())->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::now(HorasManutencao::fuso())->startOfMonth();
        }
    }
}
