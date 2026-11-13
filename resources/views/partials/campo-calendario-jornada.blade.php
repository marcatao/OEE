{{--
  Calendário de dias úteis + dias da semana com o horário de cada um (máquinas). Obrigatórios para ativar.
  Parâmetros: $registro (calendario_id e a jornada) e $calendarios (lista para escolher).
--}}
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
</div>
@include('partials.jornada-semanal', ['registro' => $registro])
