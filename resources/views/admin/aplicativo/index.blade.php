@extends('layouts.portal')

@section('titulo', 'Aplicativo')

@section('conteudo')
<section class="cartao">
  <p class="trilha">Segurança</p>
  <h1>Aplicativo Android</h1>
  <p class="suave">Publique aqui o APK do aplicativo. Os celulares perguntam ao portal se há versão nova (ao abrir e de tempos em
    tempos, na rede da fábrica), baixam o arquivo pelo portal e pedem para o usuário tocar em <strong>Instalar</strong>
    (o Android sempre pede essa confirmação). A versão é lida do próprio APK.</p>

  <div class="pm-panorama">
    <div><span>Versão oferecida aos celulares</span><strong>{{ $atual ? $atual->nome.' (build '.$atual->codigo.')' : 'Nenhuma' }}</strong></div>
    <div><span>Build mínimo obrigatório</span><strong>{{ $minima > 0 ? $minima : 'Nenhum' }}</strong></div>
  </div>

  <h2>Publicar nova versão</h2>
  <form method="post" action="{{ route('admin.aplicativo.store') }}" enctype="multipart/form-data" class="formulario-vertical">
    @csrf
    <label>Arquivo APK (até {{ $maxMb }} MB)
      <input type="file" name="apk" accept=".apk,application/vnd.android.package-archive" required>
      @error('apk')<span class="erro">{{ $message }}</span>@enderror
    </label>
    <label>O que mudou (aparece no celular)
      <textarea name="observacao" rows="3" maxlength="1000" placeholder="Ex.: gráfico de produção com a meta">{{ old('observacao') }}</textarea>
    </label>
    <label class="opcao"><input type="checkbox" name="obrigatoria" value="1" @checked(old('obrigatoria'))>
      <span>Atualização obrigatória <small>quem estiver abaixo desta versão só volta a usar o app depois de atualizar</small></span></label>
    <div class="acoes"><button class="botao" type="submit">@include('layouts.partials.icone', ['nome' => 'mais']) Publicar</button></div>
  </form>

  <h2>Versões publicadas</h2>
  <div class="rolagem">
  <table class="tabela">
    <thead><tr><th>Versão</th><th class="num">Build</th><th class="num">Tamanho</th><th>O que mudou</th><th>Publicada</th><th>Obrigatória</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    @forelse ($lista as $v)
      <tr>
        <td><strong>{{ $v->nome }}</strong>@if ($atual && (int) $atual->id === (int) $v->id) <span class="etiqueta situacao-online">Atual</span>@endif</td>
        <td class="num">{{ $v->codigo }}</td>
        <td class="num">{{ number_format($v->tamanho / 1048576, 1, ',', '.') }} MB</td>
        <td class="quebra"><small>{{ $v->observacao ?? '—' }}</small></td>
        <td>@dataHora($v->criado_em)<br><small class="suave">{{ $v->criadoPor?->nome }}</small></td>
        <td>{{ $v->obrigatoria ? 'Sim' : 'Não' }}</td>
        <td><span class="etiqueta {{ $v->ativo ? 'situacao-online' : 'situacao-inativo' }}">{{ $v->ativo ? 'Ativa' : 'Desativada' }}</span></td>
        <td style="white-space:nowrap">
          <a class="botao pequeno secundario" href="{{ route('admin.aplicativo.baixar', $v->id) }}">Baixar</a>
          <form method="post" action="{{ route('admin.aplicativo.update', $v->id) }}" style="display:inline">
            @csrf @method('put')
            <input type="hidden" name="ativo" value="{{ $v->ativo ? 0 : 1 }}"><input type="hidden" name="obrigatoria" value="{{ $v->obrigatoria ? 1 : 0 }}">
            <button class="botao pequeno secundario" type="submit">{{ $v->ativo ? 'Desativar' : 'Reativar' }}</button>
          </form>
          <form method="post" action="{{ route('admin.aplicativo.update', $v->id) }}" style="display:inline">
            @csrf @method('put')
            <input type="hidden" name="ativo" value="{{ $v->ativo ? 1 : 0 }}"><input type="hidden" name="obrigatoria" value="{{ $v->obrigatoria ? 0 : 1 }}">
            <button class="botao pequeno secundario" type="submit">{{ $v->obrigatoria ? 'Tornar opcional' : 'Tornar obrigatória' }}</button>
          </form>
        </td>
      </tr>
    @empty
      <tr><td colspan="8" class="suave">Nenhuma versão publicada ainda.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  <p class="suave"><small>Desativar uma versão (ex.: com defeito) faz os celulares voltarem a oferecer a anterior ativa. O
    Android só instala por cima um APK assinado com a mesma chave (a do repositório) e com build maior que o instalado.</small></p>
</section>
@endsection
