<?php

namespace Imanghafoori\LaravelMicroscope;

use Illuminate\View\View;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Imanghafoori\LaravelMicroscope\Commands;
use Imanghafoori\LaravelMicroscope\SpyClasses\SpyGate;
use Imanghafoori\LaravelMicroscope\SpyClasses\SpyRouter;
use Imanghafoori\LaravelMicroscope\SpyClasses\ViewsData;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Imanghafoori\LaravelMicroscope\SpyClasses\SpyDispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactoryContract;
use Imanghafoori\LaravelMicroscope\ErrorReporters\ErrorPrinter;
use Imanghafoori\LaravelMicroscope\ErrorReporters\ConsolePrinterInstaller;

class LaravelMicroscopeServiceProvider extends ServiceProvider
{
    public function boot()
    {
        (app()['env'] !== 'production') && $this->spyView();

        if (! $this->canRun()) {
            return;
        }

        $this->commands([
            Commands\CheckEvents::class,
            Commands\CheckGates::class,
            Commands\CheckRoutes::class,
            Commands\CheckViews::class,
            Commands\CheckPsr4::class,
            Commands\CheckImports::class,
            Commands\CheckAll::class,
            Commands\ClassifyStrings::class,
            Commands\CheckDD::class,
            Commands\CheckEarlyReturns::class,
            Commands\CheckCompact::class,
        ]);

        ConsolePrinterInstaller::boot();
    }

    public function register()
    {
        if (! $this->canRun()) {
            return;
        }

        //  $this->loadConfig();

        app()->singleton(ErrorPrinter::class);

        // we need to start spying before the boot process starts.

        $command = $_SERVER['argv'][1] ?? null;

        $this->spyRouter();
        // we spy the router in order to have a list of route files.
        Str::startsWith('check:routes', $command) && app('router')->spyRouteConflict();
        Str::startsWith('check:events', $command) && $this->spyEvents();
        Str::startsWith('check:gates', $command) && $this->spyGates();
    }

    private function spyRouter()
    {
        $router = new SpyRouter(app('events'), app());
        $this->app->singleton('router', function ($app) use ($router) {
            return $router;
        });
        Route::swap($router);
    }

    private function spyGates()
    {
        $this->app->singleton(GateContract::class, function ($app) {
            return new SpyGate($app, function () use ($app) {
                return call_user_func($app['auth']->userResolver());
            });
        });
    }

    private function spyEvents()
    {
        $this->app->singleton('events', function ($app) {
            return (new SpyDispatcher($app))->setQueueResolver(function () use ($app) {
                return $app->make(QueueFactoryContract::class);
            });
        });
        Event::clearResolvedInstance('events');
    }

    public function spyView()
    {
        app()->singleton('microscope.views', ViewsData::class);

        \View::creator('*', function (View $view) {
            resolve('microscope.views')->add($view);
        });

        app()->terminating(function () {
            $spy = resolve('microscope.views');
            if (Str::startsWith($spy->main->getName(), ['errors::'])) {
                return;
            }
            $action = $this->getActionName();

            \Log::info('Laravel Microscope: The view file: '.$spy->main->getName().' at '.$action.' has some unused variables passed to it: ');
            \Log::info(array_diff_key($spy->getMainVars(), $spy->readTokenizedVars()));
        });
    }

    private function loadConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'laravel-microscope');
    }

    private function canRun()
    {
        return $this->app->runningInConsole() && app()['env'] !== 'production';
    }

    public function getActionName(): string
    {
        $action = '';
        if ($cRoute = \Route::getCurrentRoute()) {
            $action = $cRoute->getActionName();
        }

        return $action;
    }
}
