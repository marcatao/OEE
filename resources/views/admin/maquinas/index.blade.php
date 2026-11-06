@extends('layouts.portal')

@section('titulo', 'Máquinas')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Administração</p>
  <div class="titulo-com-acao">
    <h1>Máquinas</h1>
    @if ($temLinhas)
      <a class="botao" href="{{ route('admin.maquinas.create') }}">@include('layouts.partials.icone', ['nome' => 'mais']) Nova máquina</a>
    @endif
  </div>
  <p class="suave">Cadastro das máquinas acompanhadas pelo OEE. O ciclo ideal (segundos por peça) é a base da performance.</p>

  @unless ($temLinhas)
    <p class="aviso alerta">Antes de cadastrar máquinas, cadastre uma planta e uma linha em
      <a href="{{ route('admin.estrutura') }}">Plantas e linhas</a>.</p>
  @endunless

  <form method="get" class="filtros">
    <label>Buscar <input name="busca" value="{{ $busca }}" placeholder="Nome ou código"></label>
    <button class="botao secundario" type="submit">Buscar</button>
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Código</th><th>Nome</th><th>Linha · Planta</th><th>Centro de custo</th><th class="num">Ciclo ideal (s)</th><th class="num">Micro / macro (s)</th><th>Canais IoT</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    @forelse ($maquinas as $maquina)
      <tr>
        <td><code>{{ $maquina->codigo }}</code></td>
        <td>{{ $maquina->nome }}</td>
        <td>{{ $maquina->localizacao() ?: '-' }}</td>
        <td>{{ $maquina->centro_custo ? $maquina->centro_custo.($maquina->centroCusto ? ' — '.$maquina->centroCusto->descricao() : '') : '-' }}</td>
        <td class="num">{{ $maquina->ciclo_ideal_s !== null ? number_format($maquina->ciclo_ideal_s, 1, ',', '.') : '-' }}</td>
        <td class="num">{{ $maquina->micro_parada_s ?? '-' }} / {{ $maquina->macro_parada_s ?? '-' }}</td>
        <td class="quebra">@forelse ($maquina->canaisIot() as $c)<small style="display:block">{{ $c['id'] }} · {{ $c['canal'] }} · {{ \App\Models\Maquina::TIPOS_CANAL[$c['tipo']] ?? '-' }}</small>@empty - @endforelse</td>
        <td><span class="etiqueta {{ $maquina->ativo ? 'situacao-online' : 'situacao-inativo' }}">{{ $maquina->ativo ? 'Ativa' : 'Inativa' }}</span></td>
        <td><a class="botao pequeno secundario" href="{{ route('admin.maquinas.edit', $maquina) }}">Editar</a></td>
      </tr>
    @empty
      <tr><td colspan="9" class="suave">Nenhuma máquina encontrada.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $maquinas->links('partials.paginacao') }}
</section>
@endsection
