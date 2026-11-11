{{-- Histórico da SS/OS. Variável: $historico --}}
<div class="rolagem">
<table class="tabela">
  <thead><tr><th>Quando</th><th>Quem</th><th>O que</th><th>Situação</th><th>Observação</th></tr></thead>
  <tbody>
  @forelse ($historico as $h)
    <tr>
      <td>@dataHora($h->criado_em)</td>
      <td>{{ $h->usuario?->nome ?? 'Sistema' }}</td>
      <td>{{ str_replace('_', ' ', ucfirst($h->acao)) }}</td>
      <td>@if ($h->status_novo && $h->status_novo !== $h->status_anterior){{ $h->status_anterior ? str_replace('_', ' ', $h->status_anterior).' → ' : '' }}{{ str_replace('_', ' ', $h->status_novo) }}@endif</td>
      <td class="os-texto">{{ $h->observacao }}</td>
    </tr>
  @empty
    <tr><td colspan="5" class="suave">Sem registros.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
