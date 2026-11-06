@extends('layouts.portal')

@section('titulo', $centro->exists ? 'Editar centro de custo' : 'Novo centro de custo')

@section('conteudo')
@php($data = fn ($v) => strlen(trim((string) $v)) === 8 ? substr($v, 0, 4).'-'.substr($v, 4, 2).'-'.substr($v, 6, 2) : '')
<section class="cartao">
  <p class="trilha">Administração › <a href="{{ route('admin.centros-custo.index') }}">Centros de custo</a></p>
  <h1>{{ $centro->exists ? $centro->codigo().' — '.$centro->descricao() : 'Novo centro de custo' }}</h1>

  @if ($centro->veioDoProtheus())
    <p class="aviso alerta">Este centro de custo veio do Protheus (R_E_C_N_O_ {{ $centro->r_e_c_n_o_ }}). Alterações feitas aqui são
      substituídas na próxima importação; prefira alterar no Protheus.</p>
  @endif
  @if ($errors->any())<p class="aviso erro" role="alert">{{ $errors->first() }}</p>@endif

  <form method="post" class="formulario-vertical"
        action="{{ $centro->exists ? route('admin.centros-custo.update', $centro) : route('admin.centros-custo.store') }}">
    @csrf
    @if ($centro->exists) @method('put') @endif

    <div class="campos">
      <label>Código (CTT_CUSTO)
        <input name="ctt_custo" required maxlength="20" value="{{ old('ctt_custo', $centro->codigo()) }}" placeholder="Ex.: 110101">
      </label>
      <label>Descrição (CTT_DESC01)
        <input name="ctt_desc01" required maxlength="40" value="{{ old('ctt_desc01', $centro->descricao()) }}" placeholder="Ex.: Injeção">
      </label>
      <label>Filial (CTT_FILIAL)
        <input name="ctt_filial" maxlength="8" value="{{ old('ctt_filial', rtrim((string) $centro->ctt_filial)) }}" placeholder="Vazio = compartilhado">
      </label>
      <label>Classe (CTT_CLASSE)
        <select name="ctt_classe">
          @foreach (\App\Models\CentroCusto::CLASSES as $chave => $rotulo)
            <option value="{{ $chave }}" @selected(old('ctt_classe', $centro->ctt_classe) === (string) $chave)>{{ $chave }} — {{ $rotulo }}</option>
          @endforeach
        </select>
        <small>Só os analíticos aparecem no cadastro de máquinas.</small>
      </label>
      <label>Centro de custo superior (CTT_CCSUP)
        <input name="ctt_ccsup" maxlength="20" value="{{ old('ctt_ccsup', rtrim((string) $centro->ctt_ccsup)) }}">
      </label>
      <label>Bloqueado (CTT_BLOQ)
        <select name="ctt_bloq">
          <option value="2" @selected(old('ctt_bloq', $centro->ctt_bloq) === '2')>2 — Não</option>
          <option value="1" @selected(old('ctt_bloq', $centro->ctt_bloq) === '1')>1 — Sim</option>
        </select>
      </label>
      <label>Início da existência (CTT_DTEXIS)
        <input type="date" name="ctt_dtexis" value="{{ old('ctt_dtexis', $data($centro->ctt_dtexis)) }}">
      </label>
      <label>Fim da existência (CTT_DTEXSF)
        <input type="date" name="ctt_dtexsf" value="{{ old('ctt_dtexsf', $data($centro->ctt_dtexsf)) }}">
      </label>
    </div>

    <div class="acoes">
      <button class="botao" type="submit">Salvar</button>
      <a class="botao secundario" href="{{ route('admin.centros-custo.index') }}">Cancelar</a>
    </div>
  </form>
</section>
@endsection
