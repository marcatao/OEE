{{-- Em todas as telas: termos de responsabilidade do inventário de TI esperando a assinatura do usuário logado. --}}
@php
  try { $termosPendentes = auth()->check() ? app(\App\Services\InventarioTi\TermosResponsabilidade::class)->pendentesDe(auth()->user()) : collect(); }
  catch (\Throwable $e) { $termosPendentes = collect(); } // banco ainda sem as tabelas do inventário: sem aviso
@endphp
@if ($termosPendentes->isNotEmpty() && ! request()->routeIs('inventario-ti.termos.show'))
  <div class="aviso-termos" role="status">
    @include('layouts.partials.icone', ['nome' => 'alerta'])
    <div>
      <strong>{{ $termosPendentes->count() === 1 ? 'Você tem 1 termo de responsabilidade para assinar.' : 'Você tem '.$termosPendentes->count().' termos de responsabilidade para assinar.' }}</strong>
      <span>
        @foreach ($termosPendentes->take(5) as $pendente)
          <a href="{{ route('inventario-ti.termos.show', $pendente) }}">{{ $pendente->numeroFormatado() }} — {{ $pendente->item?->descricao }}</a>
        @endforeach
      </span>
    </div>
  </div>
@endif
