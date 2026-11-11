{{-- Em todas as telas: OS concluídas esperando a avaliação do usuário logado (solicitante). --}}
@php
  try { $pendentesAvaliacao = auth()->check() ? \App\Support\AcessoOs::pendentesDeAvaliacao(auth()->user()) : collect(); }
  catch (\Throwable $e) { $pendentesAvaliacao = collect(); } // banco ainda sem as tabelas das OS: sem aviso
@endphp
@if ($pendentesAvaliacao->isNotEmpty() && ! request()->routeIs('os.ordens.show'))
  <div class="aviso-avaliacao" role="status">
    @include('layouts.partials.icone', ['nome' => 'alerta'])
    <div>
      <strong>{{ $pendentesAvaliacao->count() === 1 ? 'Você tem 1 ordem de serviço concluída esperando a sua avaliação.' : 'Você tem '.$pendentesAvaliacao->count().' ordens de serviço concluídas esperando a sua avaliação.' }}</strong>
      <span>
        @foreach ($pendentesAvaliacao->take(5) as $pendente)
          <a href="{{ route('os.ordens.show', $pendente->id) }}#avaliar">{{ $pendente->numeroFormatado() }} — {{ \Illuminate\Support\Str::limit($pendente->titulo ?: $pendente->descricao, 50) }}</a>
        @endforeach
      </span>
    </div>
  </div>
@endif
