{{-- Histórico da SS/OS, do mais recente para o mais antigo. Variável: $historico --}}
@php($nomes = [
  'aberta' => 'SS aberta', 'alterada' => 'Dados alterados', 'aprovada' => 'SS aprovada — OS emitida', 'rejeitada' => 'SS rejeitada',
  'cancelada' => 'SS cancelada', 'gerada_preventiva' => 'OS preventiva gerada', 'distribuida' => 'Mantenedores definidos',
  'iniciar' => 'Início do atendimento', 'aguardar_material' => 'Aguardando material', 'retomar' => 'Atendimento retomado',
  'concluir' => 'Serviço concluído', 'reabrir' => 'OS reaberta', 'cancelar' => 'OS cancelada',
  'avaliada' => 'Avaliada pelo solicitante', 'avaliada_ruim' => 'Avaliada como Ruim — OS reaberta',
  'aceite' => 'Aceite do solicitante', 'servico_recusado' => 'Serviço recusado pelo solicitante',
  'maquina_parada' => 'Máquina parada / liberada', 'horas_lancadas' => 'Horas lançadas', 'horas_excluidas' => 'Horas excluídas', 'material_requisitado' => 'Material requisitado',
])
<div class="rolagem">
<table class="tabela">
  <thead><tr><th>Quando</th><th>Quem</th><th>O que</th><th>Situação</th><th>Observação</th></tr></thead>
  <tbody>
  @forelse ($historico as $h)
    <tr @class(['historico-marco' => in_array($h->acao, ['aberta', 'aprovada', 'iniciar', 'concluir', 'avaliada', 'avaliada_ruim', 'gerada_preventiva'], true)])>
      <td>@dataHora($h->criado_em)</td>
      <td>{{ $h->usuario?->nome ?? 'Sistema' }}</td>
      <td>{{ $nomes[$h->acao] ?? (str_starts_with($h->acao, 'material_') ? 'Material: '.str_replace('_', ' ', substr($h->acao, 9)) : ucfirst(str_replace('_', ' ', $h->acao))) }}</td>
      <td>@if ($h->status_novo && $h->status_novo !== $h->status_anterior){{ $h->status_anterior ? str_replace('_', ' ', $h->status_anterior).' → ' : '' }}{{ str_replace('_', ' ', $h->status_novo) }}@endif</td>
      <td class="os-texto">{{ $h->observacao }}</td>
    </tr>
  @empty
    <tr><td colspan="5" class="suave">Sem registros.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
