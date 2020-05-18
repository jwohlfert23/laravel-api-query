<?php

namespace Jwohlfert23\LaravelApiQuery;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @mixin Model
 */
trait BuildQueryFromRequest
{

    /**
     * @param $string
     * @return \Illuminate\Database\Query\Expression
     */
    public function getSortByExpression($string)
    {
        if (strpos($string, '.') === false) {
            $method = 'sortBy' . Str::studly($string);
            if (method_exists($this, $method)) {
                return $this->{$method}();
            }
            return DB::raw($this->getTable() . '.' . $string);
        }

        $parts = explode('.', $string);
        $first = array_shift($parts);
        $relationship = $this->{Str::camel($first)}();
        return $relationship->getRelated()->getSortByExpression(implode('.', $parts));
    }


    public function scopeBuildFromRequest(Builder $builder)
    {
        if (request()->method() !== 'GET') {
            return $this;
        }

        $this->applyWiths($builder);

        //Only need to join if we want to filter/sort
        if (request()->query('filter') || request()->query('sort')) {
            $this->applyJoins($builder);
        }

        $this->applyFilters($builder);
        $this->applySorts($builder);

        if ($query = request()->query('query')) {
            if (method_exists($this, 'search')) {
                return static::search($query)->query(function ($builder) {
                    $builder->with(QueryHelpers::getWiths()->all());
                });
            } elseif (method_exists($this, 'scopeSearch')) {
                $builder->search($query);
            }
        }
    }

    public function doJoins(Builder $builder, $relationships = [])
    {
        if (!is_iterable($relationships) || count($relationships) == 0)
            return;

        foreach ($relationships as $relationship_name => $additional) {
            $relationship = $this->{Str::camel($relationship_name)}();
            if (get_class($relationship) == MorphTo::class)
                return;

            $related_model = $relationship->getRelated();
            $related_key = $related_model->getKeyName();
            $related_table = $relationship->getRelated()->getTable();

            //Already Joined
            foreach ((array)$builder->getQuery()->joins as $join) {
                if ($related_table == $join->table) {
                    return;
                }
            }

            if (is_a($relationship, BelongsTo::class)) {
                $builder->leftJoin($related_table, $this->getTable() . '.' . $relationship->getForeignKeyName(), $related_table . '.' . $this->getKeyName());
            } elseif (is_a($relationship, BelongsToMany::class)) {
                $int_table = $relationship->getTable();

                $column1 = $relationship->getForeignPivotKeyName();
                $column2 = $relationship->getRelatedPivotKeyName();
                $builder
                    ->leftJoin($int_table, $column1, $this->getTable() . '.' . $this->getKeyName())
                    ->leftJoin($related_table, $related_table . '.' . $related_key, $column2);

            } elseif (is_a($relationship, HasOneOrMany::class)) {
                $column1 = $relationship->getForeignKeyName();
                $builder->leftJoin($related_table, $related_table . '.' . $column1, $this->getTable() . '.' . $related_key);
            }


            //Recursive Call
            $relationship->getRelated()->doJoins($builder, $additional);
        }
    }


    protected function applyJoins(Builder $builder)
    {
        $columns = collect(QueryHelpers::getColumnsNeeded())
            ->filter(function ($column) {
                return Str::contains($column, '.');
            })
            ->map(function ($column) {
                $parts = explode('.', $column);
                unset($parts[count($parts) - 1]);
                return implode('.', $parts);
            })
            ->values()
            ->all();

        $this->doJoins($builder, QueryHelpers::dotToArray($columns));
        $builder->addSelect($this->getTable() . '.*');
    }

    protected function applySorts(Builder $builder)
    {
        foreach (QueryHelpers::getSorts() as $column => $dir) {
            $builder->orderBy($this->getSortByExpression($column), $dir);
        }
    }

    protected function applyFilters(Builder $builder)
    {
        foreach (QueryHelpers::getFilters() as $key => $query) {
            $column = $this->getSortByExpression($key);
            $operator = 'eq';
            if (is_array($query)) {
                $operator = array_keys($query)[0];
                $query = array_values($query)[0];
            }

            $query = $this->normalizeQueryString($key, $query);

            switch ($operator) {
                case 'eq':
                    $builder->where($column, $query);
                    break;
                case 'not':
                    $builder->where($column, '!=', $query);
                    break;
                case 'gt':
                    $builder->where($column, '>', $query);
                    break;
                case 'lt':
                    $builder->where($column, '<', $query);
                    break;
                case 'gte':
                    $builder->where($column, '>=', $query);
                    break;
                case 'lte':
                    $builder->where($column, '<=', $query);
                    break;
                case 'between':
                    list($start, $end) = array_pad(explode(',', $query), 2, null);
                    $builder->whereBetween($column, [$start, $end]);
                    break;
                case 'in':
                    $builder->whereIn($column, array_filter(explode(',', $query)));
                    break;
                case 'nin':
                    $builder->whereNotIn($column, array_filter(explode(',', $query)));
                    break;
                case 'contains':
                    $builder->where($column, 'like', "%$query%");
                    break;
                case 'date':
                    $column = DB::raw('DATE(' . (string)$column . ')');
                    $builder->where($column, '=', Carbon::parse($query)->toDateString());
                    break;
                case 'year':
                    $column = DB::raw('YEAR(' . (string)$column . ')');
                    $builder->where($column, '=', Carbon::parse($query)->year);
                    break;
                default:
                    abort(422, 'Invalid filter operator');
            }
        }
    }

    public function normalizeQueryString($key, $query)
    {
        if (!is_string($query)) {
            return $query;
        }

        if ($query === 'true') {
            return 1;
        }
        if ($query === 'false') {
            return 0;
        }

        // Handle Relationships
        if (strpos($key, '.') !== false) {
            $path = explode('.', $key);
            $key = array_pop($path);
            $cursor = $this;
            while ($relationship = array_shift($path)) {
                $methodName = Str::camel($relationship);
                if (!method_exists($cursor, $methodName)) {
                    return $query;
                }
                $cursor = $cursor->{$methodName}()->getRelated();
            }
            return $cursor->normalizeQueryString($key, $query);
        }

        $cast = Arr::get($this->getCasts(), $key);
        if (!$cast) {
            return $query;
        }

        list($castType, $params) = array_pad(explode(':', $cast), 2, null);
        switch ($castType) {
            case 'date':
            case 'datetime':
                $defaultDate = $castType === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s';
                return Carbon::parse($query)->format($params ?: $defaultDate);
            case 'bool':
            case 'boolean':
                return empty($query) ? 0 : 1;
        }

        return $query;
    }


    public function applyWiths(Builder $builder)
    {
        $withs = QueryHelpers::getWiths()->filter(function ($with) {
            return method_exists($this, $with);
        })->all();

        $withCounts = QueryHelpers::getWithCounts()->filter(function ($with) {
            return method_exists($this, $with);
        })->all();

        $builder->with($withs);
        $builder->withCount($withCounts);

        return $this;
    }
}
