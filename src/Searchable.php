<?php

namespace Jwohlfert23\LaravelApiQuery;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** @mixin Model */
trait Searchable
{
    public function scopeSearch(Builder $builder, $query)
    {
        if (empty($query)) {
            return;
        }

        ApiQueryBuilder::performJoinsFromColumns($builder, $columns = $this->getSearchableColumns());

        $builder->where(function ($q) use ($query, $columns) {
            $terms = $this->getSearchableShouldSplitTerms()
                ? array_filter(preg_split("/\s+/", $query))
                : [$query];

            foreach ($terms as $term) {
                foreach ($columns as $column) {
                    $parts = explode('.', $column);
                    $column = array_pop($parts);
                    $model = $this;
                    while ($relationship = array_shift($parts)) {
                        $model = $model->$relationship()->getRelated();
                    }
                    $q->orWhere($model->getTable().'.'.$column, 'LIKE', "%$term%");
                }
            }
        });
    }

    protected function getSearchableColumns(): array
    {
        if (property_exists($this, 'searchable')) {
            return $this->searchable;
        }

        return DB::connection()->getSchemaBuilder()->getColumnListing($this->table);
    }

    protected function getSearchableShouldSplitTerms(): bool
    {
        return false;
    }
}
