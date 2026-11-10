@extends('layouts.portal')

@section('titulo', 'Banco de dados')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Administração</p>
  <h1>Banco de dados</h1>
  <p class="suave">A conexão é configurada no arquivo <code>.env</code> do servidor (seção "Banco de dados"). Depois de
    alterar, rode <code>php artisan config:clear</code> e confira aqui.</p>
  <dl class="dados-curtos">
    @foreach ($conexao as $rotulo => $valor)
      <div><dt>{{ $rotulo }}</dt><dd><code>{{ $valor }}</code></dd></div>
    @endforeach
  </dl>
  <p class="{{ $ok ? 'ok' : 'erro' }}"><strong>{{ $ok ? 'Conexão OK' : 'Falha na conexão' }}:</strong> {{ $mensagem }}</p>
</section>

<section class="cartao bloco-protheus">
  <div class="titulo-com-acao">
    <h2>Integração com o Protheus</h2>
    @if ($sincronizacoes)
      <form method="post" action="{{ route('admin.banco.sincronizar') }}" data-sincronizar>
        @csrf
        <button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'atualizar']) <span>Sincronizar com o Protheus</span></button>
      </form>
    @endif
  </div>
  <p class="suave">Os dados do Protheus são só <strong>lidos</strong>: a sincronização copia para o portal e nunca
    altera nada no Protheus. Pode rodar quantas vezes quiser; ela só grava o que mudou. Pelo botão, ela roda em
    etapas curtas e mostra o andamento aqui: mantenha a página aberta até terminar.</p>

  <div id="sinc-progresso" class="sinc-progresso" aria-live="polite" hidden>
    <strong>Sincronizando com o Protheus…</strong>
    <ul></ul>
  </div>

  <dl class="dados-curtos">
    <div><dt>Servidor</dt><dd><code>{{ $conexaoProtheus['Servidor'] ?? '-' }}</code></dd></div>
    <div><dt>Banco</dt><dd><code>{{ $conexaoProtheus['Banco'] ?? '-' }}</code></dd></div>
    <div><dt>Usuário</dt><dd><code>{{ $conexaoProtheus['Usuário'] ?? '-' }}</code></dd></div>
    <div><dt>Sincronização automática</dt>
      <dd>@if ($agenda)<code>{{ $agenda }}</code> (agendador do Laravel)@else desligada (só pelo botão ou comando)@endif</dd></div>
  </dl>

  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Tabela do portal</th><th>Origem no Protheus</th><th>Última sincronização</th><th>Resultado</th><th></th></tr></thead>
    <tbody>
    @foreach ($sincronizacoes as $chave => $item)
      @php($u = $ultimas[$chave] ?? null)
      <tr>
        <td><strong>{{ $item['descricao'] }}</strong>
          @if (($item['modo'] ?? 'espelho') === 'mista')<span class="etiqueta origem-mista">Mista</span>@endif
          <br><code class="suave">{{ $item['tabela_destino'] }}</code></td>
        <td><code>{{ $conexaoProtheus['Banco'] ?? '' }}.{{ $item['tabela_origem'] }}</code></td>
        <td>@if ($u)@dataHora($u->iniciado_em)@else <span class="suave">nunca</span>@endif</td>
        <td class="resultado">
          @if ($u)
            <span class="etiqueta sinc-{{ $u->situacao }}">{{ ['sucesso' => 'Sucesso', 'erro' => 'Erro', 'executando' => 'Em andamento'][$u->situacao] ?? $u->situacao }}</span>
            @if ($u->situacao === 'sucesso')
              <small class="suave">{{ $u->lidos }} lidos · {{ $u->novos }} novos · {{ $u->atualizados }} atualizados @if ($u->excluidos) · {{ $u->excluidos }} excluídos @endif</small>
            @elseif ($u->mensagem)
              <br><small class="erro">{{ \Illuminate\Support\Str::limit($u->mensagem, 160) }}</small>
            @endif
          @else
            -
          @endif
        </td>
        <td>
          <form method="post" action="{{ route('admin.banco.sincronizar') }}" data-sincronizar data-tabela="{{ $chave }}">
            @csrf
            <input type="hidden" name="tabela" value="{{ $chave }}">
            <button class="botao secundario pequeno" type="submit">Sincronizar só esta</button>
          </form>
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>

  <p class="suave"><small><strong>Centros de custo</strong>: cópia da CTT, atualizada a cada sincronização.
    <strong>Máquinas</strong> (mista): código, nome e centro de custo vêm da SHB só na primeira vez e a máquina chega
    <strong>inativa</strong> — complete o cadastro em Máquinas e ative. Depois disso a sincronização só mexe na máquina se
    ela for excluída no Protheus (aí ela é desativada).</small></p>

  @if ($historico->isNotEmpty())
    <h3>Últimas sincronizações</h3>
    <div class="rolagem">
    <table class="tabela">
      <thead><tr><th>Início</th><th>Tabela</th><th>Disparo</th><th>Resultado</th><th class="num">Lidos</th><th class="num">Novos</th><th class="num">Atualizados</th><th class="num">Excluídos</th><th class="num">Duração</th></tr></thead>
      <tbody>
      @foreach ($historico as $h)
        <tr>
          <td>@dataHora($h->iniciado_em)</td>
          <td>{{ $sincronizacoes[$h->chave]['descricao'] ?? $h->chave }}</td>
          <td>{{ ['manual' => 'Botão', 'agendado' => 'Automática', 'comando' => 'Comando'][$h->disparo] ?? $h->disparo }}@if ($h->usuario) <small class="suave">({{ $h->usuario->nome }})</small>@endif</td>
          <td class="resultado">
            <span class="etiqueta sinc-{{ $h->situacao }}">{{ ['sucesso' => 'Sucesso', 'erro' => 'Erro', 'executando' => 'Em andamento'][$h->situacao] ?? $h->situacao }}</span>
            @if ($h->situacao === 'erro' && $h->mensagem)<br><small class="erro">{{ \Illuminate\Support\Str::limit($h->mensagem, 120) }}</small>@endif
          </td>
          <td class="num">{{ number_format($h->lidos, 0, ',', '.') }}</td>
          <td class="num">{{ number_format($h->novos, 0, ',', '.') }}</td>
          <td class="num">{{ number_format($h->atualizados, 0, ',', '.') }}</td>
          <td class="num">{{ number_format($h->excluidos ?? 0, 0, ',', '.') }}</td>
          <td class="num">{{ $h->duracaoSegundos() !== null ? $h->duracaoSegundos().' s' : '-' }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  @endif
</section>

@if ($tabelas)
<section class="cartao">
  <h2>Tabelas</h2>
  <div class="legenda-origens">
    <span class="etiqueta origem-sistema">Sistema OEE</span><small class="suave">criada e mantida pelo portal</small>
    <span class="etiqueta origem-protheus">Protheus</span><small class="suave">cópia de uma tabela do Protheus</small>
    <span class="etiqueta origem-mista">Mista</span><small class="suave">alguns campos vêm do Protheus, o resto é do portal</small>
  </div>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Tabela</th><th>Origem</th><th class="num">Registros</th></tr></thead>
    <tbody>
    @foreach ($tabelas as $nome => $qtd)
      @php($o = $origens[$nome] ?? ['tipo' => 'sistema', 'origem' => null])
      <tr>
        <td><code>{{ $nome }}</code></td>
        <td>
          @if ($o['tipo'] === 'protheus')
            <span class="etiqueta origem-protheus">Protheus</span> <small class="suave">de <code>{{ $o['origem'] }}</code></small>
          @elseif ($o['tipo'] === 'mista')
            <span class="etiqueta origem-mista">Mista</span> <small class="suave">código, nome e centro de custo de <code>{{ $o['origem'] }}</code></small>
          @else
            <span class="etiqueta origem-sistema">Sistema OEE</span>
          @endif
        </td>
        <td class="num">{{ $qtd === null ? 'não criada' : number_format($qtd, 0, ',', '.') }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
  @if (in_array(null, $tabelas, true))
    <p class="erro">Há tabelas não criadas: rode <code>php artisan migrate</code> no servidor.</p>
  @endif
</section>
@endif

@if ($sincronizacoes)
<script>
(function () {
  var url = @json(route('admin.banco.sincronizar.etapa'));
  var tabelas = @json(array_keys($sincronizacoes));
  var nomes = @json(collect($sincronizacoes)->map(fn ($i) => $i['descricao']));
  var painel = document.getElementById('sinc-progresso');
  var lista = painel.querySelector('ul');
  var rodando = false;
  var numero = function (n) { return Number(n || 0).toLocaleString('pt-BR'); };
  var espera = function (ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); };

  window.addEventListener('beforeunload', function (e) { if (rodando) { e.preventDefault(); e.returnValue = ''; } });

  document.querySelectorAll('form[data-sincronizar]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.fetch) return; // sem fetch: envia o formulário do jeito antigo
      ev.preventDefault();
      if (rodando) return;
      rodando = true;
      document.querySelectorAll('form[data-sincronizar] button').forEach(function (b) { b.disabled = true; });
      var token = form.querySelector('input[name="_token"]').value;
      executar(form.dataset.tabela ? [form.dataset.tabela] : tabelas, token);
    });
  });

  // Uma etapa no servidor; tenta de novo se a rede ou o servidor falharem (a etapa seguinte continua de onde parou).
  async function etapa(tabela, id, token) {
    for (var tentativa = 1; ; tentativa++) {
      try {
        var corpo = new FormData();
        corpo.append('_token', token);
        corpo.append('tabela', tabela);
        if (id) corpo.append('id', id);
        var r = await fetch(url, { method: 'POST', body: corpo, credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (r.ok) return await r.json();
        if (r.status === 419) throw new Error('A sessão expirou. Atualize a página e tente de novo.');
        if (tentativa >= 4) throw new Error('O servidor respondeu ' + r.status + '.');
      } catch (e) {
        if (tentativa >= 4 || /sessão/.test(e.message)) throw e;
      }
      await espera(3000 * tentativa);
    }
  }

  async function executar(chaves, token) {
    painel.hidden = false;
    lista.innerHTML = '';
    var falhou = false;
    for (var i = 0; i < chaves.length; i++) {
      var item = document.createElement('li');
      item.textContent = nomes[chaves[i]] + ': começando…';
      lista.appendChild(item);
      var id = null;
      try {
        for (;;) {
          var r = await etapa(chaves[i], id, token);
          id = r.id;
          var texto = r.descricao + ': ' + numero(r.lidos) + ' lidos, ' + numero(r.novos) + ' novos, ' + numero(r.atualizados) + ' atualizados'
            + (r.excluidos ? ', ' + numero(r.excluidos) + ' excluídos' : '');
          if (!r.terminou) { item.textContent = texto + '… (em andamento)'; continue; }
          if (r.situacao === 'sucesso') { item.textContent = '✓ ' + texto + '.'; item.className = 'ok'; }
          else { item.textContent = '✗ ' + r.descricao + ': ' + (r.mensagem || 'falhou'); item.className = 'erro'; falhou = true; }
          break;
        }
      } catch (e) {
        item.textContent = '✗ ' + nomes[chaves[i]] + ': ' + e.message; item.className = 'erro'; falhou = true;
      }
    }
    rodando = false;
    var fim = document.createElement('li');
    fim.innerHTML = '<strong>' + (falhou ? 'Terminou com erros.' : 'Sincronização concluída.') + '</strong> Atualizando a página…';
    lista.appendChild(fim);
    await espera(falhou ? 6000 : 2500);
    location.reload();
  }
})();
</script>
@endif
@endsection
