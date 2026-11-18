{{-- Histórico da SS, do mais recente para o mais antigo. Variável: $historico (a OS mostra o histórico junto das
     interações, em balões: manutencao/ordens/_conversa). --}}
<div class="rolagem">
<table class="tabela">
  <thead><tr><th>Quando</th><th>Quem</th><th>O que</th><th>Situação</th><th>Observação</th></tr></thead>
  <tbody>
  @forelse ($historico as $h)
    <tr @class(['historico-marco' => $h->ehMarco()])>
      <td>@dataHora($h->criado_em)</td>
      <td>{{ $h->usuario?->nome ?? 'Sistema' }}</td>
      <td>{{ $h->rotuloAcao() }}</td>
      <td>{{ $h->mudancaStatus() }}</td>
      <td class="os-texto">{{ $h->observacao }}</td>
    </tr>
  @empty
    <tr><td colspan="5" class="suave">Sem registros.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
