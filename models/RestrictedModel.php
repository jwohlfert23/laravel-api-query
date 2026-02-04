<?php

namespace Models;

use Jwohlfert23\LaravelApiQuery\BuildQueryFromRequest;

class RestrictedModel extends \Illuminate\Database\Eloquent\Model
{
    use BuildQueryFromRequest;

    protected $table = 'models';

    protected $casts = [
        'bool' => 'bool',
        'date' => 'date',
        'custom_date' => 'date:Y-m',
    ];

    public function queryable(): array
    {
        return ['name', 'date'];
    }

    public function sortable(): array
    {
        return ['name', 'created_at'];
    }

    public function filterable(): array
    {
        return ['name', 'bool'];
    }

    public function related()
    {
        return $this->belongsTo(RelatedModel::class);
    }
}
