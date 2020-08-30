<?php namespace Jwohlfert23\LaravelApiQuery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @mixin Model */
trait Searchable
{
    /**
     * @param Builder $query
     * @param $search
     * @return void
     */
    public function scopeSearch($query, $search)
    {
        if (empty($search))
            return;

        $this->makeSearchableJoins($query);

        $query->where(function ($q) use ($search) {

            $columns = $this->getSearchableColumns();
            $terms = $this->getSearchableShouldSplitTerms()
                ? array_filter(preg_split("/\s+/", $search))
                : [$search];

            foreach ($terms as $term) {
                foreach ($columns as $column) {
                    if (strpos($column, '.') === FALSE) {
                        $column = $this->getTable() . '.' . $column;
                    }
                    if (strpos($column, 'raw::') !== FALSE) {
                        $column = str_replace('raw::', '', $column);
                        $column = DB::raw($column);
                    }
                    $q->orWhere($column, 'LIKE', "%{$term}%");
                }
            }
        });
    }

    /**
     * Returns the search columns
     *
     * @return array
     */
    protected function getSearchableColumns()
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

    /**
     * Returns the tables that has to join
     *
     * @return array
     */
    protected function getSearchableJoins()
    {
        return Arr::get($this->searchable, 'joins', []);
    }


    /**
     * Returns the tables that has to join
     *
     * @return array
     */
    protected function getSearchableShouldSplitTerms()
    {
        return Arr::get($this->searchable, 'split', true);
    }


    /**
     * Adds the join sql to the query
     *
     * @param Builder $query
     */
    protected function makeSearchableJoins(&$query)
    {
        $joins = $this->getSearchableJoins();
        foreach ($joins as $table => $keys) {
            if (!collect($query->getQuery()->joins)->contains('table', $table)) {
                $query->leftJoin($table, $keys[0], '=', $keys[1]);
            }
        }
        if (count($joins) > 0) {
            $query
                ->select($this->getTable() . '.*')
                ->groupBy($this->getTable() . '.' . $this->getKeyName());
        }
    }
}
