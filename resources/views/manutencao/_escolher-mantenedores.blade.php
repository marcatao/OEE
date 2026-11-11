{{-- Escolha de vários mantenedores com busca. Variáveis: $mantenedores, $marcados (ids), $fixo (mantenedor logado: sempre
     marcado) e $area (nome da área executante). --}}
@include('partials.escolha-multipla', [
  'nome' => 'mantenedores[]',
  'titulo' => 'Mantenedores'.(! empty($area) ? ' da área '.$area : ''),
  'vazio' => 'Definir depois',
  'busca' => 'Buscar mantenedor por nome ou área…',
  'semItens' => 'Nenhum mantenedor cadastrado nesta área executante.',
  'itens' => $mantenedores->map(fn ($mt) => [
    'valor' => $mt->id,
    'nome' => $mt->nomeExibicao(),
    'detalhe' => $mt->areas->isNotEmpty() ? 'Áreas: '.$mt->areas->pluck('nome')->join(', ') : 'Sem áreas cadastradas',
    'marcado' => in_array($mt->id, $marcados ?? [], true),
    'fixo' => isset($fixo) && $fixo?->id === $mt->id,
    'usuario' => $mt->usuario,
  ])->all(),
])
