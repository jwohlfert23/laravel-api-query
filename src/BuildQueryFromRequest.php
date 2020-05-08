<?php

namespace Jwohlfert23\LaravelApiQuery;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
            $this->getJoins($builder);
        }

        $this->applyFilters($builder);
        $this->applySorts($builder);

        if (method_exists($this, 'scopeSearch')) {
            if ($query = request()->input('query')) {
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


    protected function getJoins(Builder $builder)
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

            switch ($operator) {
                case 'eq':
                    $builder->where($column, $query);
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
                case 'date':
                    $builder->where($column, Carbon::parse($query)->toDateString());
                    break;
                case 'contains':
                    $builder->where($column, 'like', "%$query%");
                    break;
                default:
                    abort(422, 'Invalid filter operator');
            }
        }
    }


    public function applyWiths(Builder $builder)
    {
        foreach (QueryHelpers::getWiths() as $with) {
            if (method_exists($this, $with))
                $builder->with($with);
        }
        return $this;
    }
}
