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
      <label>Planta <code>{{ $planta->codigo }}</code><input name="nome" required maxlength="120" value="{{ $planta->nome }}"></label>
      @include('partials.campo-unidade', ['registro' => $planta])
      <label>Situação
        <select name="ativo"><option value="1" @selected($planta->ativo)>Ativa</option><option value="0" @selected(! $planta->ativo)>Inativa</option></select>
      </label>
      <button class="botao secundario pequeno" type="submit">Salvar planta</button>
    </form>

    <div class="rolagem">
    <table class="tabela">
      <thead><tr><th>Código</th><th>Linha</th><th class="num">Máquinas</th><th>Situação</th><th></th></tr></thead>
      <tbody>
      @forelse ($planta->linhas as $linha)
        <tr>
          <td><code>{{ $linha->codigo }}</code></td>
          <td colspan="4">
            <form method="post" action="{{ route('admin.estrutura.linhas.update', $linha) }}" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
              @csrf @method('put')
              <input name="nome" required maxlength="120" value="{{ $linha->nome }}" aria-label="Nome da linha">
              @include('partials.campo-unidade', ['registro' => $linha])
              <span class="suave">{{ $linha->maquinas_count }} máquina(s)</span>
              <select name="ativo" aria-label="Situação da linha"><option value="1" @selected($linha->ativo)>Ativa</option><option value="0" @selected(! $linha->ativo)>Inativa</option></select>
              <button class="botao secundario pequeno" type="submit">Salvar</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="5" class="suave">Nenhuma linha nesta planta.</td></tr>
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
