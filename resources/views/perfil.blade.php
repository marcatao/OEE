@extends('layouts.portal')

@section('titulo', 'Perfil')

@section('conteudo')
@php($u = auth()->user())
<section class="cartao">
  <h1>Meu perfil</h1>
  <dl>
    <dt>Nome</dt><dd>{{ $u->nome }}</dd>
    <dt>E-mail</dt><dd>{{ $u->email }}</dd>
    <dt>Perfil no portal</dt><dd>{{ $u->rotuloPerfil() }}</dd>
    <dt>Grupos</dt><dd>{{ $u->grupos->pluck('nome')->join(', ') ?: '-' }}</dd>
    <dt>Último login</dt><dd>@dataHora($u->ultimo_login_em)</dd>
    <dt>ID do objeto (Entra ID)</dt><dd><code>{{ $u->entra_oid }}</code></dd>
    <dt>Locatário</dt><dd><code>{{ $u->entra_tenant_id }}</code></dd>
  </dl>
</section>
@endsection
