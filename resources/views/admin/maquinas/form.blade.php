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
      e os canais IoT e marque <strong>Ativa</strong> para ela entrar nos painéis.</p>
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
        <label>Linha <small class="suave">(obrigatória para ativar)</small>
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
          <select name="centro_custo" @disabled($maquina->doProtheus())>
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
        <a href="{{ route('admin.iot.index') }}">Dispositivos IoT</a>, só os ativos), o canal (ex.: <code>ch1_value</code>) e o tipo.</p>
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
            <input name="iot{{ $n }}_canal" maxlength="100" value="{{ old("iot{$n}_canal", $maquina->{"iot{$n}_canal"}) }}" placeholder="Ex.: ch1_value">
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
