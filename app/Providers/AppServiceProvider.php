<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
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
        // Provedor "azure" do Socialite (Microsoft Entra ID).
        Event::listen(SocialiteWasCalled::class, [AzureExtendSocialite::class, 'handle']);

        // @dataHora($valor): data gravada em UTC exibida no fuso local (dd/mm/aaaa HH:mm).
        Blade::directive('dataHora', fn ($expr) => "<?php echo e(\\App\\Providers\\AppServiceProvider::dataHora($expr)); ?>");
    }

    public static function dataHora($valor): string
    {
        if (! $valor) {
            return '-';
        }

        return Carbon::parse($valor, 'UTC')->setTimezone(config('oee.fuso_exibicao'))->format('d/m/Y H:i');
    }
}
