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
  <p class="suave">Mão de obra da manutenção. A <strong>jornada</strong> separa hora normal × extra e dá as horas disponíveis. Ligue o
    mantenedor ao login do portal para ele apontar as próprias horas.</p>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Unidade</th><th>Nome</th><th>Matrícula</th><th>Login</th><th>Área</th><th>Jornada</th><th class="num">Horas/dia</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    @forelse ($mantenedores as $m)
      <tr>
        <td><span class="etiqueta unidade-{{ $m->unidade }}">{{ $m->rotuloUnidade() }}</span></td>
        <td><strong>{{ $m->nome }}</strong></td>
        <td>{{ $m->matricula }}</td>
        <td>{{ $m->usuario?->email ?? '—' }}</td>
        <td>{{ $m->areaExecutante?->nome }}</td>
        <td>{{ $m->jornada_inicio }}–{{ $m->jornada_fim }}<br><small class="suave">{{ collect(str_split((string) $m->dias_semana))->map(fn ($d) => \App\Models\Manutencao\Mantenedor::DIAS_SEMANA[$d] ?? $d)->join(', ') }}</small></td>
        <td class="num">{{ HorasManutencao::hhmm(HorasManutencao::de($m)->minutosPorDia()) }}</td>
        <td><span class="etiqueta {{ $m->ativo ? 'situacao-online' : 'situacao-inativo' }}">{{ $m->ativo ? 'Ativo' : 'Inativo' }}</span></td>
        <td><a class="botao pequeno secundario" href="{{ route('os.cadastros.mantenedores.edit', $m) }}">Editar</a></td>
      </tr>
    @empty
      <tr><td colspan="9" class="suave">Nenhum mantenedor cadastrado.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>
@endsection
