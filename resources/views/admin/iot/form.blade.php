@extends('layouts.portal')

@section('titulo', $dispositivo->exists ? 'Editar dispositivo IoT' : 'Novo dispositivo IoT')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Administração › <a href="{{ route('admin.iot.index') }}">Dispositivos IoT</a></p>
  <h1>{{ $dispositivo->exists ? $dispositivo->nome : 'Novo dispositivo IoT' }}</h1>

  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif

  <form method="post" class="formulario-vertical"
        action="{{ $dispositivo->exists ? route('admin.iot.update', $dispositivo) : route('admin.iot.store') }}">
    @csrf
    @if ($dispositivo->exists) @method('put') @endif

    <div class="campos">
      <label>Código
        <input name="codigo" required maxlength="40" value="{{ old('codigo', $dispositivo->codigo) }}" placeholder="Ex.: SENS-INJ01">
      </label>
      <label>Nome
        <input name="nome" required maxlength="120" value="{{ old('nome', $dispositivo->nome) }}" placeholder="Ex.: Contador da Injetora 01">
      </label>
      <label>Tipo
        <select name="tipo" required>
          @foreach (\App\Models\DispositivoIot::TIPOS as $chave => $rotulo)
            <option value="{{ $chave }}" @selected(old('tipo', $dispositivo->tipo) === $chave)>{{ $rotulo }}</option>
          @endforeach
        </select>
      </label>
      <label>Protocolo
        <select name="protocolo" required>
          @foreach (\App\Models\DispositivoIot::PROTOCOLOS as $chave => $rotulo)
            <option value="{{ $chave }}" @selected(old('protocolo', $dispositivo->protocolo) === $chave)>{{ $rotulo }}</option>
          @endforeach
        </select>
      </label>
      <label>Endereço (IP ou host)
        <input name="endereco" maxlength="200" value="{{ old('endereco', $dispositivo->endereco) }}" placeholder="Ex.: 192.168.100.50">
      </label>
      <label>Porta
        <input name="porta" type="number" min="1" max="65535" value="{{ old('porta', $dispositivo->porta) }}" placeholder="Ex.: 1883">
      </label>
      <label>Tópico / nó / registrador
        <input name="topico" maxlength="250" value="{{ old('topico', $dispositivo->topico) }}" placeholder="Ex.: alumbra/injecao/inj01/pecas">
        <small>Tópico MQTT, nó OPC UA ou registrador Modbus de onde vêm os dados.</small>
      </label>
      <label>Máquina
        <select name="maquina_id">
          <option value="">Nenhuma (ainda não instalado)</option>
          @foreach ($maquinas as $m)
            <option value="{{ $m->id }}" @selected((int) old('maquina_id', $dispositivo->maquina_id) === $m->id)>{{ $m->nome }} ({{ $m->codigo }})</option>
          @endforeach
        </select>
      </label>
    </div>
    <label>Observação
      <textarea name="observacao" maxlength="500" rows="3">{{ old('observacao', $dispositivo->observacao) }}</textarea>
    </label>

    <fieldset>
      <legend>Situação</legend>
      <label class="opcao"><input type="radio" name="ativo" value="1" @checked(old('ativo', (int) $dispositivo->ativo) == 1)> <span>Ativo</span></label>
      <label class="opcao"><input type="radio" name="ativo" value="0" @checked(old('ativo', (int) $dispositivo->ativo) == 0)> <span>Inativo <small>os dados deste dispositivo deixam de ser considerados</small></span></label>
    </fieldset>

    @if ($dispositivo->exists)
      <p class="suave">Último sinal: @dataHora($dispositivo->ultimo_sinal_em) · {{ $dispositivo->rotuloSituacao() }}</p>
    @endif

    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('admin.iot.index') }}">Cancelar</a>
    </div>
  </form>
</section>
@endsection
