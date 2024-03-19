<?php

namespace Models;

use Jwohlfert23\LaravelApiQuery\BuildQueryFromRequest;
use Jwohlfert23\LaravelApiQuery\Searchable;

class Model extends \Illuminate\Database\Eloquent\Model
{
    use BuildQueryFromRequest, Searchable;

    protected $casts = [
        'bool' => 'bool',
        'date' => 'date',
        'custom_date' => 'date:Y-m',
    ];

    protected $searchable = [
        'name',
        'id',
    ];

    public function related()
    {
        return $this->belongsTo(RelatedModel::class);
    }
}
