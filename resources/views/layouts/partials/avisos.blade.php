@if (session('ok'))<p class="aviso ok" role="status">{{ session('ok') }}</p>@endif
@if (session('erro'))<p class="aviso erro" role="alert">{{ session('erro') }}</p>@endif
