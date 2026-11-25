@extends('layouts.portal')

@section('titulo', 'Meus equipamentos')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Tecnologia</p>
  <h1>Meus equipamentos</h1>
  <p class="suave">Equipamentos, linhas e licenças da empresa que estão com você, e os seus termos de responsabilidade. Algum dado errado?
    Fale com a Tecnologia.</p>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Patrimônio</th><th>Tipo</th><th>Descrição</th><th>Nº de série / linha</th><th>Desde</th></tr></thead>
    <tbody>
    @forelse ($itens as $i)
      <tr>
        <td><strong>{{ $i->codigo() }}</strong></td>
        <td>{{ $i->tipo?->nome }}</td>
        <td>{{ $i->descricao }}@if ($i->marcaModelo())<br><small class="suave">{{ $i->marcaModelo() }}</small>@endif</td>
        <td>{{ $i->ehLinha() ? ($i->linha_numero ?: '—') : ($i->numero_serie ?: '—') }}</td>
        <td>@dataHora($i->responsavel_desde)</td>
      </tr>
    @empty
      <tr><td colspan="5" class="suave">Nenhum equipamento está com você.</td></tr>
    @endforelse
    @foreach ($licencas as $a)
      <tr>
        <td><strong>{{ $a->licenca?->codigo() }}</strong></td>
        <td>Licença de software</td>
        <td>{{ $a->licenca?->descricao }}</td>
        <td>—</td>
        <td>@dataHora($a->atribuida_em)</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>

  <h2>Termos de responsabilidade</h2>
  @forelse ($termos as $t)
    <p><a href="{{ route('inventario-ti.termos.show', $t) }}"><strong>{{ $t->numeroFormatado() }}</strong></a> — {{ $t->item?->descricao }}
      <span class="etiqueta ti-termo-{{ $t->status }}">{{ $t->rotuloStatus() }}</span>
      @if ($t->status === 'pendente') <a class="botao pequeno" href="{{ route('inventario-ti.termos.show', $t) }}">Ler e assinar</a>@endif
      <br><small class="suave">@if ($t->assinado_em)Assinado em @dataHora($t->assinado_em)@else Gerado em @dataHora($t->criado_em)@endif</small></p>
  @empty
    <p class="suave">Nenhum termo.</p>
  @endforelse
</section>
@endsection
