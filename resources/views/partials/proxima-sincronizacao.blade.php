{{-- Próxima sincronização automática da tabela (Segurança › Banco de dados › Periodicidade). Parâmetro: $chave --}}
@php($proximaSinc = \App\Services\Protheus\AgendaSincronizacoes::proximaDe($chave))
@if ($proximaSinc)
<span class="proxima-sinc">Próxima sincronização: <strong>{{ $proximaSinc->isSameDay(now(config('oee.fuso_exibicao'))) ? 'hoje às '.$proximaSinc->format('H:i') : $proximaSinc->format('d/m/Y H:i') }}</strong>.</span>
@else
<span class="proxima-sinc">Próxima sincronização: automática desligada.</span>
@endif
