<?php

namespace Jwohlfert23\LaravelApiQuery;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class QueryHelpers
{
    public static function getWiths()
    {
        return collect(explode(',', request()->input('with')))
            ->map(function ($i) {
                return Str::camel(trim($i));
            })
            ->filter()
            ->unique()
            ->all();
    }

    public static function getFilters()
    {
        return (array)request()->query('filter');
    }

    public static function getSorts()
    {
        return collect(explode(',', request()->input('sort', '')))
            ->filter()
            ->mapWithKeys(function ($column) {
                $dir = $column[0] === '-' ? 'desc' : 'asc';
                return [
                    ltrim($column, '-') => $dir
                ];
            })->all();
    }

    public static function getColumnsNeeded()
    {
        return array_merge(array_keys(static::getSorts()), array_keys(static::getFilters()));
    }

    public static function dotToArray($dot)
    {
        $arr = [];
        $relations = is_string($dot) ? func_get_args() : $dot;
        foreach ($relations as $relation) {
            Arr::set($arr, $relation, true);
        }
        return $arr;
    }
}
