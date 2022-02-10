<?php namespace Jwohlfert23\LaravelApiQuery;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\ParameterBag;
use Illuminate\Support\Collection;

/**
 * Class ApiQueryBuilder
 * @package Jwohlfert23\LaravelApiQuery
 *
 */
class ApiQueryBuilder
{
    /** @var Builder */
    protected $builder;

    /** @var ParameterBag */
    protected $input;

    public function __construct(Builder $builder, ParameterBag $input)
    {
        $this->builder = $builder;
        $this->input = $input;

        Collection::macro('filterValidColumns', function () {
            return $this->filter(function ($value, $column) {
                if (is_string(! $column)) {
                    return false;
                }
                return preg_match('/^[a-z]+[a-z0-9._]+$/i', $column);
            });
        });
    }

    public static function applyInputToBuilder(Builder $builder, ParameterBag $input)
    {
        $obj = new self($builder, $input);
        return $obj->apply();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Model
     */
    protected function getModel()
    {
        return $this->builder->getModel();
    }

    protected function getTable(): string
    {
        return $this->getModel()->getTable();
    }

    public function apply()
    {
        $this->applyWiths();

        // Only need to join if we want to filter/sort
        if ($this->input->has('filter') || $this->input->has('sort')) {
            $this->applyJoins();
        }

        $this->applyFilters();
        $this->applySorts();
        return $this;
    }


    protected function applyJoins()
    {
        collect($this->getColumnsNeeded())
            ->filter(function ($column) {
                return Str::contains($column, '.');
            })
            ->map(function ($column) {
                $parts = explode('.', $column);
                unset($parts[count($parts) - 1]);
                return implode('.', $parts);
            })
            ->each(function ($columns) {
                $this->builder->leftJoinRelationship(Str::camel($columns));
            });
    }

    protected function applySorts()
    {
        foreach ($this->getSorts() as $column => $dir) {
            $this->builder->orderBy(static::getSortByExpression($this->getModel(), $column), $dir);
        }
    }

    protected function applyFilters()
    {
        foreach ($this->getFilters() as $key => $queries) {
            $column = static::getSortByExpression($this->getModel(), $key);
            if (! is_array($queries)) {
                $defaultOperator = Str::contains($queries, ',') ? 'in' : 'eq';
                $queries = [$defaultOperator => $queries];
            }

            foreach ($queries as $operator => $query) {
                $query = static::normalizeQueryString($this->getModel(), $key, $operator, $query);

                switch ($operator) {
                    case 'eq':
                        $this->builder->where($column, $query);
                        break;
                    case 'not':
                        $this->builder->where($column, '!=', $query);
                        break;
                    case 'gt':
                        $this->builder->where($column, '>', $query);
                        break;
                    case 'lt':
                        $this->builder->where($column, '<', $query);
                        break;
                    case 'gte':
                        $this->builder->where($column, '>=', $query);
                        break;
                    case 'lte':
                        $this->builder->where($column, '<=', $query);
                        break;
                    case 'contains':
                        $this->builder->where($column, 'like', "%$query%");
                        break;
                    case 'descendant':
                        $parts = explode('.', $column);
                        if (count($parts) === 2) {
                            if ($node = DB::table($table = $parts[0])->where($parts[1], $query)->first()) {
                                $this->builder->whereBetween($table.'._lft', [
                                    $node->_lft,
                                    $node->_rgt
                                ]);
                            }
                        }
                        break;
                    case 'date':
                        $dt = Carbon::parse($query)->tz(config('app.timezone'));
                        $this->builder->whereBetween($column, [
                            $dt->startOfDay()->toDateTimeString(),
                            $dt->endOfDay()->toDateTimeString(),
                        ]);
                        break;
                    case 'year':
                        $column = DB::raw('YEAR('.(string)$column.')');
                        $this->builder->where($column, '=', Carbon::parse($query)->tz(config('app.timezone'))->year);
                        break;
                    case 'null':
                        $method = ! empty($query) ? 'whereNull' : 'whereNotNull';
                        $this->builder->$method($column);
                        break;
                    case 'notnull':
                        $method = ! empty($query) ? 'whereNotNull' : 'whereNull';
                        $this->builder->$method($column);
                        break;
                    // Expects Array here and below
                    case 'between':
                        list($start, $end) = array_pad($query, 2, null);
                        if ($end && $this->getModel()->attributeIsDate($key)) {
                            $end = Carbon::parse($end)->tz(config('app.timezone'))->endOfDay()->toDateTimeString();
                        }
                        $this->builder->whereBetween($column, [$start, $end]);
                        break;
                    case 'in':
                        $this->builder->whereIn($column, array_filter($query));
                        break;
                    case 'nin':
                        $this->builder->whereNotIn($column, array_filter($query));
                        break;
                    default:
                        abort(422, 'Invalid filter operator');
                }
            }
        }
    }

    /**
     * @param $model Model
     * @param $key
     * @param $operator
     * @param $query
     * @return mixed
     */
    public static function normalizeQueryString($model, $key, $operator, $query)
    {
        $expectsArray = in_array($operator, ['in', 'nin', 'between']);
        if ($expectsArray) {
            return array_map(function ($part) use ($model, $key) {
                return static::normalizeQueryStringSingular($model, $key, $part);
            }, explode(',', $query));
        }
        return static::normalizeQueryStringSingular($model, $key, $query);
    }

    /**
     * @param $model Model
     * @param $key
     * @param $query
     * @return mixed
     */
    public static function normalizeQueryStringSingular($model, $key, $query)
    {
        if (! is_string($query)) {
            return $query;
        }

        if ($query === 'null') {
            return null;
        }
        if ($query === 'true') {
            return 1;
        }
        if ($query === 'false') {
            return 0;
        }

        // Handle Relationships
        if (Str::contains($key, '.')) {
            $path = explode('.', $key);
            $key = array_pop($path);
            $cursor = $model;
            while ($relationship = array_shift($path)) {
                $methodName = Str::camel($relationship);
                if (! method_exists($cursor, $methodName)) {
                    return $query;
                }
                $cursor = $cursor->{$methodName}()->getRelated();
            }
            return static::normalizeQueryStringSingular($cursor, $key, $query);
        }

        $cast = Arr::get($model->getCasts(), $key);
        if ($model->attributeIsDate($key)) {
            $cast = 'datetime';
        }
        if (! $cast) {
            return $query;
        }

        list($castType, $params) = array_pad(explode(':', $cast), 2, null);
        switch ($castType) {
            case 'date':
            case 'datetime':
                $defaultDate = $castType === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s';
                return Carbon::parse($query)->tz(config('app.timezone'))->format($params ?: $defaultDate);
            case 'bool':
            case 'boolean':
                return empty($query) ? 0 : 1;
        }

        return $query;
    }


    public function applyWiths()
    {
        $this->builder->with($this->getWiths()->all());
        $this->builder->withCount($this->getWithCounts()->all());

        return $this;
    }

    public function getWiths(): Collection
    {
        return collect(explode(',', $this->input->get('with', '')))
            ->when($key = config('fractal.auto_includes.request_key'), function ($collection) use ($key) {
                return $collection->concat(explode(',', $this->input->get($key, '')));
            })
            ->map(function ($i) {
                return Str::camel(trim($i));
            })
            ->filter()
            ->unique()
            ->filter(function ($dotRelation) {
                $cursor = $this->getModel();
                foreach (explode('.', $dotRelation) as $relation) {
                    if (! method_exists($cursor, $relation)) {
                        return false;
                    }
                    $cursor = $cursor->{$relation}()->getRelated();
                }
                return true;
            })
            ->values();
    }

    public function getWithCounts(): Collection
    {
        return collect(explode(',', $this->input->get('with_count')))
            ->map(function ($i) {
                return Str::camel(trim($i));
            })
            ->filter()
            ->unique()
            ->filter(function ($with) {
                return method_exists($this->getModel(), $with);
            })
            ->values();
    }

    public function getFilters()
    {
        if (! $this->input->has('filter')) {
            return [];
        }
        return collect(Arr::wrap($this->input->all('filter')))
            ->filterValidColumns()
            ->all();
    }

    public function getSorts()
    {
        return collect(explode(',', $this->input->get('sort')))
            ->filter()
            ->mapWithKeys(function ($column) {
                $dir = $column[0] === '-' ? 'desc' : 'asc';
                return [
                    ltrim($column, '-') => $dir
                ];
            })
            ->filterValidColumns()
            ->all();
    }

    public function getColumnsNeeded()
    {
        return array_merge(array_keys($this->getSorts()), array_keys($this->getFilters()));
    }

    public static function getColumnsForTable($table)
    {
        $store = Cache::getStore() instanceof \Illuminate\Cache\TaggableStore
            ? Cache::tags('laravel-api-query')
            : Cache::store('array');

        return $store->remember("columns.$table", now()->addWeek(), function () use ($table) {
            return Schema::getColumnListing($table);
        });
    }

    public static function isColumnForTable($table, $column): bool
    {
        return in_array($column, static::getColumnsForTable($table));
    }

    /**
     * @param $model Model
     * @param $string
     * @return \Illuminate\Database\Query\Expression
     */
    public function getSortByExpression($model, $string)
    {
        if (strpos($string, '.') === false) {
            $method = 'sortBy'.Str::studly($string);
            if (method_exists($model, $method)) {
                return $model->{$method}();
            }
            if ($this->isColumnForTable($table = $model->getTable(), $string)) {
                return DB::raw("$table.$string");
            }
            return DB::raw($string);
        }

        $parts = explode('.', $string);
        $first = array_shift($parts);
        $relationship = $model->{Str::camel($first)}();
        return static::getSortByExpression($relationship->getRelated(), implode('.', $parts));
    }
}
