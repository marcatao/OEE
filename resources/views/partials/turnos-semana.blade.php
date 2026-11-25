{{--
  Dias da semana em que a MÁQUINA trabalha, com até 3 turnos por dia (pedido do usuário em 25/11/2026): turno 1
  (obrigatório no dia marcado), turno 2 e turno 3 (opcionais), cada um com o horário em que começa e termina. A soma
  dos turnos = horas disponíveis do dia. Campos: dias[] e turno[dia][n][inicio|fim]. Parâmetro: $registro.
  Marcar um dia sem horários copia os turnos do dia marcado mais próximo acima (ou abaixo).
--}}
@php
  $gravados = \App\Support\JornadaSemanal::turnos($registro);
  $marcados = array_map('intval', old('dias', array_keys($gravados)));
  $velho = old('turno', []);
@endphp
<fieldset class="jornada-semanal" data-turnos-semana>
  <legend>Dias e turnos de trabalho</legend>
  <div class="rolagem">
  <table class="tabela compacta turnos-tabela">
    <thead>
      <tr><th rowspan="2">Dia</th>
        @for ($n = 1; $n <= \App\Support\JornadaSemanal::MAX_TURNOS; $n++)<th colspan="2" class="turno-cab">Turno {{ $n }}</th>@endfor
        <th rowspan="2" class="num" title="Soma dos turnos do dia">Horas disponíveis</th></tr>
      <tr>@for ($n = 1; $n <= \App\Support\JornadaSemanal::MAX_TURNOS; $n++)<th>Começa</th><th>Termina</th>@endfor</tr>
    </thead>
    <tbody>
    @foreach (\App\Support\JornadaSemanal::DIAS as $d => $nome)
      @php($marcado = in_array($d, $marcados, true))
      <tr @class(['jornada-dia', 'folga' => ! $marcado])>
        <td><label class="opcao"><input type="checkbox" name="dias[]" value="{{ $d }}" @checked($marcado)> <span>{{ $nome }}</span></label></td>
        @for ($n = 1; $n <= \App\Support\JornadaSemanal::MAX_TURNOS; $n++)
          <td><input type="time" name="turno[{{ $d }}][{{ $n }}][inicio]" value="{{ $velho[$d][$n]['inicio'] ?? ($gravados[$d][$n][0] ?? '') }}" aria-label="{{ $nome }}: turno {{ $n }} começa às" @disabled(! $marcado)></td>
          <td><input type="time" name="turno[{{ $d }}][{{ $n }}][fim]" value="{{ $velho[$d][$n]['fim'] ?? ($gravados[$d][$n][1] ?? '') }}" aria-label="{{ $nome }}: turno {{ $n }} termina às" @disabled(! $marcado)></td>
        @endfor
        <td class="num" data-horas></td>
      </tr>
      @error("turno.$d")<tr><td colspan="8" class="erro">{{ $message }}</td></tr>@enderror
    @endforeach
    </tbody>
    <tfoot><tr><th colspan="7" class="num">Horas disponíveis na semana</th><th class="num" data-horas-semana></th></tr></tfoot>
  </table>
  </div>
  <small class="suave">Turno 1 obrigatório no dia marcado; turnos 2 e 3 só se a máquina trabalhar neles. Termina antes de começar = vira o dia
    (ex.: turno 3 das 22:00 às 06:00). O intervalo entre turnos não conta como hora disponível (vira "fora de turno").</small>
  @error('dias')<p class="erro">{{ $message }}</p>@enderror
</fieldset>
<script>
  (function () {
    var caixa = document.currentScript.previousElementSibling;
    var linhas = Array.prototype.slice.call(caixa.querySelectorAll('.jornada-dia'));
    function minutos(v) { var p = (v || '').split(':'); return p.length < 2 ? null : (+p[0]) * 60 + (+p[1]); }
    function hhmm(m) { return Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0'); }
    function horasDoDia(tr) {
      var t = tr.querySelectorAll('[type=time]'), total = 0;
      for (var i = 0; i < t.length; i += 2) {
        var a = minutos(t[i].value), b = minutos(t[i + 1].value);
        if (a !== null && b !== null && a !== b) total += b > a ? b - a : b + 1440 - a;
      }
      return total;
    }
    function atualizar() {
      var semana = 0;
      linhas.forEach(function (tr) {
        var marcado = tr.querySelector('[type=checkbox]').checked;
        tr.classList.toggle('folga', !marcado);
        tr.querySelectorAll('[type=time]').forEach(function (t) { t.disabled = !marcado; });
        var h = marcado ? horasDoDia(tr) : 0;
        semana += h;
        tr.querySelector('[data-horas]').textContent = marcado && h ? hhmm(h) : '';
      });
      caixa.querySelector('[data-horas-semana]').textContent = semana ? hhmm(semana) : '';
    }
    function copiar(tr) {
      var t = tr.querySelectorAll('[type=time]');
      for (var j = 0; j < t.length; j++) if (t[j].value) return;
      var i = linhas.indexOf(tr), outras = linhas.slice(0, i).reverse().concat(linhas.slice(i + 1));
      for (var k = 0; k < outras.length; k++) {
        var o = outras[k].querySelectorAll('[type=time]');
        if (outras[k].querySelector('[type=checkbox]').checked && o[0].value && o[1].value) {
          for (var x = 0; x < t.length; x++) t[x].value = o[x].value;
          return;
        }
      }
    }
    linhas.forEach(function (tr) {
      tr.querySelector('[type=checkbox]').addEventListener('change', function () { if (this.checked) copiar(tr); atualizar(); });
      tr.querySelectorAll('[type=time]').forEach(function (t) { t.addEventListener('input', atualizar); });
    });
    atualizar();
  })();
</script>
