@extends('layouts.portal')

@section('titulo', 'Plantas e linhas')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Administração</p>
  <h1>Plantas e linhas</h1>
  <p class="suave">Organização da fábrica: cada máquina fica numa linha, e cada linha numa planta.</p>
  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif

  <div class="grade duas">
    <form method="post" action="{{ route('admin.estrutura.plantas.store') }}" class="cartao" style="box-shadow:none">
      @csrf
      <h3>Nova planta</h3>
      <div class="campos">
        <label>Código <input name="codigo" required maxlength="20" placeholder="Ex.: MATRIZ"></label>
        <label>Nome <input name="nome" required maxlength="120" placeholder="Ex.: Planta Matriz"></label>
        @include('partials.campo-unidade', ['registro' => new \App\Models\Planta])
      </div>
      <div class="acoes" style="margin-top:1rem"><button class="botao" type="submit">Adicionar planta</button></div>
    </form>

    <form method="post" action="{{ route('admin.estrutura.linhas.store') }}" class="cartao" style="box-shadow:none">
      @csrf
      <h3>Nova linha</h3>
      <div class="campos">
        <label>Planta
          <select name="planta_id" required @disabled($plantas->isEmpty())>
            @foreach ($plantas->where('ativo', true) as $planta)
              <option value="{{ $planta->id }}">{{ $planta->nome }}</option>
            @endforeach
          </select>
        </label>
        <label>Código <input name="codigo" required maxlength="20" placeholder="Ex.: L1"></label>
        <label>Nome <input name="nome" required maxlength="120" placeholder="Ex.: Linha 1"></label>
        @include('partials.campo-unidade', ['registro' => new \App\Models\Linha])
      </div>
      <div class="acoes" style="margin-top:1rem"><button class="botao" type="submit" @disabled($plantas->isEmpty())>Adicionar linha</button></div>
    </form>
  </div>
</section>

@forelse ($plantas as $planta)
  <section class="cartao">
    <form method="post" action="{{ route('admin.estrutura.plantas.update', $planta) }}" class="filtros" style="margin:0">
      @csrf @method('put')
      <label><span>Planta <code>{{ $planta->codigo }}</code></span><input name="nome" required maxlength="120" value="{{ $planta->nome }}"></label>
      @include('partials.campo-unidade', ['registro' => $planta])
      <label>Situação
        <select name="ativo"><option value="1" @selected($planta->ativo)>Ativa</option><option value="0" @selected(! $planta->ativo)>Inativa</option></select>
      </label>
      <button class="botao secundario" type="submit">Salvar planta</button>
    </form>

    <div class="rolagem" style="margin-top:1rem">
    <table class="tabela tabela-form">
      <thead><tr><th>Código</th><th>Linha</th><th>Unidade</th><th class="num">Máquinas</th><th>Situação</th><th></th></tr></thead>
      <tbody>
      @forelse ($planta->linhas as $linha)
        <tr>
          <td><code>{{ $linha->codigo }}</code>
            <form method="post" action="{{ route('admin.estrutura.linhas.update', $linha) }}" id="linha-{{ $linha->id }}" hidden>
              @csrf @method('put')
            </form>
          </td>
          <td style="min-width:14rem"><input name="nome" required maxlength="120" value="{{ $linha->nome }}" aria-label="Nome da linha" form="linha-{{ $linha->id }}"></td>
          <td>@include('partials.campo-unidade', ['registro' => $linha, 'form' => 'linha-'.$linha->id, 'semRotulo' => true])</td>
          <td class="num">{{ $linha->maquinas_count }}</td>
          <td><select name="ativo" aria-label="Situação da linha" form="linha-{{ $linha->id }}"><option value="1" @selected($linha->ativo)>Ativa</option><option value="0" @selected(! $linha->ativo)>Inativa</option></select></td>
          <td class="acoes-linha"><button class="botao secundario pequeno" type="submit" form="linha-{{ $linha->id }}">Salvar</button></td>
        </tr>
      @empty
        <tr><td colspan="6" class="suave">Nenhuma linha nesta planta.</td></tr>
      @endforelse
      </tbody>
    </table>
    </div>
  </section>
@empty
  <section class="cartao vazio">
    @include('layouts.partials.icone', ['nome' => 'estrutura'])
    <p>Nenhuma planta cadastrada ainda. Comece pela planta e depois cadastre as linhas.</p>
  </section>
@endforelse
@endsection
