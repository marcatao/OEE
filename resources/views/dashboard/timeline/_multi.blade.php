{{-- Seleção múltipla (nenhuma marcada = todas). Parâmetros: campo, rotulo, itens, marcados, texto (fn), dados (fn). --}}
<details class="multi" data-multi="{{ $campo }}">
  <summary><span class="multi-rotulo">{{ $rotulo }}</span> <strong data-resumo>Todas</strong></summary>
  <div class="multi-painel">
    <button type="button" class="botao secundario pequeno" data-todas>Todas</button>
    @forelse ($itens as $item)
      <label class="multi-opcao" @foreach ($dados($item) as $chave => $valor) data-{{ $chave }}="{{ $valor }}" @endforeach>
        <input type="checkbox" name="{{ $campo }}[]" value="{{ $item->id }}" @checked(in_array($item->id, $marcados, true))>
        <span>{{ $texto($item) }}</span>
      </label>
    @empty
      <p class="suave">Nenhum cadastro ativo.</p>
    @endforelse
  </div>
</details>
