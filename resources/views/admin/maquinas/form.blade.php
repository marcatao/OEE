@extends('layouts.portal')

@section('titulo', $maquina->exists ? 'Editar máquina' : 'Nova máquina')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Administração › <a href="{{ route('admin.maquinas.index') }}">Máquinas</a></p>
  <h1>{{ $maquina->exists ? $maquina->nome : 'Nova máquina' }}</h1>

  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif
  @if ($maquina->excluidaNoProtheus())
    <p class="aviso erro">Esta máquina foi <strong>excluída no Protheus</strong> (SHB) em @dataHora($maquina->excluida_protheus_em)
      e foi desativada. Ela não pode ser ativada de novo; o histórico continua.</p>
  @elseif ($maquina->aguardandoCadastro())
    <p class="aviso alerta">Máquina vinda do Protheus. Escolha a <strong>linha</strong>, preencha o ciclo ideal, as paradas
      e (nas máquinas da Manutenção) os canais IoT e o calendário, e marque <strong>Ativa</strong> para ela entrar nos painéis.</p>
  @endif

  <form method="post" class="formulario-vertical" style="max-width:none"
        action="{{ $maquina->exists ? route('admin.maquinas.update', $maquina) : route('admin.maquinas.store') }}">
    @csrf
    @if ($maquina->exists) @method('put') @endif

    <fieldset>
      <legend>Identificação</legend>
      @if ($maquina->doProtheus())
        <p class="suave"><span class="etiqueta origem-protheus">Protheus</span> Código, nome e centro de custo vêm da tabela
          SHB do Protheus @if ($maquina->sincronizado_em) (sincronizada em @dataHora($maquina->sincronizado_em))@endif e não são
          alterados aqui.</p>
      @endif
      <div class="campos">
        @include('partials.campo-unidade', ['registro' => $maquina])
        <label>Código
          <input name="codigo" required maxlength="30" value="{{ old('codigo', $maquina->codigo) }}" placeholder="Ex.: INJ-01" @disabled($maquina->doProtheus())>
        </label>
        <label>Nome
          <input name="nome" required maxlength="120" value="{{ old('nome', $maquina->nome) }}" placeholder="Ex.: Injetora 01" @disabled($maquina->doProtheus())>
        </label>
        <label><span>Linha <small>(obrigatória para ativar)</small></span>
          <select name="linha_id">
            <option value="">Escolha…</option>
            @foreach ($linhas as $planta => $doGrupo)
              <optgroup label="{{ $planta }}">
                @foreach ($doGrupo as $linha)
                  <option value="{{ $linha->id }}" @selected((int) old('linha_id', $maquina->linha_id) === $linha->id)>{{ $linha->nome }}</option>
                @endforeach
              </optgroup>
            @endforeach
          </select>
        </label>
        <label>Setor / centro de custo
          @php($ccAtual = old('centro_custo', $maquina->centro_custo))
          <select name="centro_custo" data-busca @disabled($maquina->doProtheus())>
            <option value="">Nenhum</option>
            @foreach ($centros as $cc)
              <option value="{{ $cc->codigo() }}" @selected($ccAtual === $cc->codigo())>{{ $cc->codigo() }} — {{ $cc->descricao() }}</option>
            @endforeach
            @if ($ccAtual && ! $centros->contains(fn ($c) => $c->codigo() === $ccAtual))
              <option value="{{ $ccAtual }}" selected>{{ $ccAtual }} (bloqueado ou não encontrado)</option>
            @endif
          </select>
          <small>Grava só o código do centro de custo (CTT_CUSTO). Cadastro em Administração › Centros de custo.</small>
        </label>
        <label>Fabricante
          <input name="fabricante" maxlength="120" value="{{ old('fabricante', $maquina->fabricante) }}">
        </label>
        <label>Modelo
          <input name="modelo" maxlength="120" value="{{ old('modelo', $maquina->modelo) }}">
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Produção e paradas</legend>
      <div class="campos">
        <label>Ciclo ideal (segundos por peça)
          <input name="ciclo_ideal_s" type="number" step="any" min="0" value="{{ old('ciclo_ideal_s', $maquina->ciclo_ideal_s) }}">
          <small>Copiado para cada apontamento; mudar aqui não altera o histórico.</small>
        </label>
        <label>Micro parada (segundos)
          <input name="micro_parada_s" type="number" step="1" min="1" value="{{ old('micro_parada_s', $maquina->micro_parada_s) }}" placeholder="Ex.: 60">
          <small>Parada de até este tempo é micro parada (perda de performance).</small>
        </label>
        <label>Macro parada (segundos)
          <input name="macro_parada_s" type="number" step="1" min="1" value="{{ old('macro_parada_s', $maquina->macro_parada_s) }}" placeholder="Ex.: 300">
          <small>Parada a partir deste tempo é macro parada (perda de disponibilidade). Deve ser maior que a micro.</small>
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Canais IoT</legend>
      <p class="suave" style="margin-top:0">De onde vêm os dados da máquina: o dispositivo (cadastro em
        <a href="{{ route('admin.iot.index') }}">Dispositivos IoT</a>, só os ativos), o canal (<code>chd1_value</code> a <code>chd6_value</code>) e o tipo.
        Nas máquinas da <strong>Manutenção</strong>, para <strong>ativar</strong> é preciso pelo menos um canal com dispositivo ativo (sem canal, ela
        fica inativa), além do calendário e dos dias de trabalho; nas de Tecnologia e Ferramentaria esses campos são opcionais.
        Um dispositivo + canal só pode estar em uma máquina.</p>
      @if ($dispositivos->isEmpty())
        <p class="aviso alerta">Nenhum dispositivo IoT ativo. Cadastre em <a href="{{ route('admin.iot.index') }}">Dispositivos IoT</a>.</p>
      @endif
      @foreach (\App\Models\Maquina::CANAIS_IOT as $n)
        {{-- Valor antigo (texto livre): sugere o dispositivo de mesmo código, mas só grava se o usuário salvar. --}}
        @php($textoAntigo = (string) $maquina->{"iot{$n}_id"})
        @php($semDispositivo = ! $maquina->{"iot{$n}_dispositivo_id"} && $textoAntigo !== '')
        @php($sugerido = $semDispositivo ? $dispositivos->first(fn ($d) => mb_strtolower($d->codigo) === mb_strtolower($textoAntigo))?->id : null)
        @php($atual = (int) old("iot{$n}_dispositivo_id", $maquina->{"iot{$n}_dispositivo_id"} ?: $sugerido))
        <div class="campos" style="margin-top:.75rem">
          <label>IoT {{ $n }} — Dispositivo IoT
            <select name="iot{{ $n }}_dispositivo_id">
              <option value="">—</option>
              @foreach ($dispositivos as $d)
                <option value="{{ $d->id }}" @selected($atual === $d->id)>{{ $d->nome }} ({{ $d->codigo }}){{ $d->ativo ? '' : ' — inativo' }}</option>
              @endforeach
            </select>
            @if ($semDispositivo)
              <small class="erro">Antes era o texto <code>{{ $textoAntigo }}</code>.
                {{ $sugerido ? 'Sugerimos o dispositivo de mesmo código: confira e salve.' : 'Escolha o dispositivo correspondente e salve.' }}</small>
            @endif
          </label>
          <label>IoT {{ $n }} — Canal IoT
            @php($canalAtual = (string) old("iot{$n}_canal", $maquina->{"iot{$n}_canal"}))
            <select name="iot{{ $n }}_canal">
              <option value="">—</option>
              @foreach (config('oee.iot_canais') as $canal)
                <option value="{{ $canal }}" @selected($canalAtual === $canal)>{{ $canal }}</option>
              @endforeach
              @if ($canalAtual !== '' && ! in_array($canalAtual, config('oee.iot_canais'), true))
                <option value="{{ $canalAtual }}" selected>{{ $canalAtual }} (antigo)</option>
              @endif
            </select>
          </label>
          <label>IoT {{ $n }} — Tipo do canal
            <select name="iot{{ $n }}_tipo">
              <option value="">—</option>
              @foreach (\App\Models\Maquina::TIPOS_CANAL as $chave => $rotulo)
                <option value="{{ $chave }}" @selected(old("iot{$n}_tipo", $maquina->{"iot{$n}_tipo"}) === $chave)>{{ $rotulo }}</option>
              @endforeach
            </select>
          </label>
        </div>
      @endforeach
    </fieldset>

    <fieldset>
      <legend>Situação atual</legend>
      <p class="suave">Atualizada sozinha pelo apontamento real mais recente (o tipo da legenda diz se a máquina está produzindo ou parada,
        e a OP do apontamento vira a OP da máquina; apontamento sem OP desvincula). Também pode ser informada aqui. A Timeline mostra estes dados.</p>
      <div class="campos">
        <label>Máquina trabalhando ou parada
          <select name="situacao">
            <option value="">Sem informação</option>
            @foreach (\App\Models\Maquina::SITUACOES as $valor => $rotulo)
              <option value="{{ $valor }}" @selected(old('situacao', $maquina->situacao) === $valor)>{{ $rotulo }}</option>
            @endforeach
          </select>
          @if ($maquina->situacao_desde)<small>desde @dataHora($maquina->situacao_desde)@if ($maquina->situacaoLegenda) · pela legenda {{ $maquina->situacaoLegenda->codigo }} – {{ $maquina->situacaoLegenda->descricao }}@endif</small>@endif
        </label>
        @include('partials.campo-op', ['op' => $maquina->ordemProducao, 'rotulo' => 'OP atual',
          'ajuda' => 'Busque pela OP ou pelo produto. "Sem OP" deixa a máquina sem OP.'])
      </div>
    </fieldset>

    <fieldset>
      <legend>Calendário e jornada</legend>
      <p class="suave" style="margin-top:0">Obrigatórios para <strong>ativar</strong> a máquina: o calendário diz os dias úteis, feriados e pontes;
        os dias da semana e o horário dizem quando a máquina trabalha.</p>
      @include('partials.campo-calendario-jornada', ['registro' => $maquina, 'calendarios' => $calendarios])
    </fieldset>

    <fieldset class="opcoes-linha">
      <legend>Máquina ativa ou inativa</legend>
      <label class="opcao"><input type="radio" name="ativo" value="1" @disabled($maquina->excluidaNoProtheus()) @checked(old('ativo', (int) $maquina->ativo) == 1)> <span>Ativa</span></label>
      <label class="opcao"><input type="radio" name="ativo" value="0" @checked(old('ativo', (int) $maquina->ativo) == 0)> <span>Inativa <small>sai dos painéis e da timeline, mas o histórico continua</small></span></label>
    </fieldset>

    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('admin.maquinas.index') }}">Cancelar</a>
    </div>
  </form>
</section>
@endsection
