<?php

namespace Jwohlfert23\LaravelApiQuery;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kirschbaum\PowerJoins\PowerJoins;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * @mixin Model
 */
trait BuildQueryFromRequest
{
    use PowerJoins;

    public function scopeBuildFrom(Builder $builder, ParameterBag $input, $search = true)
    {
        $apiQueryBuilder = ApiQueryBuilder::applyInputToBuilder($builder, $input);

        if ($search && ($query = $input->get('query'))) {
            if (method_exists(static::class, 'search')) {
                return static::search($query)->query(function ($builder) use ($apiQueryBuilder) {
                    $builder->with($apiQueryBuilder->getWiths()->all());
                });
            } elseif (method_exists($this, 'scopeSearch')) {
                $builder->search($query);
            }
        }
    }

    public function scopeBuildFromArray(Builder $builder, array $array, $search = true)
    {
        $this->scopeBuildFrom($builder, new ParameterBag($array), $search);
    }

    public function scopeBuildFromRequest(Builder $builder, $search = true)
    {
        if (request()->method() !== 'GET') {
            return;
        }

        $this->scopeBuildFrom($builder, request()->query, $search);
    }

    public function attributeIsDate(string $key)
    {
        return $this->isDateAttribute($key);
    }
}
