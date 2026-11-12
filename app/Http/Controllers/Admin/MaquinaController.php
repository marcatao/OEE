<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CentroCusto;
use App\Models\DispositivoIot;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Support\Unidades;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Administração > Máquinas. Só administradores. Máquinas são desativadas, nunca excluídas.
 * Tabela mista com o Protheus (SHB): nas máquinas que vieram de lá, código, nome e centro de custo não se editam
 * aqui; o usuário completa o resto e ativa. Para ativar é preciso ter linha.
 */
class MaquinaController extends Controller
{
    public function index(Request $request): View
    {
        $busca = trim((string) $request->query('busca'));
        $situacao = (string) $request->query('situacao');
        $aguardando = fn ($q) => $q->whereNotNull('r_e_c_n_o_')->where('ativo', false)->where('d_e_l_e_t_', '<>', '*');

        return view('admin.maquinas.index', [
            'busca' => $busca,
            'situacao' => $situacao,
            'maquinas' => Maquina::with(['linha.planta', 'centroCusto', 'iot1Dispositivo', 'iot2Dispositivo', 'iot3Dispositivo', 'ordemProducao'])
                ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('nome', 'like', "%{$busca}%")->orWhere('codigo', 'like', "%{$busca}%")))
                ->when($situacao === 'aguardando', $aguardando)
                ->when($situacao === 'ativas', fn ($q) => $q->where('ativo', true))
                ->when($situacao === 'excluidas', fn ($q) => $q->where('d_e_l_e_t_', '*'))
                ->orderByDesc('ativo')->orderBy('nome')->paginate(25)->withQueryString(),
            'qtdAguardando' => Maquina::where($aguardando)->count(),
            'temLinhas' => Linha::where('ativo', true)->exists(),
        ]);
    }

    public function create(): View
    {
        return $this->formulario(new Maquina(['ativo' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        $maquina = new Maquina;
        $aviso = $this->salvar($request, $maquina);

        return redirect()->route('admin.maquinas.index')->with('ok', trim("Máquina {$maquina->nome} cadastrada. ".$aviso));
    }

    public function edit(Maquina $maquina): View
    {
        return $this->formulario($maquina);
    }

    public function update(Request $request, Maquina $maquina): RedirectResponse
    {
        $aviso = $this->salvar($request, $maquina);

        return redirect()->route('admin.maquinas.index')->with('ok', trim("Máquina {$maquina->nome} atualizada. ".$aviso));
    }

    private function formulario(Maquina $maquina): View
    {
        return view('admin.maquinas.form', [
            'maquina' => $maquina,
            'linhas' => Linha::with('planta')
                ->where(fn ($q) => $q->where('ativo', true)->orWhere('id', $maquina->linha_id))
                ->get()->sortBy(fn ($l) => $l->planta?->nome.' '.$l->nome)->groupBy(fn ($l) => $l->planta?->nome ?? 'Sem planta'),
            // Mesmo código pode existir em mais de uma filial: a lista mostra um por código.
            'centros' => CentroCusto::selecionaveis()->orderBy('ctt_custo')->get()
                ->unique(fn ($c) => $c->codigo())->values(),
            // Combo dos canais IoT: dispositivos ativos + os que a máquina já usa (mesmo se desativados depois).
            'dispositivos' => DispositivoIot::where(fn ($q) => $q->where('ativo', true)
                ->orWhereIn('id', array_filter(array_map(fn ($n) => $maquina->{"iot{$n}_dispositivo_id"}, Maquina::CANAIS_IOT))))
                ->orderBy('nome')->get(),
        ]);
    }

    /** @return string|null aviso (ex.: máquina inativada por ficar sem canal IoT) */
    private function salvar(Request $request, Maquina $maquina): ?string
    {
        $regras = [
            'unidade' => Unidades::regras($request->user(), $maquina->exists ? $maquina->unidade : null),
            'linha_id' => ['nullable', 'required_if:ativo,1', 'integer', Rule::exists('linhas', 'id')],
            'codigo' => ['required', 'string', 'max:30', Rule::unique('maquinas', 'codigo')->ignore($maquina->id)],
            'nome' => ['required', 'string', 'max:120'],
            'fabricante' => ['nullable', 'string', 'max:120'],
            'modelo' => ['nullable', 'string', 'max:120'],
            'ciclo_ideal_s' => ['nullable', 'numeric', 'gt:0', 'max:86400'],
            'centro_custo' => ['nullable', 'string', 'max:20', Rule::exists('centros_custo', 'ctt_custo')],
            'micro_parada_s' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'macro_parada_s' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'ativo' => ['required', 'boolean'],
            'situacao' => ['nullable', Rule::in(array_keys(Maquina::SITUACOES))],
            'ordem_producao' => ['nullable', 'string', 'max:20'],
        ];
        foreach (Maquina::CANAIS_IOT as $n) {
            // Dispositivo ativo do cadastro (ou o que a máquina já usa, mesmo que tenha sido desativado depois).
            $regras["iot{$n}_dispositivo_id"] = ['nullable', 'integer', "required_with:iot{$n}_canal,iot{$n}_tipo",
                Rule::exists('dispositivos_iot', 'id')->where(fn ($q) => $q->where('ativo', true)
                    ->orWhere('id', (int) $maquina->{"iot{$n}_dispositivo_id"}))];
            // Canal: um dos 6 canais do dispositivo (oee.iot_canais); o texto antigo que a máquina já tinha continua aceito.
            $regras["iot{$n}_canal"] = ['nullable', 'string', 'max:100', "required_with:iot{$n}_dispositivo_id,iot{$n}_tipo",
                Rule::in(array_values(array_filter([...config('oee.iot_canais', []), $maquina->{"iot{$n}_canal"}])))];
            $regras["iot{$n}_tipo"] = ['nullable', Rule::in(array_keys(Maquina::TIPOS_CANAL)), "required_with:iot{$n}_dispositivo_id,iot{$n}_canal"];
        }

        $mensagens = [
            'linha_id.required_if' => 'Para ativar a máquina, escolha a linha.',
            'linha_id.exists' => 'Linha não encontrada.',
            'codigo.required' => 'Informe o código da máquina.',
            'codigo.unique' => 'Já existe uma máquina com esse código.',
            'nome.required' => 'Informe o nome da máquina.',
            'ciclo_ideal_s.gt' => 'O ciclo ideal deve ser maior que zero.',
            'ciclo_ideal_s.numeric' => 'O ciclo ideal deve ser um número (segundos por peça).',
            'centro_custo.exists' => 'Centro de custo não cadastrado.',
            'micro_parada_s.integer' => 'A micro parada deve ser um número inteiro de segundos.',
            'macro_parada_s.integer' => 'A macro parada deve ser um número inteiro de segundos.',
            '*.min' => 'Os tempos de parada devem ser maiores que zero.',
            '*.max' => 'Texto ou número muito grande.',
        ];
        foreach (Maquina::CANAIS_IOT as $n) {
            $mensagens["iot{$n}_dispositivo_id.required_with"] = "IoT {$n}: escolha o dispositivo IoT.";
            $mensagens["iot{$n}_dispositivo_id.exists"] = "IoT {$n}: dispositivo IoT não encontrado ou inativo.";
            $mensagens["iot{$n}_canal.required_with"] = "IoT {$n}: informe o canal IoT.";
            $mensagens["iot{$n}_tipo.required_with"] = "IoT {$n}: escolha o tipo do canal.";
            $mensagens["iot{$n}_tipo.in"] = "IoT {$n}: tipo de canal inválido.";
            $mensagens["iot{$n}_canal.in"] = "IoT {$n}: escolha um dos canais do dispositivo (chd1_value a chd6_value).";
        }

        // Máquina do Protheus: código, nome e centro de custo vêm da SHB e não são alterados aqui.
        if ($maquina->doProtheus()) {
            unset($regras['codigo'], $regras['nome'], $regras['centro_custo']);
        }

        $dados = $request->validate($regras, $mensagens);
        if ($maquina->excluidaNoProtheus() && $dados['ativo']) {
            throw ValidationException::withMessages(['ativo' => 'Esta máquina foi excluída no Protheus e não pode ser ativada.']);
        }
        if (isset($dados['micro_parada_s'], $dados['macro_parada_s']) && $dados['macro_parada_s'] <= $dados['micro_parada_s']) {
            throw ValidationException::withMessages(['macro_parada_s' => 'A macro parada deve ser maior que a micro parada.']);
        }
        foreach (Maquina::CANAIS_IOT as $n) {
            // Só o que veio do formulário (campo ausente não apaga o canal gravado).
            if (array_key_exists("iot{$n}_canal", $dados)) {
                $dados["iot{$n}_canal"] = isset($dados["iot{$n}_canal"]) ? trim($dados["iot{$n}_canal"]) : null;
            }
        }
        $this->conferirCanaisRepetidos($dados, $maquina);

        // Ativa só com pelo menos um canal IoT (dispositivo ATIVO + canal). Se a máquina estava ativa e ficou sem
        // canal (o canal foi apagado), ela passa a INATIVA.
        $aviso = null;
        if ($dados['ativo'] && ! $maquina->temCanalIotAtivo($dados)) {
            if (! $maquina->exists || ! $maquina->ativo) {
                throw ValidationException::withMessages(['ativo' => 'Para ativar a máquina, informe pelo menos um canal IoT (dispositivo IoT ativo + canal).']);
            }
            $dados['ativo'] = false;
            $aviso = 'A máquina ficou sem canal IoT ativo e foi INATIVADA.';
        }

        // OP atual, pelo número (C2_NUM + C2_ITEM + C2_SEQUEN). Vazio = máquina sem OP.
        $numeroOp = trim((string) ($dados['ordem_producao'] ?? ''));
        unset($dados['ordem_producao']);
        $op = null;
        if ($numeroOp !== '') {
            $op = OrdemProducao::where('numero', $numeroOp)->where('d_e_l_e_t_', '<>', '*')->orderBy('status')->first();
            if (! $op) {
                throw ValidationException::withMessages(['ordem_producao' => "Ordem de produção {$numeroOp} não encontrada."]);
            }
            if ($op->status === 'fechada' && $op->id !== $maquina->ordem_producao_id) {
                throw ValidationException::withMessages(['ordem_producao' => "A ordem de produção {$numeroOp} está fechada no Protheus."]);
            }
        }
        $dados['ordem_producao_id'] = $op?->id;
        // Situação mudada no cadastro: vale a partir de agora e deixa de seguir a legenda do último apontamento.
        $dados['situacao'] = ($dados['situacao'] ?? null) ?: null;
        if ($dados['situacao'] !== $maquina->situacao) {
            $dados['situacao_desde'] = $dados['situacao'] ? now() : null;
            $dados['situacao_legenda_id'] = null;
        }

        $maquina->fill($dados)->save();

        return $aviso;
    }

    /**
     * O mesmo canal (ID IoT + canal) não pode ser usado duas vezes: a produção seria contada em dobro.
     */
    private function conferirCanaisRepetidos(array $dados, Maquina $maquina): void
    {
        $usados = [];
        foreach (Maquina::CANAIS_IOT as $n) {
            if (empty($dados["iot{$n}_dispositivo_id"])) {
                continue;
            }
            $chave = $dados["iot{$n}_dispositivo_id"].'|'.mb_strtolower($dados["iot{$n}_canal"]);
            if (isset($usados[$chave])) {
                throw ValidationException::withMessages(["iot{$n}_canal" => "IoT {$n} repete o canal do IoT {$usados[$chave]}."]);
            }
            $usados[$chave] = $n;

            foreach (Maquina::CANAIS_IOT as $m) {
                $outra = Maquina::withoutGlobalScopes()->where("iot{$m}_dispositivo_id", $dados["iot{$n}_dispositivo_id"])->where("iot{$m}_canal", $dados["iot{$n}_canal"])
                    ->when($maquina->exists, fn ($q) => $q->whereKeyNot($maquina->id))->first(['id', 'nome']);
                if ($outra) {
                    $nomeDispositivo = DispositivoIot::withoutGlobalScopes()->whereKey($dados["iot{$n}_dispositivo_id"])->value('nome');
                    throw ValidationException::withMessages(["iot{$n}_canal" => "IoT {$n}: o canal {$nomeDispositivo} / {$dados["iot{$n}_canal"]} já está na máquina {$outra->nome}."]);
                }
            }
        }
    }
}
