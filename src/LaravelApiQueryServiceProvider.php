<?php

namespace Jwohlfert23\LaravelApiQuery;

use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class LaravelApiQueryServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Event::listen(MigrationsStarted::class, function () {
            Cache::tags('laravel-api-query')->flush();
        });
    }
}
