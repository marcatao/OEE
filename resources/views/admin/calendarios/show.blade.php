@extends('layouts.portal')

@section('titulo', $calendario->titulo())

@use('App\Models\Calendario')
@use('Carbon\CarbonImmutable')

@section('conteudo')
@php($meses = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'])
@php($hoje = now(config('oee.fuso_exibicao'))->format('Y-m-d'))
<section class="cartao">
  <p class="trilha">Administração › <a href="{{ route('admin.calendarios.index') }}">Calendários</a></p>
  <div class="cartao-titulo">
    <h1>{{ $calendario->nome }} <span class="suave">{{ $calendario->ano }}</span>
      <span class="etiqueta unidade-{{ $calendario->unidade }}">{{ $calendario->rotuloUnidade() }}</span>
      @unless ($calendario->ativo)<span class="etiqueta situacao-inativo">Inativo</span>@endunless</h1>
    <div class="acoes">
      @foreach ($outrosAnos as $o)<a class="botao secundario pequeno" href="{{ route('admin.calendarios.show', $o->id) }}">{{ $o->ano }}</a>@endforeach
    </div>
  </div>
  <p class="suave">Clique num dia para mudar: feriado, ponte, folga, compensação ou dia útil extra (ou voltar ao padrão: segunda a sexta úteis).
    Usado por {{ $qtdMaquinas }} {{ $qtdMaquinas == 1 ? 'máquina' : 'máquinas' }} e {{ $qtdMantenedores }} {{ $qtdMantenedores == 1 ? 'mantenedor' : 'mantenedores' }}.</p>

  <div class="cal-resumo">
    <span><strong data-total-uteis>{{ $resumo['total'] }}</strong> dias úteis no ano</span>
    <span class="cal-legenda">
      <i class="cal-amostra cal-normal"></i>Útil
      <i class="cal-amostra cal-fds"></i>Fim de semana
      @foreach (Calendario::TIPOS as $tipo => $t)<i class="cal-amostra cal-{{ $tipo }}"></i>{{ $t['rotulo'] }} @endforeach
    </span>
  </div>

  <div class="cal-ano" data-url="{{ route('admin.calendarios.dia', $calendario) }}">
    @foreach ($meses as $i => $nomeMes)
      @php($primeiro = CarbonImmutable::create($calendario->ano, $i + 1, 1))
      <div class="cal-mes">
        <h3>{{ $nomeMes }} <small class="suave"><span data-uteis-mes="{{ $i + 1 }}">{{ $resumo['meses'][$i + 1] }}</span> úteis</small></h3>
        <div class="cal-grade">
          @foreach (['S', 'T', 'Q', 'Q', 'S', 'S', 'D'] as $sigla)<span class="cal-semana">{{ $sigla }}</span>@endforeach
          @for ($v = 1; $v < $primeiro->dayOfWeekIso; $v++)<span></span>@endfor
          @for ($d = $primeiro; $d->month === $i + 1; $d = $d->addDay())
            @php($data = $d->format('Y-m-d'))
            @php($ex = $excecoes[$data] ?? null)
            <button type="button" data-dia="{{ $data }}" data-tipo="{{ $ex?->tipo }}" data-descricao="{{ $ex?->descricao }}"
                    data-fds="{{ $d->dayOfWeekIso >= 6 ? 1 : 0 }}"
                    @class(['cal-dia', 'cal-'.$ex?->tipo => $ex, 'cal-fds' => ! $ex && $d->dayOfWeekIso >= 6, 'cal-normal' => ! $ex && $d->dayOfWeekIso < 6, 'cal-hoje' => $data === $hoje])
                    title="{{ $d->format('d/m/Y') }}{{ $ex ? ' · '.Calendario::TIPOS[$ex->tipo]['rotulo'].($ex->descricao ? ': '.$ex->descricao : '') : '' }}">{{ $d->day }}</button>
          @endfor
        </div>
      </div>
    @endforeach

    <form class="cal-editor" hidden>
      <strong data-editor-titulo></strong>
      <label>Tipo do dia
        <select name="tipo">
          <option value="">Padrão (segunda a sexta úteis)</option>
          @foreach (Calendario::TIPOS as $tipo => $t)<option value="{{ $tipo }}">{{ $t['rotulo'] }}</option>@endforeach
        </select>
      </label>
      <label>Descrição <input name="descricao" maxlength="120" placeholder="Ex.: Ponte de Corpus Christi"></label>
      <div class="acoes"><button class="botao pequeno" type="submit">Salvar</button><button class="botao secundario pequeno" type="button" data-fechar>Cancelar</button></div>
      <small class="erro" data-editor-erro hidden></small>
    </form>
  </div>
</section>

<section class="cartao">
  <h2>Ajustes do ano</h2>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Dia</th><th>Tipo</th><th>Descrição</th></tr></thead>
    <tbody>
    @forelse ($excecoes as $data => $ex)
      <tr><td>{{ $ex->data->format('d/m/Y') }} <small class="suave">{{ ['', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'][$ex->data->dayOfWeekIso] }}</small></td>
        <td><span class="etiqueta cal-{{ $ex->tipo }}">{{ Calendario::TIPOS[$ex->tipo]['rotulo'] }}</span></td><td>{{ $ex->descricao }}</td></tr>
    @empty
      <tr><td colspan="3" class="suave">Nenhum ajuste: segunda a sexta úteis.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>

<section class="cartao">
  <details>
    <summary><strong>Dados do calendário e próximo ano</strong></summary>
    @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('admin.calendarios.update', $calendario) }}" class="formulario-vertical">
      @csrf @method('put')
      <div class="campos">
        <label>Nome <input name="nome" required maxlength="80" value="{{ old('nome', $calendario->nome) }}"></label>
        @include('partials.campo-unidade', ['registro' => $calendario])
        <label class="duplo">Descrição <input name="descricao" maxlength="300" value="{{ old('descricao', $calendario->descricao) }}"></label>
      </div>
      <label class="opcao"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $calendario->ativo))> <span>Ativo (aparece para escolher em máquinas e mantenedores)</span></label>
      <div class="acoes"><button class="botao secundario" type="submit">Salvar dados</button></div>
    </form>
    <form method="post" action="{{ route('admin.calendarios.proximo-ano', $calendario) }}" class="formulario-vertical">
      @csrf
      <p class="suave">Calendários são anuais. Crie o de {{ $calendario->ano + 1 }} com o mesmo nome e os feriados do novo ano.</p>
      <label class="opcao"><input type="checkbox" name="mover" value="1"> <span>Passar as máquinas e os mantenedores deste calendário para o de {{ $calendario->ano + 1 }}</span></label>
      <div class="acoes"><button class="botao secundario" type="submit">Criar o calendário de {{ $calendario->ano + 1 }}</button></div>
    </form>
  </details>
</section>

<script>
  // Visão do ano: clique no dia abre o editor; grava sem recarregar e atualiza a cor e as contagens de dias úteis.
  (function () {
    var ano = document.querySelector('.cal-ano');
    var editor = ano.querySelector('.cal-editor');
    var token = @json(csrf_token());
    var tipos = @json(collect(Calendario::TIPOS)->map(fn ($t) => $t['rotulo']));
    var atual = null;
    function fechar() { editor.hidden = true; if (atual) atual.classList.remove('cal-editando'); atual = null; }
    ano.addEventListener('click', function (e) {
      var dia = e.target.closest('.cal-dia');
      if (!dia) return;
      fechar();
      atual = dia;
      dia.classList.add('cal-editando');
      var partes = dia.dataset.dia.split('-');
      editor.querySelector('[data-editor-titulo]').textContent = partes[2] + '/' + partes[1] + '/' + partes[0];
      editor.tipo.value = dia.dataset.tipo || '';
      editor.descricao.value = dia.dataset.descricao || '';
      editor.querySelector('[data-editor-erro]').hidden = true;
      editor.hidden = false;
      var r = dia.getBoundingClientRect(), base = ano.getBoundingClientRect();
      editor.style.top = (r.bottom - base.top + 6) + 'px';
      editor.style.left = Math.max(0, Math.min(r.left - base.left - 80, base.width - editor.offsetWidth)) + 'px';
      editor.tipo.focus();
    });
    editor.querySelector('[data-fechar]').addEventListener('click', fechar);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });
    editor.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!atual) return;
      var dia = atual;
      fetch(ano.dataset.url, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify({ data: dia.dataset.dia, tipo: editor.tipo.value, descricao: editor.descricao.value }) })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (!res.ok) { var erro = editor.querySelector('[data-editor-erro]'); erro.textContent = res.d.message || 'Não foi possível salvar.'; erro.hidden = false; return; }
          var tipo = res.d.tipo || '';
          dia.className = 'cal-dia ' + (tipo ? 'cal-' + tipo : (dia.dataset.fds === '1' ? 'cal-fds' : 'cal-normal'));
          dia.dataset.tipo = tipo;
          dia.dataset.descricao = res.d.descricao || '';
          var p = dia.dataset.dia.split('-');
          dia.title = p[2] + '/' + p[1] + '/' + p[0] + (tipo ? ' · ' + tipos[tipo] + (res.d.descricao ? ': ' + res.d.descricao : '') : '');
          document.querySelector('[data-total-uteis]').textContent = res.d.resumo.total;
          Object.keys(res.d.resumo.meses).forEach(function (m) { document.querySelector('[data-uteis-mes="' + m + '"]').textContent = res.d.resumo.meses[m]; });
          fechar();
        });
    });
  })();
</script>
@endsection
