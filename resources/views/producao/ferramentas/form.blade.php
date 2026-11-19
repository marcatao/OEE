@extends('layouts.portal')

@section('titulo', 'Ferramenta '.$ferramenta->codigo())

@use('App\Support\Formato')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Produção › <a href="{{ route('ferramentas.index') }}">Ferramentas</a></p>
  <h1>Ferramenta <code>{{ $ferramenta->h4_codigo }}</code></h1>
  <p class="suave">Código, descrição, quantidade, vida útil e cavidades vêm da SH4 do Protheus
    @if ($ferramenta->sincronizado_em)(sincronizada em @dataHora($ferramenta->sincronizado_em))@endif e não são alterados aqui.</p>

  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif
  @if ($ferramenta->cavidadesAcima())
    <p class="aviso alerta">As cavidades ativas ({{ $ferramenta->h4_qtdcavativas }}) estão acima das cavidades da ferramenta no Protheus
      ({{ $ferramenta->h4_qtdcav }}). Ajuste o valor.</p>
  @endif

  <form method="post" action="{{ route('ferramentas.update', $ferramenta) }}" class="formulario-vertical">
    @csrf @method('put')
    <div class="campos">
      <label>Descrição <input value="{{ $ferramenta->h4_descri }}" disabled></label>
      <label>Cavidades (Protheus) <input value="{{ $ferramenta->h4_qtdcav }}" disabled></label>
      <label>Quantidade <input value="{{ Formato::quantidade($ferramenta->h4_quant) }}" disabled></label>
      <label>Vida útil <input value="{{ $ferramenta->h4_vidauti > 0 ? Formato::quantidade($ferramenta->h4_vidauti) : '-' }}" disabled></label>
    </div>
    <div class="campos">
      <label>Cavidades ativas
        <input type="number" name="h4_qtdcavativas" required min="0" max="{{ $ferramenta->h4_qtdcav }}" step="1"
               value="{{ old('h4_qtdcavativas', min($ferramenta->h4_qtdcavativas, $ferramenta->h4_qtdcav)) }}">
        <small>De 0 a {{ $ferramenta->h4_qtdcav }}. A sincronização com o Protheus não muda este valor.</small>
      </label>
      @include('partials.campo-unidade', ['registro' => $ferramenta])
    </div>
    <fieldset class="opcoes-linha">
      <legend>Disponibilidade</legend>
      <label class="opcao"><input type="radio" name="disponivel" value="1" @checked((int) old('disponivel', (int) $ferramenta->disponivel) === 1)> <span>Disponível</span></label>
      <label class="opcao"><input type="radio" name="disponivel" value="0" @checked((int) old('disponivel', (int) $ferramenta->disponivel) === 0)> <span>Indisponível</span></label>
      <small class="suave">Campo do portal: a sincronização com o Protheus não muda.</small>
    </fieldset>
    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('ferramentas.index') }}">Cancelar</a>
    </div>
  </form>

  <h2>Operações que usam esta ferramenta</h2>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Produto</th><th>Roteiro</th><th>Operação</th><th>Centro de trabalho</th><th class="num">Tempo padrão</th></tr></thead>
    <tbody>
    @forelse ($roteiros as $r)
      <tr><td><code>{{ $r->g2_produto }}</code></td><td>{{ $r->g2_codigo ?: '-' }}</td><td><code>{{ $r->g2_operac }}</code> {{ $r->g2_descri }}</td>
        <td>{{ $r->g2_ctrab ?: '-' }}</td><td class="num">{{ Formato::quantidade($r->g2_tempad) }}</td></tr>
    @empty
      <tr><td colspan="5" class="suave">Nenhuma operação do roteiro (SG2) usa esta ferramenta.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>
@endsection
