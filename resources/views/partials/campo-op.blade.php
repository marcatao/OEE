{{--
  Campo de OP com busca (lista compacta; busca no servidor pelo número da OP ou pelo código/descrição do produto).
  Envia o NÚMERO da OP no campo "ordem_producao" (vazio = sem OP).
  Parâmetros: $op (OrdemProducao atual ou null); opcionais $nome (padrão "ordem_producao"), $rotulo e $ajuda (texto abaixo).
--}}
<label>{{ $rotulo ?? 'Ordem de produção' }}
  <select name="{{ $nome ?? 'ordem_producao' }}" data-busca="Buscar pela OP ou pelo produto…" data-busca-url="{{ route('ordens-producao.busca') }}" data-compacto>
    <option value="">Sem OP</option>
    @php($numeroAtual = old($nome ?? 'ordem_producao', $op?->numero))
    @if ($numeroAtual)
      <option value="{{ $numeroAtual }}" selected>{{ $op && $op->numero === $numeroAtual ? $op->textoBusca() : $numeroAtual }}</option>
    @endif
  </select>
  @if (! empty($ajuda))<small data-ajuda-op>{{ $ajuda }}</small>@endif
</label>
