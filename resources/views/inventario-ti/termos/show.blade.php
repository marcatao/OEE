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
  @elseif ($t->status === 'pendente' && $podeAssinar)
    <form method="post" action="{{ route('inventario-ti.termos.assinar', $t) }}" class="formulario-vertical nao-imprimir termo-assinar">
      @csrf
      <h2>Assinar o contrato</h2>
      @if ($presencial)
        <p class="aviso">Assinatura presencial: entregue o aparelho para <strong>{{ $t->usuario?->nome }}</strong> conferir os dados, ler e assinar. Fica registrado que foi no seu aparelho.</p>
      @endif
      <p class="suave">Seus dados completam o contrato (como no contrato em papel) e ficam guardados só neste termo.</p>
      <div class="campos">
        @if ($presencial)<label class="largo">Nome de quem assina <input name="nome" maxlength="120" value="{{ old('nome', $t->usuario?->nome) }}"></label>@endif
        <label>Cargo <input name="cargo" maxlength="100" required value="{{ old('cargo', $comodatario['cargo'] ?? '') }}"></label>
        <label>Nacionalidade <input name="nacionalidade" maxlength="40" required value="{{ old('nacionalidade', $comodatario['nacionalidade'] ?? 'Brasileiro(a)') }}"></label>
        <label>RG <input name="rg" maxlength="30" required value="{{ old('rg', $comodatario['rg'] ?? '') }}" placeholder="00.000.000-0"></label>
        <label>CPF <input name="cpf" maxlength="14" required inputmode="numeric" value="{{ old('cpf', $comodatario['cpf'] ?? '') }}" placeholder="000.000.000-00"></label>
        <label class="largo">Endereço <input name="endereco" maxlength="255" required value="{{ old('endereco', $comodatario['endereco'] ?? '') }}" placeholder="Rua, número, bairro – cidade/UF – CEP"></label>
      </div>
      <div class="assinatura-quadro" data-assinatura>
        <canvas aria-label="Quadro de assinatura: assine com o dedo ou o mouse"></canvas>
        <input type="hidden" name="assinatura" data-assinatura-campo>
        <div class="assinatura-acoes">
          <small class="suave">Assine no quadro acima com o dedo (celular) ou o mouse.</small>
          <button type="button" class="botao pequeno secundario" data-assinatura-limpar>Limpar</button>
        </div>
      </div>
      <label class="opcao"><input type="checkbox" name="aceite" value="1" required> <span>Li e concordo com o contrato de comodato.</span></label>
      <div class="acoes"><button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'check']) Assinar contrato</button></div>
    </form>
  @elseif ($t->status === 'pendente')
    <p class="suave nao-imprimir">Aguardando a assinatura de {{ $t->usuario?->nome }} (pelo portal, em "Meus equipamentos").</p>
  @endif

  @if ($t->status !== 'cancelado')
    @foreach ($testemunhas as $te)
      @continue($te->assinou() || ! ((int) $te->usuario_id === (int) auth()->id() || $podeDefinirTestemunhas))
      @php($ehEla = (int) $te->usuario_id === (int) auth()->id())
      <form method="post" action="{{ route('inventario-ti.termos.testemunha.assinar', [$t, $te->ordem]) }}" class="formulario-vertical nao-imprimir termo-assinar">
        @csrf
        <h2>Assinar como testemunha {{ $te->ordem }}{{ $ehEla ? '' : ' — '.$te->usuario?->nome }}</h2>
        @unless ($ehEla)
          <p class="aviso">Assinatura presencial: entregue o aparelho para <strong>{{ $te->usuario?->nome }}</strong> assinar. Fica registrado que foi no seu aparelho.</p>
        @endunless
        <div class="campos">
          <label class="largo">Nome <input name="nome" maxlength="120" value="{{ old('nome', $te->usuario?->nome) }}"></label>
          <label>RG <input name="rg" maxlength="30" required value="{{ old('rg', $ehEla ? $rgTestemunha : '') }}" placeholder="00.000.000-0 SSP/SP"></label>
        </div>
        <div class="assinatura-quadro" data-assinatura>
          <canvas aria-label="Quadro de assinatura da testemunha: assine com o dedo ou o mouse"></canvas>
          <input type="hidden" name="assinatura_testemunha" data-assinatura-campo>
          <div class="assinatura-acoes">
            <small class="suave">Assine no quadro acima com o dedo (celular) ou o mouse.</small>
            <button type="button" class="botao pequeno secundario" data-assinatura-limpar>Limpar</button>
          </div>
        </div>
        <div class="acoes"><button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'check']) Assinar como testemunha</button></div>
      </form>
    @endforeach
  @endif
</section>
@endsection
