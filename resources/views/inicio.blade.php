@extends('layouts.portal')

@use('App\Support\Formato')

@section('conteudo')
@guest
  {{-- Visitante: tela de entrada --}}
  <section class="entrada">
    <div class="entrada-marca">
      <img class="logo" src="{{ asset('img/alumbra-logo.svg') }}" alt="Alumbra">
      <div>
        <h1>{{ config('oee.nome') }}</h1>
        <p>{{ config('oee.descricao') }}: acompanhe a produção, as paradas e a eficiência de cada máquina.</p>
        <ul>
          <li>@include('layouts.partials.icone', ['nome' => 'check']) Disponibilidade × Performance × Qualidade</li>
          <li>@include('layouts.partials.icone', ['nome' => 'check']) Painéis por máquina, linha e turno</li>
          <li>@include('layouts.partials.icone', ['nome' => 'check']) Dados das máquinas via IoT</li>
        </ul>
      </div>
    </div>
    <div class="entrada-acesso">
      <img class="a" src="{{ asset('img/alumbra-icone.svg') }}" alt="">
      <h2>Acesse o portal</h2>
      <p class="suave">Entre com a sua conta corporativa da Alumbra (Microsoft Entra ID). Não é preciso outra senha.</p>
      <a class="botao botao-microsoft" href="{{ route('login') }}">
        <svg viewBox="0 0 21 21" aria-hidden="true"><path fill="#f25022" d="M1 1h9v9H1z"/><path fill="#7fba00" d="M11 1h9v9h-9z"/><path fill="#00a4ef" d="M1 11h9v9H1z"/><path fill="#ffb900" d="M11 11h9v9h-9z"/></svg>
        Entrar com Microsoft
      </a>
      <p class="suave"><small>No primeiro acesso o seu cadastro é criado e um administrador libera as telas que você pode usar.</small></p>
    </div>
  </section>
@else
  @php $u = auth()->user(); @endphp
  @if (! $u->temAcesso())
    <section class="cartao">
      <h1>Olá, {{ $u->primeiroNome() }}!</h1>
      @include('partials.aguardando')
      <a class="botao secundario" href="{{ route('perfil') }}">@include('layouts.partials.icone', ['nome' => 'perfil']) Completar meu perfil</a>
    </section>
  @else
    @php
      $agora = now(config('oee.fuso_exibicao'))->locale('pt_BR');
      $saudacao = $agora->hour < 12 ? 'Bom dia' : ($agora->hour < 18 ? 'Boa tarde' : 'Boa noite');
      $atalhos = array_values(array_filter([
        $u->pode('dashboard_maquina') ? ['dashboard.maquinas', 'dashboard', 'Dashboard por máquina', 'OEE, disponibilidade, performance e qualidade de cada máquina.'] : null,
        $u->pode('timeline') ? ['timeline', 'timeline', 'Timeline', 'Estado de cada máquina ao longo do dia, nas cores das legendas.'] : null,
        $u->pode('apontamentos') ? ['apontamentos.index', 'apontamento', 'Apontamentos', 'Registre o estado das máquinas: início, fim e legenda.'] : null,
        $u->pode('calculadora') ? ['calculadora', 'calculadora', 'Calculadora de OEE', 'Simule o OEE de um turno a partir de tempos e peças.'] : null,
        $u->ehAdmin() ? ['admin.maquinas.index', 'maquina', 'Máquinas', 'Cadastro das máquinas, linhas e ciclos ideais.'] : null,
        $u->ehAdmin() ? ['admin.iot.index', 'iot', 'Dispositivos IoT', 'Sensores, CLPs e gateways que enviam os dados.'] : null,
        $u->ehAdmin() ? ['cadastros.usuarios.index', 'usuarios', 'Usuários e acessos', 'Libere usuários e organize os grupos.'] : null,
        ['perfil', 'perfil', 'Meu perfil', 'Foto, setor, cargo e contatos.'],
      ]));
    @endphp

    <section class="heroi">
      <div>
        <p class="heroi-data">{{ \Illuminate\Support\Str::ucfirst($agora->translatedFormat('l, d \d\e F \d\e Y')) }}</p>
        <h1>{{ $saudacao }}, {{ $u->primeiroNome() }}!</h1>
        <p>{{ collect([$u->cargo, $u->setor])->filter()->join(' · ') ?: 'Bem-vindo ao '.config('oee.nome').'.' }}</p>
      </div>
      <div class="acoes">
        @if ($u->pode('dashboard_maquina'))
          <a class="botao" href="{{ route('dashboard.maquinas') }}">@include('layouts.partials.icone', ['nome' => 'dashboard']) Ver produção</a>
        @endif
        <a class="botao secundario" href="{{ route('perfil') }}">Meu perfil</a>
      </div>
    </section>

    <div class="kpis">
      @if ($hoje !== null)
        <a class="kpi {{ Formato::nivel($hoje->oee()) }}" href="{{ route('dashboard.maquinas') }}">
          <div class="medidor pequeno" style="--pct: {{ Formato::pct100($hoje->oee()) }}"><div><strong>{{ Formato::pct($hoje->oee(), 0) }}</strong></div></div>
          <div><span>OEE de hoje</span><small>{{ $hoje->temDados() ? 'todas as máquinas' : 'sem apontamentos hoje' }}</small></div>
        </a>
      @endif
      <div class="kpi">
        <div class="kpi-icone">@include('layouts.partials.icone', ['nome' => 'maquina'])</div>
        <div><span>Máquinas ativas</span><strong>{{ Formato::inteiro($maquinasAtivas) }}</strong></div>
      </div>
      <div class="kpi">
        <div @class(['kpi-icone', 'alerta' => $paradasAbertas > 0])>@include('layouts.partials.icone', ['nome' => 'relogio'])</div>
        <div><span>Paradas em andamento</span><strong>{{ Formato::inteiro($paradasAbertas) }}</strong></div>
      </div>
      <div class="kpi">
        <div class="kpi-icone">@include('layouts.partials.icone', ['nome' => 'sinal'])</div>
        <div><span>Dispositivos IoT online</span><strong>{{ $iotOnline }}<small> / {{ $iotAtivos }}</small></strong></div>
      </div>
      @if ($opsAbertas !== null)
        @php
          $opsAndamento = $opsAbertas->where('status', 'andamento')->count();
          $opsParadas = $opsAbertas->where('status', 'parada')->count();
        @endphp
        <a class="kpi" href="{{ route('ordens-producao.index', ['status' => ['andamento']]) }}">
          <div @class(['kpi-icone', 'alerta' => $opsParadas > 0])>@include('layouts.partials.icone', ['nome' => 'ordem_producao'])</div>
          <div><span>OPs em andamento</span><strong>{{ Formato::inteiro($opsAndamento) }}<small> / {{ Formato::inteiro($opsAbertas->count()) }} abertas</small></strong>
            @if ($opsParadas > 0)<small>{{ $opsParadas }} {{ $opsParadas === 1 ? 'parada' : 'paradas' }}</small>@endif</div>
        </a>
      @endif
      @if ($u->ehAdmin() && $aguardando > 0)
        <a class="kpi" href="{{ route('cadastros.usuarios.index') }}">
          <div class="kpi-icone alerta">@include('layouts.partials.icone', ['nome' => 'usuarios'])</div>
          <div><span>Aguardando liberação</span><strong>{{ $aguardando }}</strong><small>usuários para liberar</small></div>
        </a>
      @endif
    </div>

    @if ($opsAbertas !== null)
      <section class="cartao" style="margin-top:1.5rem">
        <div class="cartao-titulo">
          <h3>Ordens de produção abertas <small class="suave">({{ Formato::inteiro($opsAbertas->count()) }})</small></h3>
          <a href="{{ route('ordens-producao.index', ['status' => ['aberta', 'andamento', 'parada']]) }}">Ver na tela de OPs @include('layouts.partials.icone', ['nome' => 'seta'])</a>
        </div>
        @if ($opsAbertas->isEmpty())
          <p class="suave">Nenhuma OP aberta. As OPs vêm do Protheus (sincronização em Administração › Banco de dados).</p>
        @else
          <div class="rolagem ops-inicio">
          <table class="tabela">
            <thead><tr><th>OP</th><th>Status</th><th>Produto</th><th class="num">Quantidade</th><th class="num">Produzido</th><th>Previsão</th></tr></thead>
            <tbody>
            @foreach ($opsAbertas as $op)
              <tr>
                <td><code>{{ $op->numero }}</code></td>
                <td><span class="etiqueta op-{{ $op->status }}">{{ $op->rotuloStatus() }}</span></td>
                <td class="quebra"><code>{{ $op->c2_produto }}</code> <small>{{ ($produtosOps[$op->c2_produto] ?? null)?->descricao() }}</small></td>
                <td class="num">{{ number_format($op->c2_quant, 0, ',', '.') }} <small class="suave">{{ $op->c2_um ?: ($produtosOps[$op->c2_produto] ?? null)?->b1_um }}</small></td>
                <td class="num">{{ number_format($op->c2_quje, 0, ',', '.') }}
                  @if ($op->c2_quant > 0)<small class="suave">({{ number_format(min($op->c2_quje / $op->c2_quant, 9.99) * 100, 0, ',', '.') }}%)</small>@endif</td>
                <td>{{ \App\Models\OrdemProducao::data($op->c2_datpri) }} a {{ \App\Models\OrdemProducao::data($op->c2_datprf) }}</td>
              </tr>
            @endforeach
            </tbody>
          </table>
          </div>
        @endif
      </section>
    @endif

    <h2>Acesso rápido</h2>
    <div class="grade cartoes">
      @foreach ($atalhos as [$rota, $icone, $titulo, $texto])
        <a class="atalho" href="{{ route($rota) }}">
          <div class="kpi-icone">@include('layouts.partials.icone', ['nome' => $icone])</div>
          <strong>{{ $titulo }}</strong>
          <span>{{ $texto }}</span>
          <em>Abrir @include('layouts.partials.icone', ['nome' => 'seta'])</em>
        </a>
      @endforeach
    </div>

    <section class="cartao" style="margin-top:1.5rem">
      <h3>Como o OEE é calculado</h3>
      <p class="suave">O OEE mostra quanto da capacidade planejada virou peça boa. Referência: 85% ou mais é classe mundial; abaixo de 60% merece atenção.</p>
      <div class="formula">
        <div><strong>Disponibilidade</strong><small>tempo operando ÷ tempo planejado</small></div><span>×</span>
        <div><strong>Performance</strong><small>ciclo ideal × produzido ÷ tempo operando</small></div><span>×</span>
        <div><strong>Qualidade</strong><small>peças boas ÷ total produzido</small></div><span>=</span>
        <div class="resultado"><strong>OEE</strong><small>eficiência global</small></div>
      </div>
    </section>
  @endif
@endguest
@endsection
