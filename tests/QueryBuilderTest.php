<?php namespace Tests;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Models\Model;
use Orchestra\Testbench\TestCase;

class QueryBuilderTest extends TestCase
{

    public function testAll()
    {
        $request = Request::create('http://test.com/api?with=related&sort=-name,date&filter[name]=jack&filter[related.id][gt]=0&filter[related.name][contains]=my');
        $this->instance('request', $request);

        /** @var \Illuminate\Database\Eloquent\Builder $builder */
        $builder = Model::query()->buildFromRequest();
        /** @var Builder $query */
        $query = $builder->getQuery();

        // Withs
        $this->assertCount(1, $builder->getEagerLoads());

        // Joins
        $this->assertCount(1, $query->joins);
        $this->assertEquals($query->joins[0]->table, 'related_models');
        $this->assertEquals($query->joins[0]->wheres[0]['first'], 'models.related_id');
        $this->assertEquals($query->joins[0]->wheres[0]['second'], 'related_models.id');

        // Sorts
        $this->assertCount(2, $query->orders);
        $this->assertEquals((string)$query->orders[0]['column'], 'models.name');
        $this->assertEquals($query->orders[0]['direction'], 'desc');
        $this->assertEquals((string)$query->orders[1]['column'], 'models.date');
        $this->assertEquals($query->orders[1]['direction'], 'asc');

        // Filters
        $this->assertCount(3, $query->wheres);
        $this->assertEquals((string)$query->wheres[0]['column'], 'models.name');
        $this->assertEquals($query->wheres[0]['value'], 'jack');
        $this->assertEquals($query->wheres[0]['operator'], '=');
        $this->assertEquals((string)$query->wheres[1]['column'], 'related_models.id');
        $this->assertEquals($query->wheres[1]['value'], '0');
        $this->assertEquals($query->wheres[1]['operator'], '>');
        $this->assertEquals((string)$query->wheres[2]['column'], 'related_models.name');
        $this->assertEquals($query->wheres[2]['value'], '%my%');
        $this->assertEquals($query->wheres[2]['operator'], 'like');
    }

    public function testQuery()
    {
        $request = Request::create('http://test.com/api?with=related&query=jack');
        $this->instance('request', $request);

        /** @var \Illuminate\Database\Eloquent\Builder $builder */
        $builder = Model::query()->buildFromRequest();
        /** @var Builder $query */
        $query = $builder->getQuery();

        // 2 columns to search by
        $subquery = $query->wheres[0]['query'];
        $this->assertCount(2, $subquery->wheres);
        $this->assertEquals($subquery->wheres[0]['column'], 'models.name');
        $this->assertEquals($subquery->wheres[1]['column'], 'models.id');
    }

    public function testCasts()
    {
        $request = Request::create('http://test.com/api?filter[date]=Jan+23+2020&filter[related.datetime]=Jan+23+2020&filter[bool]=true&filter[custom_date]=01/01/2019');
        $this->instance('request', $request);

        /** @var Builder $builder */
        $query = Model::query()->buildFromRequest()->getQuery();

        $this->assertCount(1, $query->joins);
        $this->assertCount(4, $query->wheres);

        list($date, $datetime, $bool, $customDate) = $query->wheres;

        $this->assertEquals($date['column'], 'models.date');
        $this->assertEquals($date['value'], '2020-01-23');

        $this->assertEquals((string)$datetime['column'], 'related_models.datetime');
        $this->assertEquals($datetime['value'], '2020-01-23 00:00:00');

        $this->assertEquals($bool['column'], 'models.bool');
        $this->assertEquals($bool['value'], 1);

        $this->assertEquals($customDate['column'], 'models.custom_date');
        $this->assertEquals($customDate['value'], '2019-01');
    }
}
