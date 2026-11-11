{{-- Escolha de vários mantenedores. Variáveis: $mantenedores, $marcados (ids), $fixo (mantenedor logado: sempre marcado). --}}
<fieldset>
  <legend>Mantenedores{{ isset($area) && $area ? ' da área '.$area : '' }} <small class="suave">(nenhum marcado = definir depois)</small></legend>
  @if ($mantenedores->isEmpty())<p class="suave">Nenhum mantenedor cadastrado nesta área executante.</p>@endif
  <div class="grade-opcoes">
    @foreach ($mantenedores as $mt)
      @php($ehFixo = isset($fixo) && $fixo?->id === $mt->id)
      <label class="opcao">
        <input type="checkbox" name="mantenedores[]" value="{{ $mt->id }}" @checked($ehFixo || in_array($mt->id, $marcados ?? [], true)) @if ($ehFixo) onclick="return false" @endif>
        <span>{{ $mt->nomeExibicao() }}@if ($ehFixo) <small class="suave">(você — fica atribuído)</small>@endif
          @if ($mt->relationLoaded('areas') && $mt->areas->isNotEmpty())<br><small class="suave">{{ $mt->areas->pluck('nome')->join(', ') }}</small>@endif</span>
      </label>
    @endforeach
  </div>
</fieldset>
