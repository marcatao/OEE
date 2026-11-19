@if (session('ok'))<p class="aviso ok" role="status">{{ session('ok') }}</p>@endif
@if (session('erro'))<p class="aviso erro" role="alert">{{ session('erro') }}</p>@endif
@if (session('ss_automatica'))<p class="aviso {{ str_contains(session('ss_automatica'), 'NÃO') ? 'alerta' : 'ok' }}" role="status">{{ session('ss_automatica') }}</p>@endif
