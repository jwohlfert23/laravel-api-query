<?php

namespace Jwohlfert23\LaravelApiQuery;

use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;

class LaravelApiQueryServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Collection::macro('filterValidColumns', function () {
            return $this->filter(function ($value, $column) {
                if (! is_string($column)) {
                    return false;
                }

                return preg_match('/^[a-z]+[a-z0-9._]+$/i', $column);
            });
        });
    }
}
