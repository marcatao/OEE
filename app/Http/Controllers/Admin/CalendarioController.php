<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Calendario;
use App\Models\Manutencao\Mantenedor;
use App\Models\Maquina;
use App\Services\Calendario\DiasUteis;
use App\Support\Unidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administração › Calendários: calendários anuais de dias úteis por unidade (cada unidade vê só os seus e pode ter
 * vários). Um calendário novo já vem com os feriados nacionais, de SP e de São Bernardo do Campo; na visão do ano
 * (como no Outlook) o administrador clica no dia para marcar feriado, ponte, folga, compensação ou dia útil extra.
 */
class CalendarioController extends Controller
{
    public function index(): View
    {
        $calendarios = Calendario::withCount(['maquinas'])->orderByDesc('ano')->orderBy('nome')->get();
        $usoMantenedores = Mantenedor::whereNotNull('calendario_id')->selectRaw('calendario_id, count(*) as qtd')
            ->groupBy('calendario_id')->pluck('qtd', 'calendario_id');

        return view('admin.calendarios.index', [
            'calendarios' => $calendarios,
            'usoMantenedores' => $usoMantenedores,
            'novo' => new Calendario(['ano' => (int) now(config('oee.fuso_exibicao'))->format('Y'), 'ativo' => true]),
        ]);
    }

    public function store(Request $request, DiasUteis $dias): RedirectResponse
    {
        $dados = $this->validar($request, new Calendario);
        $calendario = $dias->criar($dados + ['ativo' => true]);

        return redirect()->route('admin.calendarios.show', $calendario)
            ->with('ok', "Calendário {$calendario->titulo()} criado com os feriados nacionais, de SP e de São Bernardo do Campo.");
    }

    public function show(Calendario $calendario, DiasUteis $dias): View
    {
        return view('admin.calendarios.show', [
            'calendario' => $calendario,
            'excecoes' => $dias->excecoes($calendario),
            'resumo' => $dias->resumo($calendario),
            'outrosAnos' => Calendario::where('nome', $calendario->nome)->where('unidade', $calendario->unidade)
                ->whereKeyNot($calendario->id)->orderBy('ano')->get(['id', 'ano']),
            'qtdMaquinas' => Maquina::where('calendario_id', $calendario->id)->count(),
            'qtdMantenedores' => Mantenedor::where('calendario_id', $calendario->id)->count(),
        ]);
    }

    public function update(Request $request, Calendario $calendario): RedirectResponse
    {
        $dados = $this->validar($request, $calendario);
        $calendario->update($dados + ['ativo' => $request->boolean('ativo')]);

        return redirect()->route('admin.calendarios.show', $calendario)->with('ok', 'Calendário atualizado.');
    }

    /** Um dia do calendário (JSON): tipo vazio volta ao padrão (segunda a sexta úteis). */
    public function salvarDia(Request $request, Calendario $calendario, DiasUteis $dias): JsonResponse
    {
        $dados = $request->validate([
            'data' => ['required', 'date_format:Y-m-d', function ($campo, $valor, $falha) use ($calendario) {
                if ((int) substr($valor, 0, 4) !== $calendario->ano) {
                    $falha("O dia precisa ser de {$calendario->ano}.");
                }
            }],
            'tipo' => ['nullable', Rule::in(array_keys(Calendario::TIPOS))],
            'descricao' => ['nullable', 'string', 'max:120'],
        ]);
        if (empty($dados['tipo'])) {
            $calendario->dias()->whereDate('data', $dados['data'])->delete(); // volta ao padrão
        } else {
            $dia = $calendario->dias()->whereDate('data', $dados['data'])->first() ?? $calendario->dias()->make(['data' => $dados['data']]);
            $dia->fill(['tipo' => $dados['tipo'], 'descricao' => $dados['descricao'] ?? null])->save();
        }

        return response()->json($dias->dia($calendario, $dados['data']) + ['resumo' => $dias->resumo($calendario)]);
    }

    /** Cria o calendário do ano seguinte (com os feriados do novo ano); opcionalmente leva máquinas e mantenedores. */
    public function proximoAno(Request $request, Calendario $calendario, DiasUteis $dias): RedirectResponse
    {
        $novo = $dias->proximoAno($calendario, $request->boolean('mover'));

        return redirect()->route('admin.calendarios.show', $novo)->with('ok', "Calendário {$novo->titulo()} pronto."
            .($request->boolean('mover') ? ' As máquinas e os mantenedores do '.$calendario->ano.' passaram para ele.' : ''));
    }

    private function validar(Request $request, Calendario $calendario): array
    {
        return $request->validate([
            'unidade' => Unidades::regras($request->user(), $calendario->exists ? $calendario->unidade : null),
            'nome' => ['required', 'string', 'max:80', Rule::unique('calendarios')->ignore($calendario->id)
                ->where(fn ($q) => $q->where('unidade', $request->input('unidade', $calendario->unidade))
                    ->where('ano', (int) $request->input('ano', $calendario->ano)))],
            'ano' => [$calendario->exists ? 'prohibited' : 'required', 'integer', 'between:2020,2100'],
            'descricao' => ['nullable', 'string', 'max:300'],
        ], [
            'nome.required' => 'Informe o nome do calendário.',
            'nome.unique' => 'Já existe um calendário com esse nome nesse ano e unidade.',
            'ano.prohibited' => 'O ano do calendário não muda: crie o do ano seguinte.',
        ]);
    }
}
