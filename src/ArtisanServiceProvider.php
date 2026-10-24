<?php

namespace Firevel\Artisan;

use Firevel\Artisan\Console\Commands\RemoteArtisanCommand;
use Illuminate\Support\ServiceProvider;
use Route;

class ArtisanServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/artisan.php', 'artisan');

        Route::post(
            '/' . config('artisan.route.prefix') . '/call',
            'Firevel\Artisan\Http\Controllers\ArtisanController@call'
        );

        Route::post(
            '/' . config('artisan.route.prefix') . '/queue',
            'Firevel\Artisan\Http\Controllers\ArtisanController@queue'
        );
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes(
            [
                __DIR__.'/../config/artisan.php' => config_path('artisan.php'),
            ],
            'config'
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                RemoteArtisanCommand::class,
            ]);
        }
    }
}
