{{--
  Dias da semana em que trabalha, cada um com o horário em que começa e termina (máquinas e mantenedores).
  Campos: dias[] (dia ISO 1 = segunda … 7 = domingo) e horario[N][inicio|fim]. Parâmetro: $registro.
  Marcar um dia sem horário copia o horário do dia marcado mais próximo acima (ou abaixo).
--}}
@php
  $gravados = \App\Support\JornadaSemanal::de($registro);
  $marcados = array_map('intval', old('dias', array_keys($gravados)));
  $horarioVelho = old('horario', []);
@endphp
<fieldset class="jornada-semanal" data-jornada-semanal>
  <legend>Dias e horário de trabalho</legend>
  <div class="rolagem">
  <table class="tabela compacta">
    <thead><tr><th>Dia</th><th>Começa às</th><th>Termina às</th><th class="num">Horas</th></tr></thead>
    <tbody>
    @foreach (\App\Support\JornadaSemanal::DIAS as $n => $nome)
      @php
        $ini = $horarioVelho[$n]['inicio'] ?? ($gravados[$n][0] ?? '');
        $fim = $horarioVelho[$n]['fim'] ?? ($gravados[$n][1] ?? '');
        $marcado = in_array($n, $marcados, true);
      @endphp
      <tr @class(['jornada-dia', 'folga' => ! $marcado])>
        <td><label class="opcao"><input type="checkbox" name="dias[]" value="{{ $n }}" @checked($marcado)> <span>{{ $nome }}</span></label></td>
        <td><input type="time" name="horario[{{ $n }}][inicio]" value="{{ $ini }}" aria-label="{{ $nome }}: começa às" @disabled(! $marcado)></td>
        <td><input type="time" name="horario[{{ $n }}][fim]" value="{{ $fim }}" aria-label="{{ $nome }}: termina às" @disabled(! $marcado)></td>
        <td class="num" data-horas></td>
      </tr>
      @error("horario.$n")<tr><td colspan="4" class="erro">{{ $message }}</td></tr>@enderror
    @endforeach
    </tbody>
  </table>
  </div>
  <small class="suave">Termina antes de começar = vira o dia (ex.: 22:00 às 06:00). Dia desmarcado = não trabalha.</small>
  @error('dias')<p class="erro">{{ $message }}</p>@enderror
</fieldset>
<script>
  (function () {
    var caixa = document.currentScript.previousElementSibling;
    var linhas = Array.prototype.slice.call(caixa.querySelectorAll('.jornada-dia'));
    function minutos(v) { var p = (v || '').split(':'); return p.length < 2 ? null : (+p[0]) * 60 + (+p[1]); }
    function atualizar(tr) {
      var marcado = tr.querySelector('[type=checkbox]').checked, t = tr.querySelectorAll('[type=time]');
      tr.classList.toggle('folga', !marcado);
      t[0].disabled = t[1].disabled = !marcado;
      var a = minutos(t[0].value), b = minutos(t[1].value), c = tr.querySelector('[data-horas]');
      if (!marcado || a === null || b === null || a === b) { c.textContent = ''; return; }
      var d = b > a ? b - a : b + 1440 - a;
      c.textContent = Math.floor(d / 60) + ':' + String(d % 60).padStart(2, '0');
    }
    function copiar(tr) {
      var t = tr.querySelectorAll('[type=time]');
      if (t[0].value || t[1].value) return;
      var i = linhas.indexOf(tr), outras = linhas.slice(0, i).reverse().concat(linhas.slice(i + 1));
      for (var k = 0; k < outras.length; k++) {
        var o = outras[k].querySelectorAll('[type=time]');
        if (outras[k].querySelector('[type=checkbox]').checked && o[0].value && o[1].value) { t[0].value = o[0].value; t[1].value = o[1].value; return; }
      }
    }
    linhas.forEach(function (tr) {
      tr.querySelector('[type=checkbox]').addEventListener('change', function () { if (this.checked) copiar(tr); atualizar(tr); });
      tr.querySelectorAll('[type=time]').forEach(function (t) { t.addEventListener('input', function () { atualizar(tr); }); });
      atualizar(tr);
    });
  })();
</script>
