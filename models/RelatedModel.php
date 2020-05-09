<?php namespace Models;

use Jwohlfert23\LaravelApiQuery\BuildQueryFromRequest;

class RelatedModel extends \Illuminate\Database\Eloquent\Model
{
    use BuildQueryFromRequest;

    protected $casts = [
        'bool' => 'bool',
        'datetime' => 'datetime',
        'custom_date' => 'date:Y-m'
    ];
}
