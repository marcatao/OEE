@extends('layouts.portal')

@section('titulo', 'Mantenedores')

@use('App\Services\Manutencao\HorasManutencao')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Ordens de serviço › Cadastros</p>
  <div class="titulo-com-acao">
    <h1>Mantenedores</h1>
    <a class="botao" href="{{ route('os.cadastros.mantenedores.create') }}">@include('layouts.partials.icone', ['nome' => 'mais']) Novo mantenedor</a>
  </div>
  <p class="suave">Usuários do portal que executam as OS. A <strong>jornada</strong> separa hora normal × extra e dá as horas disponíveis.</p>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Mantenedor</th><th>Unidade</th><th>Áreas executantes</th><th>Matrícula</th><th>Jornada</th><th class="num">Horas/dia</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    @forelse ($mantenedores as $m)
      <tr>
        <td>
          <span style="display:inline-flex;gap:.6rem;align-items:center">
            @if ($m->usuario)@include('partials.avatar', ['usuario' => $m->usuario])@endif
            <span><strong>{{ $m->nomeExibicao() }}</strong><br><small class="suave">{{ $m->usuario?->email ?? 'sem login' }}{{ $m->usuario?->departamento ? ' · '.$m->usuario->departamento : '' }}</small></span>
          </span>
        </td>
        <td><span class="etiqueta unidade-{{ $m->unidade }}">{{ $m->rotuloUnidade() }}</span></td>
        <td>{{ $m->areas->pluck('nome')->join(', ') ?: '—' }}</td>
        <td>{{ $m->matricula }}</td>
        <td>{{ $m->jornada_inicio }}–{{ $m->jornada_fim }}<br><small class="suave">{{ collect(str_split((string) $m->dias_semana))->map(fn ($d) => \App\Models\Manutencao\Mantenedor::DIAS_SEMANA[$d] ?? $d)->join(', ') }}</small></td>
        <td class="num">{{ HorasManutencao::hhmm(HorasManutencao::de($m)->minutosPorDia()) }}</td>
        <td><span class="etiqueta {{ $m->ativo ? 'situacao-online' : 'situacao-inativo' }}">{{ $m->ativo ? 'Ativo' : 'Inativo' }}</span></td>
        <td><a class="botao pequeno secundario" href="{{ route('os.cadastros.mantenedores.edit', $m) }}">Editar</a></td>
      </tr>
    @empty
      <tr><td colspan="8" class="suave">Nenhum mantenedor cadastrado.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>
@endsection
