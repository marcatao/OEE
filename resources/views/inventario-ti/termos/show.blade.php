@extends('layouts.portal')

@section('titulo', $t->numeroFormatado())

@use('App\Services\InventarioTi\TermosResponsabilidade')
@use('App\Support\ContratoComodato')

@section('conteudo')
{{-- Termo de responsabilidade no modelo do CONTRATO PARTICULAR DE COMODATO da empresa (texto em App\Support\ContratoComodato). --}}
@php
  $dados = $t->conteudo ?? [];
  $presencial = (int) auth()->id() !== (int) $t->usuario_id;
  $quali = $dados['comodatario'] ?? [];
  $nome = mb_strtoupper($t->nome_assinante ?: ($dados['responsavel'] ?? (string) $t->usuario?->nome));
  $dia = \Carbon\Carbon::parse($t->assinado_em ?? $t->criado_em)->setTimezone(config('oee.fuso_exibicao'))->locale('pt_BR');
  $testemunhas = $t->testemunhas->keyBy('ordem');
  $lacuna = fn ($campo) => filled($quali[$campo] ?? null) ? e($quali[$campo]) : '<span class="termo-lacuna">'.e(['cargo' => 'cargo', 'nacionalidade' => 'nacionalidade', 'rg' => 'RG', 'cpf' => 'CPF', 'endereco' => 'endereço'][$campo]).'</span>';
@endphp
<section class="cartao termo">
  <div class="titulo-com-acao nao-imprimir">
    <p class="trilha">Tecnologia › @if (auth()->user()->pode('inventario_ti') && $t->item)<a href="{{ route('inventario-ti.show', $t->item_id) }}">{{ $t->item->codigo() }}</a>@else<a href="{{ route('inventario-ti.meus') }}">Meus equipamentos</a>@endif
      · {{ $t->numeroFormatado() }} <span class="etiqueta ti-termo-{{ $t->status }}">{{ $t->rotuloStatus() }}</span></p>
    <button class="botao secundario" type="button" onclick="window.print()">@include('layouts.partials.icone', ['nome' => 'imprimir']) Imprimir</button>
  </div>
  @include('manutencao._erros')

  <h1>{{ mb_strtoupper(ContratoComodato::TITULO) }}</h1>

  <div class="termo-texto">
    <p>Pelo presente instrumento particular de COMODATO, de um lado <strong>{{ ContratoComodato::COMODANTE['razao_social'] }}</strong>, {{ ContratoComodato::COMODANTE['qualificacao'] }},
      de ora em diante denominada simplesmente <strong>COMODANTE</strong> e, de outro lado Sr.(a) <strong>{{ $nome }}</strong>, {!! $lacuna('cargo') !!}, {!! $lacuna('nacionalidade') !!},
      portador(a) da Cédula de Identidade nº {!! $lacuna('rg') !!} CPF/MF nº {!! $lacuna('cpf') !!}, residente na {!! $lacuna('endereco') !!},
      de ora em diante denominado(a) simplesmente de <strong>COMODATÁRIO</strong>, tem justo e contratado o seguinte:</p>
    <p>O COMODANTE dá em comodato ao COMODATÁRIO:</p>
    <p class="termo-objeto">{{ TermosResponsabilidade::descricaoComodato($dados) }}</p>
    @foreach (TermosResponsabilidade::clausulas($dados) as $clausula)<p>{{ $clausula }}</p>@endforeach
    <p>{{ ContratoComodato::FECHAMENTO }}</p>
    <p class="termo-data">{{ ContratoComodato::CIDADE }}, {{ $dia->translatedFormat('j \d\e F \d\e Y') }}.</p>
  </div>

  <div class="termo-assinaturas">
    <div>
      <small>COMODANTE</small>
      <div class="termo-espaco"></div>
      <div class="termo-linha"></div>
      <strong>{{ ContratoComodato::COMODANTE['nome_curto'] }}</strong>
      <small class="suave">Emitido por {{ $t->geradoPor?->nome ?? '—' }} em @dataHora($t->criado_em)</small>
    </div>
    <div>
      <small>COMODATÁRIO</small>
      <div class="termo-espaco">@if ($t->status === 'assinado')<img src="data:image/png;base64,{{ $t->imagem_png }}" alt="Assinatura de {{ $t->nome_assinante }}">@endif</div>
      <div class="termo-linha"></div>
      <strong>{{ $nome }}</strong>
      @if ($t->status === 'assinado')
        <small class="suave">Assinado eletronicamente em @dataHora($t->assinado_em){{ $t->presencial ? ' na presença de '.$t->registradoPor?->nome : ' pelo portal' }}{{ $t->assinado_ip ? ' · IP '.$t->assinado_ip : '' }}</small>
      @endif
    </div>
  </div>
  <p class="termo-testemunhas-titulo"><small>TESTEMUNHAS:</small></p>
  <div class="termo-assinaturas">
    @foreach ([1, 2] as $ordem)
      @php($te = $testemunhas->get($ordem))
      <div>
        <div class="termo-espaco">@if ($te?->assinou())<img src="data:image/png;base64,{{ $te->imagem_png }}" alt="Assinatura de {{ $te->nome }}">@endif</div>
        <div class="termo-linha"></div>
        <span>NOME: {{ mb_strtoupper($te?->nome ?: (string) $te?->usuario?->nome) }}</span>
        <span>RG nº {{ $te?->rg }}</span>
        @if ($te?->assinou())
          <small class="suave">Assinado eletronicamente em @dataHora($te->assinado_em){{ $te->presencial ? ' na presença de '.$te->registradoPor?->nome : ' pelo portal' }}</small>
        @elseif ($te)
          <small class="suave nao-imprimir">Aguardando a assinatura da testemunha</small>
        @else
          <small class="suave nao-imprimir">Testemunha {{ $ordem }} não definida</small>
        @endif
      </div>
    @endforeach
  </div>

  @if ($podeDefinirTestemunhas && $testemunhas->filter->assinou()->count() < 2)
    <form method="post" action="{{ route('inventario-ti.termos.testemunhas', $t) }}" class="formulario-vertical nao-imprimir termo-assinar">
      @csrf
      <h2>Testemunhas</h2>
      <p class="suave">Escolha as duas testemunhas (usuários do portal). Cada uma vê o aviso no topo do portal e assina pelo celular ou
        computador; você também pode colher a assinatura na hora, aqui. Os próximos termos já nascem com as mesmas testemunhas.</p>
      <div class="campos">
        @foreach ([1, 2] as $ordem)
          @php($te = $testemunhas->get($ordem))
          <label class="largo">Testemunha {{ $ordem }}
            @if ($te?->assinou())
              <input value="{{ $te->nome }} (já assinou)" disabled><input type="hidden" name="testemunha_{{ $ordem }}" value="{{ $te->usuario_id }}">
            @else
              <select name="testemunha_{{ $ordem }}" data-busca="Buscar usuário…">
                <option value="">Nenhuma</option>
                @foreach ($usuarios as $usu)<option value="{{ $usu->id }}" @selected((int) old('testemunha_'.$ordem, $te?->usuario_id) === $usu->id)>{{ $usu->nome }} — {{ $usu->email }}</option>@endforeach
              </select>
            @endif
          </label>
        @endforeach
      </div>
      <div class="acoes"><button class="botao secundario" type="submit">Salvar testemunhas</button></div>
    </form>
  @endif

  @if ($t->status === 'cancelado')
    <p class="erro">Termo cancelado em @dataHora($t->cancelado_em){{ $t->motivo_cancelamento ? ' — '.$t->motivo_cancelamento : '' }}.</p>
  @else
    @php($euComodatario = (int) $t->usuario_id === (int) auth()->id())
    {{-- 1) O que é do usuário logado assinar vem primeiro e aberto: como comodatário ou como testemunha. --}}
    @if ($t->status === 'pendente' && $euComodatario && $podeAssinar)
      @include('inventario-ti.termos._form-comodatario', ['presencial' => false])
    @endif
    @foreach ($testemunhas as $te)
      @if (! $te->assinou() && (int) $te->usuario_id === (int) auth()->id())
        @include('inventario-ti.termos._form-testemunha', ['ehEla' => true])
      @endif
    @endforeach

    {{-- 2) Assinatura presencial (o administrador colhe a de OUTRA pessoa no aparelho dele): recolhida, com confirmação. --}}
    @if ($t->status === 'pendente' && ! $euComodatario && $podeAssinar)
      <details class="termo-presencial nao-imprimir">
        <summary>Colher na hora a assinatura de {{ $t->usuario?->nome }} (comodatário)</summary>
        @include('inventario-ti.termos._form-comodatario', ['presencial' => true])
      </details>
    @elseif ($t->status === 'pendente' && ! $euComodatario)
      <p class="suave nao-imprimir">Aguardando a assinatura de {{ $t->usuario?->nome }} (pelo portal, em "Meus equipamentos").</p>
    @endif
    @if ($podeDefinirTestemunhas)
      @foreach ($testemunhas as $te)
        @if (! $te->assinou() && (int) $te->usuario_id !== (int) auth()->id())
          <details class="termo-presencial nao-imprimir">
            <summary>Colher na hora a assinatura de {{ $te->usuario?->nome }} (testemunha {{ $te->ordem }})</summary>
            @include('inventario-ti.termos._form-testemunha', ['ehEla' => false])
          </details>
        @endif
      @endforeach
      {{-- 3) Anular (assinatura errada, dados errados): o termo fica cancelado com o motivo e nasce um novo. --}}
      <details class="termo-presencial nao-imprimir">
        <summary>Anular este termo</summary>
        <form method="post" action="{{ route('inventario-ti.termos.anular', $t) }}" class="formulario-vertical termo-assinar"
          onsubmit="return confirm('Anular o termo {{ $t->numeroFormatado() }}? Ele fica cancelado (com as assinaturas, para consulta) e um termo novo é gerado.')">
          @csrf
          <p class="suave">Use quando alguém assinou no lugar errado ou os dados estão errados. Nada é apagado: este termo fica cancelado com o motivo
            e, se o item continua com {{ $t->usuario?->nome }}, um termo novo (com as mesmas testemunhas) é gerado para assinar de novo.</p>
          <label class="largo">Motivo <input name="motivo" maxlength="200" required placeholder="Ex.: assinatura da testemunha registrada no lugar do comodatário"></label>
          <div class="acoes"><button class="botao secundario" type="submit">Anular e gerar termo novo</button></div>
        </form>
      </details>
    @endif
  @endif
</section>
@endsection
