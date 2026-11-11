@extends('layouts.portal')

@section('titulo', 'Meu perfil')

@section('conteudo')
<section class="cartao">
  <div class="perfil-cabecalho">
    @include('partials.avatar', ['usuario' => $u, 'classe' => 'grande'])
    <div>
      <h1>{{ $u->nome ?? $u->email }}</h1>
      <p class="suave">{{ collect([$u->cargo, $u->setor])->filter()->join(' · ') ?: 'Cargo e setor não informados' }}</p>
      <p><span class="etiqueta perfil-{{ $u->perfil }}">{{ $u->rotuloPerfil() }}</span>
        @if ($u->grupos->isNotEmpty())<span class="suave"> · {{ $u->grupos->pluck('nome')->join(', ') }}</span>@endif</p>
    </div>
    <span class="topo-espaco"></span>
    <form method="post" action="{{ route('perfil.entra') }}">
      @csrf
      <button class="botao secundario" type="submit" @disabled(! $podeLerEntra)
              title="{{ $podeLerEntra ? 'Troca setor, cargo, e-mail, celular e foto pelos dados do Microsoft Entra ID' : 'Saia e entre novamente para liberar esta opção' }}">
        @include('layouts.partials.icone', ['nome' => 'atualizar']) Atualizar com o Entra ID
      </button>
    </form>
  </div>

  @if ($errors->any())<p class="aviso erro" role="alert" style="margin-top:1rem">{{ $errors->first() }}</p>@endif

  <form method="post" action="{{ route('perfil.update') }}" enctype="multipart/form-data">
    @csrf
    @method('put')

    <h2>Dados profissionais</h2>
    <div class="campos">
      <label>Setor
        <input name="setor" maxlength="120" value="{{ old('setor', $u->setor) }}" placeholder="Ex.: Produção">
      </label>
      <label>Cargo
        <input name="cargo" maxlength="120" value="{{ old('cargo', $u->cargo) }}" placeholder="Ex.: Supervisor de produção">
      </label>
      <label>E-mail de contato
        <input name="email_contato" type="email" maxlength="320" value="{{ old('email_contato', $u->email_contato ?? $u->email) }}">
      </label>
      <label>Celular
        <input name="celular" type="tel" maxlength="40" value="{{ old('celular', $u->celular) }}" placeholder="(11) 99999-9999">
      </label>
    </div>

    <h2>Foto de perfil</h2>
    <div class="foto-campos">
      <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" aria-label="Escolher foto">
      <small class="suave">JPG, PNG ou WEBP, até 3 MB. Se você não escolher uma foto, usamos a do Microsoft 365 (quando houver).</small>
      @if ($u->temFoto())
        <label class="opcao"><input type="checkbox" name="remover_foto" value="1"> <span>Remover a foto atual</span></label>
      @endif
    </div>

    <h2>Identificação <small class="suave">(somente leitura)</small></h2>
    <div class="campos">
      <label>ID no portal
        <input value="{{ $u->id }}" readonly>
      </label>
      <label>Matrícula
        <input value="{{ $u->matricula ?? 'Não cadastrada no Entra ID' }}" readonly>
      </label>
      <label>Conta Microsoft (login)
        <input value="{{ $u->email }}" readonly>
      </label>
      <label><span>Centro de custo <small>(local de escritório no Entra ID)</small></span>
        <input value="{{ $u->local_escritorio ?? 'Não informado no Entra ID' }}" readonly>
      </label>
      <label>Departamento
        <input value="{{ $u->departamento ?? 'Não informado no Entra ID' }}" readonly>
      </label>
      <label>Gerente responsável
        <input value="{{ $u->gerente_nome ? $u->gerente_nome.($u->gerente_email ? ' ('.$u->gerente_email.')' : '') : 'Não informado no Entra ID' }}" readonly>
      </label>
      <label>ID do objeto no Entra ID
        <input value="{{ $u->entra_oid }}" readonly>
      </label>
      <label>Último login
        <input value="@dataHora($u->ultimo_login_em)" readonly>
      </label>
      <label>Dados lidos do Entra ID em
        <input value="{{ $u->perfil_entra_em ? \App\Providers\AppServiceProvider::dataHora($u->perfil_entra_em) : 'Ainda não lidos' }}" readonly>
      </label>
    </div>

    <div class="acoes" style="margin-top:1.5rem">
      <button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'check']) Salvar perfil</button>
      <a class="botao secundario" href="{{ route('inicio') }}">Cancelar</a>
    </div>
  </form>
</section>
@endsection
