{{--
  Calendário de dias úteis + dias da semana + horário de trabalho (máquinas e mantenedores). Obrigatórios para ativar.
  Parâmetros: $registro (calendario_id, dias_semana, jornada_inicio, jornada_fim), $calendarios (lista para escolher)
  e opcional $semJornada (true = os campos de jornada ficam em outro lugar do formulário, ex.: mantenedor).
--}}
@php($diasMarcados = array_map('intval', old('dias', str_split((string) $registro->dias_semana))))
<div class="campos">
  <label class="duplo">Calendário
    <select name="calendario_id" data-busca="Buscar calendário…">
      <option value="">— escolha —</option>
      @foreach ($calendarios as $c)
        <option value="{{ $c->id }}" @selected((int) old('calendario_id', $registro->calendario_id) === $c->id)>{{ $c->nome }} {{ $c->ano }} · {{ $c->rotuloUnidade() }}{{ $c->ativo ? '' : ' (inativo)' }}</option>
      @endforeach
    </select>
    <small>Dias úteis, feriados, pontes e compensações (<a href="{{ route('admin.calendarios.index') }}">Administração › Calendários</a>).</small>
  </label>
  @unless ($semJornada ?? false)
    <label>Começa às <input type="time" name="jornada_inicio" value="{{ old('jornada_inicio', $registro->jornada_inicio) }}"></label>
    <label>Termina às <input type="time" name="jornada_fim" value="{{ old('jornada_fim', $registro->jornada_fim) }}"><small>Antes do início = vira o dia.</small></label>
  @endunless
</div>
<fieldset class="opcoes-linha">
  <legend>Dias da semana em que trabalha</legend>
  @foreach ([1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'] as $n => $rotulo)
    <label class="opcao"><input type="checkbox" name="dias[]" value="{{ $n }}" @checked(in_array($n, $diasMarcados, true))> <span>{{ $rotulo }}</span></label>
  @endforeach
</fieldset>
