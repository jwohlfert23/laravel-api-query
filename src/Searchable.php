<?php

namespace Jwohlfert23\LaravelApiQuery;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** @mixin Model */
trait Searchable
{
    public function scopeSearch(Builder $query, $search)
    {
        if (empty($search)) {
            return;
        }

        $this->makeSearchableJoins($query);

        $query->where(function ($q) use ($search) {
            $columns = $this->getSearchableColumns();
            $terms = $this->getSearchableShouldSplitTerms()
                ? array_filter(preg_split("/\s+/", $search))
                : [$search];

            foreach ($terms as $term) {
                foreach ($columns as $column) {
                    if (! str_contains($column, '.')) {
                        $column = $this->getTable().'.'.$column;
                    }
                    if (str_contains($column, 'raw::')) {
                        $column = str_replace('raw::', '', $column);
                        $column = DB::raw($column);
                    }
                    $q->orWhere($column, 'LIKE', "%{$term}%");
                }
            }
        });
    }

    protected function getSearchableColumns(): array
    {
        if (property_exists($this, 'searchable')) {
            $columns = $this->searchable;
            if (array_key_exists('columns', $this->searchable)) {
                $columns = $this->searchable['columns'];
            }
            // If it's assoc array, columns are the keys
            // This is to maintain compatibility with https://github.com/nicolaslopezj/searchable
            return isset($columns[0]) ? $columns : array_keys($columns);
        }

        return DB::connection()->getSchemaBuilder()->getColumnListing($this->table);
    }

    protected function getSearchableJoins(): array
    {
        return Arr::get($this->searchable, 'joins', []);
    }

    protected function getSearchableShouldSplitTerms(): bool
    {
        return Arr::get($this->searchable, 'split', false);
    }

    /**
     * Adds the join sql to the query
     **/
    protected function makeSearchableJoins(Builder $query): void
    {
        $joins = $this->getSearchableJoins();
        foreach ($joins as $table => $keys) {
            if (! collect($query->getQuery()->joins)->contains('table', $table)) {
                $query->leftJoin($table, $keys[0], '=', $keys[1]);
            }
        }
        if (count($joins) > 0) {
            $query
                ->select($this->getTable().'.*')
                ->groupBy($this->getTable().'.'.$this->getKeyName());
        }
    }
}
