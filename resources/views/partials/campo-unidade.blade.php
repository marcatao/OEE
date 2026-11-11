{{-- Campo "Unidade" dos cadastros. Variáveis: $registro (modelo com "unidade"), opcional $nome (padrão "unidade"),
     opcional $form (id do formulário, para campos numa linha de tabela) e $semRotulo (só a lista, com aria-label). --}}
@php($opcoesUnidade = \App\Support\Unidades::opcoes(auth()->user(), $registro->exists ? $registro->unidade : null))
@if (! empty($semRotulo))
  <select name="{{ $nome ?? 'unidade' }}" required aria-label="Unidade" @if (! empty($form)) form="{{ $form }}" @endif>
    @foreach ($opcoesUnidade as $valor => $rotulo)
      <option value="{{ $valor }}" @selected(old($nome ?? 'unidade', $registro->unidade ?? \App\Support\Unidades::padrao(auth()->user())) === $valor)>{{ $rotulo }}</option>
    @endforeach
  </select>
@else
<label>Unidade
  <select name="{{ $nome ?? 'unidade' }}" required @if (! empty($form)) form="{{ $form }}" @endif>
    @foreach ($opcoesUnidade as $valor => $rotulo)
      <option value="{{ $valor }}" @selected(old($nome ?? 'unidade', $registro->unidade ?? \App\Support\Unidades::padrao(auth()->user())) === $valor)>{{ $rotulo }}</option>
    @endforeach
  </select>
</label>
@endif
