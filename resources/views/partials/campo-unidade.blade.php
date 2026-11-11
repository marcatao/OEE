{{-- Campo "Unidade" dos cadastros. Variáveis: $registro (modelo com "unidade"), opcional $nome (padrão "unidade"). --}}
@php($opcoesUnidade = \App\Support\Unidades::opcoes(auth()->user(), $registro->exists ? $registro->unidade : null))
<label>Unidade
  <select name="{{ $nome ?? 'unidade' }}" required>
    @foreach ($opcoesUnidade as $valor => $rotulo)
      <option value="{{ $valor }}" @selected(old($nome ?? 'unidade', $registro->unidade ?? \App\Support\Unidades::padrao(auth()->user())) === $valor)>{{ $rotulo }}</option>
    @endforeach
  </select>
</label>
