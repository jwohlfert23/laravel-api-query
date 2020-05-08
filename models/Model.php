<?php namespace Jwohlfert23\LaravelApiQuery\Models;

use Jwohlfert23\LaravelApiQuery\BuildQueryFromRequest;

class Model extends \Illuminate\Database\Eloquent\Model
{
    use BuildQueryFromRequest;

    public function related()
    {
        return $this->belongsTo(RelatedModel::class);
    }
}
