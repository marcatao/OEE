<?php

namespace App\Http\Controllers\Aplicativo;

use App\Http\Controllers\Controller;
use App\Models\ApontamentoEstado;
use App\Models\Ferramenta;
use App\Services\Aplicativo\MaquinasAplicativo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API da função "Máquinas" do aplicativo. Consultar: permissão apontamentos ou timeline. Alterar (motivo da parada,
 * OP da máquina, cavidades do molde): permissão apontamentos (operador). Ver App\Services\Aplicativo\MaquinasAplicativo.
 */
class MaquinasAplicativoController extends Controller
{
    public function __construct(private MaquinasAplicativo $maquinas) {}

    public function index(): JsonResponse
    {
        return response()->json(['maquinas' => $this->maquinas->lista()]);
    }

    public function show(int $maquina): JsonResponse
    {
        return response()->json($this->maquinas->detalhe($this->maquinas->conferirMaquina($maquina)));
    }

    // Ids buscados aqui (sem "route model binding"): assim o usuário do token já está definido e os escopos de
    // unidade valem.
    public function motivo(Request $request, int $apontamento): JsonResponse
    {
        $apontamento = ApontamentoEstado::with('legenda', 'ordemProducao')->findOrFail($apontamento);
        $dados = $request->validate(['legenda_id' => ['required', 'integer']], ['legenda_id.required' => 'Escolha o motivo da parada.']);
        $this->maquinas->informarMotivo($apontamento, (int) $dados['legenda_id']);

        return response()->json($this->maquinas->detalhe($this->maquinas->conferirMaquina($apontamento->maquina_id)));
    }

    public function op(Request $request, int $maquina): JsonResponse
    {
        $dados = $request->validate(['numero' => ['required', 'string', 'max:20']], ['numero.required' => 'Informe a ordem de produção.']);
        $registro = $this->maquinas->conferirMaquina($maquina);
        $this->maquinas->trocarOp($registro, $dados['numero']);

        return response()->json($this->maquinas->detalhe($registro));
    }

    public function cavidades(Request $request, int $ferramenta): JsonResponse
    {
        $ferramenta = Ferramenta::findOrFail($ferramenta);
        $dados = $request->validate(['cavidades' => ['required', 'integer'], 'maquina_id' => ['required', 'integer']]);
        $maquina = $this->maquinas->conferirMaquina((int) $dados['maquina_id']);
        $this->maquinas->alterarCavidades($ferramenta, (int) $dados['cavidades']);

        return response()->json($this->maquinas->detalhe($maquina));
    }

    public function ops(Request $request): JsonResponse
    {
        return response()->json(['ops' => $this->maquinas->buscarOps((string) $request->query('busca', ''))]);
    }
}
