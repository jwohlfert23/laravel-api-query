<?php

namespace Jwohlfert23\LaravelApiQuery;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\ParameterBag;

class ApiQueryBuilder
{
    public function __construct(protected Builder $builder, protected ParameterBag $input) {}

    public static function applyInputToBuilder(Builder $builder, ParameterBag $input): ApiQueryBuilder
    {
        $obj = new self($builder, $input);

        return $obj->apply();
    }

    public static function performJoinsFromColumns(Builder $builder, array $columns): void
    {
        $model = $builder->getModel();

        collect($columns)
            ->filter(function (string $column) {
                return Str::contains($column, '.');
            })
            ->map(function (string $column) {
                $parts = explode('.', $column);
                array_pop($parts);

                return implode('.', $parts);
            })
            ->filter(function (string $relationPath) use ($model) {
                $cursor = $model;
                foreach (explode('.', $relationPath) as $segment) {
                    $method = Str::camel($segment);
                    if (! method_exists($cursor, $method)) {
                        return false;
                    }
                    $cursor = $cursor->{$method}()->getRelated();
                }

                return true;
            })
            ->each(function (string $column) use ($builder) {
                $builder->leftJoinRelationship(Str::camel($column));
            });
    }

    protected function getModel(): Model
    {
        return $this->builder->getModel();
    }

    protected function getTable(): string
    {
        return $this->getModel()->getTable();
    }

    public function apply(): self
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

    protected function applyJoins(): void
    {
        self::performJoinsFromColumns($this->builder, $this->getColumnsNeeded());
    }

    protected function applySorts(): void
    {
        foreach ($this->getSorts() as $column => $dir) {
            $resolved = $this->resolveColumn($this->getModel(), $column, 'sort');
            if ($resolved === null) {
                continue;
            }
            $this->builder->orderBy($resolved instanceof Closure ? $resolved() : $resolved, $dir);
        }
    }

    protected function applyFilters(): void
    {
        foreach ($this->getFilters() as $key => $queries) {
            $resolved = $this->resolveColumn($this->getModel(), $key, 'filter');
            if ($resolved === null) {
                continue;
            }
            $column = $resolved instanceof Closure ? $resolved() : $resolved;

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
                    case 'lt':
                    case 'gte':
                    case 'lte':
                        if ($this->getModel()->attributeIsDate($key)) {
                            $query = Carbon::parse($query)->tz(config('app.timezone'))->toDateTimeString();
                        }
                        $op = match ($operator) {
                            'gt' => '>',
                            'lt' => '<',
                            'gte' => '>=',
                            'lte' => '<=',
                        };
                        $this->builder->where($column, $op, $query);
                        break;
                    case 'contains':
                        $this->builder->where($column, 'like', "%$query%");
                        break;
                    case 'sw':
                        $this->builder->where($column, 'like', "$query%");
                        break;
                    case 'ew':
                        $this->builder->where($column, 'like', "%$query");
                        break;
                    case 'descendant':
                        $parts = explode('.', $column);
                        if (count($parts) === 2) {
                            if ($node = DB::table($table = $parts[0])->where($parts[1], $query)->first()) {
                                $this->builder->whereBetween($table.'._lft', [
                                    $node->_lft,
                                    $node->_rgt,
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
                        $column = DB::raw('YEAR('.(string) $column.')');
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
                        [$start, $end] = array_pad($query, 2, null);
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

    public static function normalizeQueryString(Model $model, $key, $operator, $query): mixed
    {
        $expectsArray = in_array($operator, ['in', 'nin', 'between']);
        if ($expectsArray) {
            return array_map(function ($part) use ($model, $key) {
                return static::normalizeQueryStringSingular($model, $key, $part);
            }, explode(',', $query));
        }

        return static::normalizeQueryStringSingular($model, $key, $query);
    }

    public static function normalizeQueryStringSingular(Model $model, $key, $query): mixed
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

        [$castType, $params] = array_pad(explode(':', $cast), 2, null);
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

    public function applyWiths(): self
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
                    if ($cursor->hasAttributeMutator(Str::snake($relation))) {
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
        return collect(explode(',', $this->input->get('with_count', '')))
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

    public function getFilters(): array
    {
        if (! $this->input->has('filter')) {
            return [];
        }

        return collect(Arr::wrap($this->input->all('filter')))
            ->filterValidColumns()
            ->all();
    }

    public function getSorts(): array
    {
        return collect(explode(',', $this->input->get('sort', '')))
            ->filter()
            ->mapWithKeys(function ($column) {
                $dir = $column[0] === '-' ? 'desc' : 'asc';

                return [
                    ltrim($column, '-') => $dir,
                ];
            })
            ->filterValidColumns()
            ->all();
    }

    public function getColumnsNeeded(): array
    {
        return array_merge(array_keys($this->getSorts()), array_keys($this->getFilters()));
    }

    public static function getColumnsForTable($table): array
    {
        // Using array in-memory cache which should be cleared on migration/deployment
        return Cache::store('array')->remember("columns.$table", now()->addWeek(), function () use ($table) {
            return Schema::getColumnListing($table);
        });
    }

    public static function isColumnForTable($table, $column): bool
    {
        return in_array($column, static::getColumnsForTable($table));
    }

    protected function isSelectAlias(string $column): bool
    {
        $grammar = $this->builder->getQuery()->getGrammar();

        foreach ($this->builder->getQuery()->columns ?? [] as $select) {
            $value = $select instanceof \Illuminate\Database\Query\Expression
                ? $select->getValue($grammar)
                : $select;

            if (! is_string($value)) {
                continue;
            }

            if (preg_match('/\bas\s+[`"\[]?'.preg_quote($column, '/').'\s*[`"\]]?\s*$/i', $value)) {
                return true;
            }
        }

        return false;
    }

    protected function getAllowedColumns(Model $model, string $type): ?array
    {
        $method = $type === 'sort' ? 'sortable' : 'filterable';
        if (method_exists($model, $method)) {
            return $model->{$method}();
        }
        if (method_exists($model, 'queryable')) {
            return $model->queryable();
        }

        return null;
    }

    public function resolveColumn(Model $model, string $column, string $type): string|Closure|null
    {
        if (strpos($column, '.') === false) {
            // Check sortBy{Column}() on model (backward compat, sort only)
            if ($type === 'sort') {
                $method = 'sortBy'.Str::studly($column);
                if (method_exists($model, $method)) {
                    return $model->{$method}();
                }
            }

            // Check allowlist
            $allowed = $this->getAllowedColumns($model, $type);
            if ($allowed !== null) {
                // Normalize allowlist: string values and key => Closure entries
                foreach ($allowed as $key => $value) {
                    if ($value instanceof Closure) {
                        if ($key === $column) {
                            return $value;
                        }
                    } elseif ($value === $column) {
                        if ($this->isColumnForTable($model->getTable(), $column)) {
                            return $model->getTable().'.'.$column;
                        }

                        return $column;
                    }
                }

                return null;
            }

            // No allowlist — fall back to schema check, then select aliases
            if ($this->isColumnForTable($table = $model->getTable(), $column)) {
                return "$table.$column";
            }

            if ($this->isSelectAlias($column)) {
                return $column;
            }

            return null;
        }

        // Dot-notation: validate relationship exists, traverse to related model, recurse
        $parts = explode('.', $column);
        $first = array_shift($parts);
        $method = Str::camel($first);

        if (! method_exists($model, $method)) {
            return null;
        }

        $relationship = $model->{$method}();

        return $this->resolveColumn($relationship->getRelated(), implode('.', $parts), $type);
    }
}
