<?php

use App\Console\Commands\ConfigureBuiltForCloud;
use App\Console\Commands\InstallDuckDb;
use App\Console\Commands\InstallFluxPro;
use App\Console\Commands\LoadVerificationCorpus;
use App\Console\Commands\OptimizeTailwind;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ConfigureBuiltForCloud::class,
        InstallDuckDb::class,
        InstallFluxPro::class,
        LoadVerificationCorpus::class,
        OptimizeTailwind::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
