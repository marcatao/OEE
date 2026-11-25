<?php

namespace App\Services\InventarioTi;

use App\Models\InventarioTi\ItemTi;
use App\Models\InventarioTi\MovimentacaoItemTi;
use App\Models\InventarioTi\TermoResponsabilidade;
use App\Models\InventarioTi\TestemunhaTermo;
use App\Models\Usuario;
use App\Support\AssinaturaPng;
use App\Support\ContratoComodato;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Termo de responsabilidade do Inventário de TI: nasce pendente na entrega do item, o responsável assina no portal
 * (celular ou computador, com o dedo ou o mouse — o mesmo quadro de assinatura da avaliação da OS no aplicativo) ou
 * assina na frente do administrador da unidade, no aparelho dele (presencial). Assinado, não muda mais.
 */
class TermosResponsabilidade
{
    /** Termo pendente para o responsável atual (os pendentes anteriores do item são cancelados). */
    public function gerar(ItemTi $item, Usuario $por): TermoResponsabilidade
    {
        if (! $item->responsavel_id) {
            throw ValidationException::withMessages(['termo' => 'O item não tem responsável.']);
        }
        if (! $item->temTermo()) {
            throw ValidationException::withMessages(['termo' => 'Licença de software não tem termo de responsabilidade.']);
        }

        return DB::transaction(function () use ($item, $por) {
            $this->cancelarPendentes($item, 'Termo novo gerado', $por);
            $termo = TermoResponsabilidade::create(['item_id' => $item->id, 'usuario_id' => $item->responsavel_id, 'status' => 'pendente',
                'conteudo' => $this->conteudo($item), 'gerado_por_id' => $por->id]);
            $this->historico($item, null, "{$termo->numeroFormatado()} gerado para ".($item->responsavel?->nome ?? '—'), $por);
            // As testemunhas costumam ser as mesmas: vêm do termo mais recente que tinha testemunhas (o administrador troca).
            $anterior = TestemunhaTermo::where('termo_id', '<>', $termo->id)->orderByDesc('termo_id')->value('termo_id');
            if ($anterior) {
                foreach (TestemunhaTermo::where('termo_id', $anterior)->orderBy('ordem')->get() as $te) {
                    if ((int) $te->usuario_id !== (int) $termo->usuario_id) {
                        TestemunhaTermo::create(['termo_id' => $termo->id, 'ordem' => $te->ordem, 'usuario_id' => $te->usuario_id, 'definido_por_id' => $por->id]);
                    }
                }
            }

            return $termo;
        });
    }

    public function cancelarPendentes(ItemTi $item, string $motivo, ?Usuario $por): void
    {
        foreach (TermoResponsabilidade::pendentes()->where('item_id', $item->id)->get() as $t) {
            $t->update(['status' => 'cancelado', 'cancelado_em' => now(), 'motivo_cancelamento' => $motivo]);
            $this->historico($item, $t->numeroFormatado().' pendente', 'Cancelado: '.$motivo, $por);
        }
    }

    /**
     * Assina o termo. O próprio responsável assina no portal; o administrador da unidade pode colher a assinatura dele
     * na hora (presencial, no aparelho do administrador).
     */
    public function assinar(TermoResponsabilidade $termo, Usuario $logado, ?string $assinatura, ?string $nome, ?string $ip, ?string $navegador, array $comodatario = []): TermoResponsabilidade
    {
        if (! $this->podeAssinar($termo, $logado)) {
            abort(403, 'Só o responsável pelo item (ou o administrador da unidade, na frente dele) assina este termo.');
        }
        $png = AssinaturaPng::conferir($assinatura, 'assinatura', 'Assine no quadro antes de confirmar.');

        return DB::transaction(function () use ($termo, $logado, $png, $nome, $ip, $navegador, $comodatario) {
            $termo = TermoResponsabilidade::lockForUpdate()->findOrFail($termo->id);
            if ($termo->status !== 'pendente') {
                throw ValidationException::withMessages(['assinatura' => 'Este termo já foi '.($termo->status === 'assinado' ? 'assinado' : 'cancelado').'.']);
            }
            $presencial = (int) $logado->id !== (int) $termo->usuario_id;
            // Qualificação do comodatário (nacionalidade, cargo, RG, CPF, endereço) fica no termo, como no contrato em papel.
            $conteudo = $termo->conteudo ?? [];
            $conteudo['comodatario'] = array_map(fn ($v) => mb_substr(trim((string) $v), 0, 255), $comodatario);
            $termo->update(['status' => 'assinado', 'conteudo' => $conteudo, 'assinado_em' => now(), 'imagem_png' => $png, 'registrado_por_id' => $logado->id,
                'presencial' => $presencial, 'nome_assinante' => mb_substr(trim((string) $nome) ?: (string) $termo->usuario?->nome, 0, 120),
                'assinado_ip' => $ip, 'assinado_navegador' => $navegador !== null ? mb_substr($navegador, 0, 255) : null]);
            $this->historico($termo->item, $termo->numeroFormatado().' pendente',
                'Assinado por '.$termo->nome_assinante.($presencial ? ' (presencial, no aparelho de '.$logado->nome.')' : ' no portal'), $logado);

            return $termo;
        });
    }

    /**
     * Define as duas testemunhas (usuários do portal). Só o administrador da unidade do item; testemunha que já assinou
     * não muda; o comodatário não é testemunha; as duas são pessoas diferentes. @param array<int, int|null> $porOrdem
     */
    public function definirTestemunhas(TermoResponsabilidade $termo, array $porOrdem, Usuario $por): void
    {
        abort_unless((bool) $termo->item?->podeEditar($por), 403, 'Só o administrador da unidade do item define as testemunhas.');
        if ($termo->status === 'cancelado') {
            throw ValidationException::withMessages(['testemunhas' => 'Termo cancelado.']);
        }
        $ids = array_values(array_filter(array_map('intval', $porOrdem)));
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['testemunhas' => 'As duas testemunhas têm de ser pessoas diferentes.']);
        }
        if (in_array((int) $termo->usuario_id, $ids, true)) {
            throw ValidationException::withMessages(['testemunhas' => 'O comodatário não pode ser testemunha do próprio contrato.']);
        }
        DB::transaction(function () use ($termo, $porOrdem, $por) {
            foreach ([1, 2] as $ordem) {
                $novo = (int) ($porOrdem[$ordem] ?? 0) ?: null;
                $atual = TestemunhaTermo::where('termo_id', $termo->id)->where('ordem', $ordem)->lockForUpdate()->first();
                if ((int) $atual?->usuario_id === (int) $novo) {
                    continue;
                }
                if ($atual?->assinou()) {
                    throw ValidationException::withMessages(['testemunhas' => "A testemunha {$ordem} já assinou e não pode ser trocada."]);
                }
                $atual?->delete();
                if ($novo) {
                    $te = TestemunhaTermo::create(['termo_id' => $termo->id, 'ordem' => $ordem, 'usuario_id' => $novo, 'definido_por_id' => $por->id]);
                    $this->historico($termo->item, null, "{$termo->numeroFormatado()}: testemunha {$ordem} = ".$te->usuario?->nome, $por);
                }
            }
        });
    }

    /** Assinatura de uma testemunha: ela mesma no portal, ou o administrador da unidade colhe na hora (presencial). */
    public function assinarTestemunha(TermoResponsabilidade $termo, int $ordem, Usuario $logado, ?string $assinatura, ?string $nome, ?string $rg, ?string $ip): TestemunhaTermo
    {
        $te = TestemunhaTermo::where('termo_id', $termo->id)->where('ordem', $ordem)->first();
        if (! $te || ! $this->podeAssinarComoTestemunha($termo, $te, $logado)) {
            abort(403, 'Só a testemunha (ou o administrador da unidade, na frente dela) assina aqui.');
        }
        $png = AssinaturaPng::conferir($assinatura, 'assinatura_testemunha', 'Assine no quadro antes de confirmar.');

        return DB::transaction(function () use ($te, $termo, $logado, $png, $nome, $rg, $ip) {
            $te = TestemunhaTermo::lockForUpdate()->findOrFail($te->id);
            if ($te->assinou()) {
                throw ValidationException::withMessages(['assinatura_testemunha' => 'Esta testemunha já assinou.']);
            }
            $presencial = (int) $logado->id !== (int) $te->usuario_id;
            $te->update(['assinado_em' => now(), 'imagem_png' => $png, 'registrado_por_id' => $logado->id, 'presencial' => $presencial,
                'nome' => mb_substr(trim((string) $nome) ?: (string) $te->usuario?->nome, 0, 120), 'rg' => mb_substr(trim((string) $rg), 0, 30),
                'assinado_ip' => $ip]);
            $this->historico($termo->item, null, "{$termo->numeroFormatado()}: testemunha {$te->ordem} ({$te->nome}) assinou"
                .($presencial ? ' (presencial, com '.$logado->nome.')' : ' no portal'), $logado);

            return $te;
        });
    }

    public function podeAssinarComoTestemunha(TermoResponsabilidade $termo, TestemunhaTermo $te, Usuario $u): bool
    {
        return $termo->status !== 'cancelado' && ! $te->assinou()
            && ((int) $te->usuario_id === (int) $u->id || (bool) $termo->item?->podeEditar($u));
    }

    /** RG que a testemunha informou da última vez (para não digitar de novo). */
    public function rgAnteriorTestemunha(Usuario $u): ?string
    {
        return TestemunhaTermo::where('usuario_id', $u->id)->whereNotNull('assinado_em')->orderByDesc('assinado_em')->value('rg');
    }

    /** Ver o termo: o responsável dele, as testemunhas, ou quem enxerga o inventário daquela unidade. */
    public function podeVer(TermoResponsabilidade $termo, Usuario $u): bool
    {
        if ((int) $termo->usuario_id === (int) $u->id || TestemunhaTermo::where('termo_id', $termo->id)->where('usuario_id', $u->id)->exists()) {
            return true;
        }
        $item = $termo->item;

        return $u->pode('inventario_ti') && $item && $this->enxergaUnidade($u, (string) $item->unidade);
    }

    public function podeAssinar(TermoResponsabilidade $termo, Usuario $u): bool
    {
        return $termo->status === 'pendente'
            && ((int) $termo->usuario_id === (int) $u->id || (bool) $termo->item?->podeEditar($u));
    }

    /** Termos que o usuário ainda precisa assinar: como comodatário (pendentes) ou como testemunha (não cancelados). */
    public function pendentesDe(Usuario $u): Collection
    {
        return TermoResponsabilidade::where(fn ($q) => $q
            ->where(fn ($w) => $w->where('status', 'pendente')->where('usuario_id', $u->id))
            ->orWhere(fn ($w) => $w->where('status', '<>', 'cancelado')
                ->whereIn('id', TestemunhaTermo::select('termo_id')->where('usuario_id', $u->id)->whereNull('assinado_em'))))
            ->with('item.tipo')->orderBy('criado_em')->orderBy('id')->get();
    }

    /** Tem algo do inventário com ele (item ativo ou termo)? Mostra o "Meus equipamentos" no menu. Uma consulta por página. */
    public static function temEquipamentos(Usuario $u): bool
    {
        return ItemTi::withoutGlobalScopes()->where('responsavel_id', $u->id)->where('situacao', '<>', 'baixado')->exists()
            || TermoResponsabilidade::where('usuario_id', $u->id)->where('status', '<>', 'cancelado')->exists()
            || TestemunhaTermo::where('usuario_id', $u->id)->exists();
    }

    /** Dados do item congelados no termo (o que foi entregue). */
    public function conteudo(ItemTi $item): array
    {
        $item->loadMissing(['tipo', 'responsavel', 'vinculado']);

        return array_filter([
            'patrimonio' => $item->patrimonio,
            'tipo' => $item->tipo?->nome,
            'descricao' => $item->descricao,
            'marca_modelo' => $item->marcaModelo() ?: null,
            'numero_serie' => $item->numero_serie,
            'hostname' => $item->hostname,
            'especificacao' => $item->especificacao,
            'acessorios' => $item->acessorios,
            'linha_numero' => $item->linha_numero,
            'operadora' => $item->operadora,
            'plano' => $item->plano,
            'aparelho' => $item->vinculado?->identificacao(),
            'centro_custo' => $item->centro_custo,
            'local' => $item->local,
            'responsavel' => $item->responsavel?->nome,
            'responsavel_email' => $item->responsavel?->email,
            'responsavel_matricula' => $item->responsavel?->matricula,
            'responsavel_cargo' => $item->responsavel?->cargo,
            'categoria' => $item->categoria,
            'telefonia' => $item->ehLinha() || in_array(mb_strtolower((string) $item->tipo?->nome), array_map('mb_strtolower', ContratoComodato::TIPOS_TELEFONIA), true),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * O que é dado em comodato, numa frase como no contrato da empresa: "01 SMARTPHONE GALAXY A56 5G S/N: … e
     * carregador em ótimas condições."
     */
    public static function descricaoComodato(array $dados): string
    {
        $nome = trim((string) ($dados['descricao'] ?? ''));
        $marcaModelo = trim((string) ($dados['marca_modelo'] ?? ''));
        if ($marcaModelo !== '' && ! str_contains(mb_strtolower($nome), mb_strtolower($marcaModelo))) {
            $nome .= ' '.$marcaModelo;
        }
        $partes = ['01 '.mb_strtoupper($nome)];
        if (! empty($dados['patrimonio'])) {
            $partes[] = 'PATRIMÔNIO: '.$dados['patrimonio'];
        }
        if (! empty($dados['numero_serie'])) {
            $partes[] = (($dados['categoria'] ?? '') === 'linha' ? 'ICCID: ' : 'S/N: ').$dados['numero_serie'];
        }
        if (! empty($dados['linha_numero'])) {
            $partes[] = 'LINHA: '.$dados['linha_numero'].(! empty($dados['operadora']) ? ' ('.$dados['operadora'].')' : '');
        }
        if (! empty($dados['aparelho'])) {
            $partes[] = 'NO APARELHO '.$dados['aparelho'];
        }
        $texto = implode(' ', $partes);
        $acessorios = trim((string) ($dados['acessorios'] ?? ''));

        return $texto.($acessorios !== '' ? ' e '.mb_strtolower($acessorios) : '').' em ótimas condições.';
    }

    /** Cláusulas do contrato para este termo (as de telefonia só para celular, tablet e linha). @return list<string> */
    public static function clausulas(array $dados): array
    {
        $telefonia = (bool) ($dados['telefonia'] ?? false);

        return array_values(array_map(fn ($c) => $c['texto'], array_filter(ContratoComodato::CLAUSULAS,
            fn ($c) => $telefonia || empty($c['telefonia']))));
    }

    /** Dados do comodatário do último termo assinado por ele (para não digitar de novo). */
    public function comodatarioAnterior(Usuario $u): array
    {
        $ultimo = TermoResponsabilidade::where('usuario_id', $u->id)->where('status', 'assinado')->orderByDesc('assinado_em')->orderByDesc('id')->first();

        return ($ultimo?->conteudo['comodatario'] ?? []) + ['nacionalidade' => 'Brasileiro(a)', 'cargo' => (string) $u->cargo];
    }

    /** CPF com os dígitos verificadores certos (com ou sem pontuação). */
    public static function cpfValido(string $cpf): bool
    {
        $n = preg_replace('/\D/', '', $cpf);
        if (strlen($n) !== 11 || preg_match('/^(\d)\1{10}$/', $n)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $n[$i] * ($t + 1 - $i);
            }
            if ((int) $n[$t] !== ((10 * $soma) % 11) % 10) {
                return false;
            }
        }

        return true;
    }

    public static function formatarCpf(string $cpf): string
    {
        $n = preg_replace('/\D/', '', $cpf);

        return substr($n, 0, 3).'.'.substr($n, 3, 3).'.'.substr($n, 6, 3).'-'.substr($n, 9, 2);
    }

    private function enxergaUnidade(Usuario $u, string $unidade): bool
    {
        $visiveis = $u->unidadesVisiveis();

        return $visiveis === null || in_array($unidade, $visiveis, true);
    }

    private function historico(?ItemTi $item, ?string $de, string $para, ?Usuario $por): void
    {
        if ($item) {
            MovimentacaoItemTi::create(['item_id' => $item->id, 'campo' => 'termo', 'valor_anterior' => $de, 'valor_novo' => mb_substr($para, 0, 255),
                'usuario_id' => $por?->id]);
        }
    }
}
