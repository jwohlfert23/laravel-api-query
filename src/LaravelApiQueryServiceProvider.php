<?php namespace Jwohlfert23\LaravelApiQuery;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

class LaravelApiQueryServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Event::listen(MigrationsStarted::class, function () {
            Cache::tags('laravel-api-query')->flush();
        });
    }
}
