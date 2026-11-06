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
  <p class="suave">Cadastro das máquinas acompanhadas pelo OEE. O ciclo ideal (segundos por peça) é a base da performance.
    As máquinas do Protheus (tabela SHB) chegam pela sincronização em
    <a href="{{ route('admin.banco') }}">Banco de dados</a>
    com código, nome e centro de custo, <strong>inativas</strong>: complete o cadastro e ative.</p>

  @if ($qtdAguardando)
    <p class="aviso alerta">{{ $qtdAguardando }} {{ $qtdAguardando == 1 ? 'máquina do Protheus aguarda' : 'máquinas do Protheus aguardam' }}
      cadastro. <a href="{{ route('admin.maquinas.index', ['situacao' => 'aguardando']) }}">Ver</a></p>
  @endif

  @unless ($temLinhas)
    <p class="aviso alerta">Antes de cadastrar máquinas, cadastre uma planta e uma linha em
      <a href="{{ route('admin.estrutura') }}">Plantas e linhas</a>.</p>
  @endunless

  <form method="get" class="filtros">
    <label>Buscar <input name="busca" value="{{ $busca }}" placeholder="Nome ou código"></label>
    <label>Situação
      <select name="situacao">
        <option value="">Todas</option>
        <option value="ativas" @selected($situacao === 'ativas')>Ativas</option>
        <option value="aguardando" @selected($situacao === 'aguardando')>Aguardando cadastro (Protheus)</option>
        <option value="excluidas" @selected($situacao === 'excluidas')>Excluídas no Protheus</option>
      </select>
    </label>
    <button class="botao secundario" type="submit">Buscar</button>
  </form>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Código</th><th>Nome</th><th>Situação</th><th>Origem</th><th>Linha · Planta</th><th>Centro de custo</th><th class="num">Ciclo ideal (s)</th><th class="num">Micro / macro (s)</th><th>Canais IoT</th><th></th></tr></thead>
    <tbody>
    @forelse ($maquinas as $maquina)
      <tr>
        <td><a href="{{ route('admin.maquinas.edit', $maquina) }}"><code>{{ $maquina->codigo }}</code></a></td>
        <td><a href="{{ route('admin.maquinas.edit', $maquina) }}">{{ $maquina->nome }}</a></td>
        <td>
          @if ($maquina->excluidaNoProtheus())
            <span class="etiqueta sinc-erro">Excluída no Protheus</span>
          @elseif ($maquina->aguardandoCadastro())
            <span class="etiqueta sinc-executando">Aguardando cadastro</span>
          @else
            <span class="etiqueta {{ $maquina->ativo ? 'situacao-online' : 'situacao-inativo' }}">{{ $maquina->ativo ? 'Ativa' : 'Inativa' }}</span>
          @endif
        </td>
        <td>@if ($maquina->doProtheus())<span class="etiqueta origem-protheus">Protheus</span>@else<span class="etiqueta origem-sistema">OEE</span>@endif</td>
        <td>{{ $maquina->localizacao() ?: '-' }}</td>
        <td>{{ $maquina->centro_custo ? $maquina->centro_custo.($maquina->centroCusto ? ' — '.$maquina->centroCusto->descricao() : '') : '-' }}</td>
        <td class="num">{{ $maquina->ciclo_ideal_s !== null ? number_format($maquina->ciclo_ideal_s, 1, ',', '.') : '-' }}</td>
        <td class="num">{{ $maquina->micro_parada_s ?? '-' }} / {{ $maquina->macro_parada_s ?? '-' }}</td>
        <td class="quebra">@forelse ($maquina->canaisIot() as $c)<small style="display:block">{{ $c['id'] }} · {{ $c['canal'] }} · {{ \App\Models\Maquina::TIPOS_CANAL[$c['tipo']] ?? '-' }}</small>@empty - @endforelse</td>
        <td><a class="botao pequeno {{ $maquina->aguardandoCadastro() ? '' : 'secundario' }}" href="{{ route('admin.maquinas.edit', $maquina) }}">{{ $maquina->aguardandoCadastro() ? 'Completar' : 'Editar' }}</a></td>
      </tr>
    @empty
      <tr><td colspan="10" class="suave">Nenhuma máquina encontrada.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $maquinas->links('partials.paginacao') }}
</section>
@endsection
