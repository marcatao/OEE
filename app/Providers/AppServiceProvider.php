<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Azure\AzureExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        self::aplicarEnderecoPublico();

        // Provedor "azure" do Socialite (Microsoft Entra ID).
        Event::listen(SocialiteWasCalled::class, [AzureExtendSocialite::class, 'handle']);

        // @dataHora($valor): data gravada em UTC exibida no fuso local (dd/mm/aaaa HH:mm).
        Blade::directive('dataHora', fn ($expr) => "<?php echo e(\\App\\Providers\\AppServiceProvider::dataHora($expr)); ?>");
    }

    /**
     * Portal publicado numa subpasta (ex.: APP_URL=https://www.alumbra.com.br/oee): todos os links, redirecionamentos e
     * o retorno do login passam a usar esse endereço, mesmo quando o servidor recebe a requisição por um proxy ou por
     * outro nome. Sem subpasta no APP_URL, nada muda (o Laravel usa o endereço da própria requisição).
     */
    public static function aplicarEnderecoPublico(): void
    {
        $url = rtrim((string) config('app.url'), '/');
        if (trim((string) parse_url($url, PHP_URL_PATH), '/') === '') {
            return;
        }

        URL::forceRootUrl($url);
        if (str_starts_with($url, 'https://')) {
            URL::forceScheme('https');
        }
    }

    public static function dataHora($valor): string
    {
        if (! $valor) {
            return '-';
        }

        return Carbon::parse($valor, 'UTC')->setTimezone(config('oee.fuso_exibicao'))->format('d/m/Y H:i');
    }
}
