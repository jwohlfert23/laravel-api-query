<?php namespace Models;

use Jwohlfert23\LaravelApiQuery\BuildQueryFromRequest;
use Jwohlfert23\LaravelApiQuery\Searchable;

class Model extends \Illuminate\Database\Eloquent\Model
{
    use BuildQueryFromRequest, Searchable;

    protected $searchable = [
        'columns' => [
            'name' => 10,
            'id' => 10
        ]
    ];

    public function related()
    {
        return $this->belongsTo(RelatedModel::class);
    }
}
