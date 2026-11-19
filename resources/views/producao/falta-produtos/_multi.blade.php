{{-- Seleção múltipla com opções fixas (valor => rótulo); nenhuma marcada = todas. Parâmetros: campo, rotulo, opcoes,
     marcados, cores (opcional: valor => classe da bolinha). --}}
<details class="multi" data-multi="{{ $campo }}">
  <summary><span class="multi-rotulo">{{ $rotulo }}</span> <strong data-resumo>Todas</strong></summary>
  <div class="multi-painel">
    <button type="button" class="botao secundario pequeno" data-todas>Todas</button>
    @foreach ($opcoes as $valor => $texto)
      <label class="multi-opcao">
        <input type="checkbox" name="{{ $campo }}[]" value="{{ $valor }}" @checked(in_array($valor, $marcados, true))>
        <span>@isset($cores[$valor])<i class="falta-bola {{ $cores[$valor] }}" aria-hidden="true"></i> @endisset{{ $texto }}</span>
      </label>
    @endforeach
  </div>
</details>
