<?php

namespace Tests;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Kirschbaum\PowerJoins\EloquentJoins;
use Models\Model;
use Orchestra\Testbench\TestCase;

class QueryBuilderTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        EloquentJoins::registerEloquentMacros();

        Schema::create('models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('bool');
            $table->date('date');
            $table->dateTime('datetime');
            $table->date('custom_date');
        });

        Schema::create('related_models', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('parent_id');
            $table->string('name');
            $table->boolean('bool');
            $table->date('date');
            $table->dateTime('datetime');
            $table->date('custom_date');
        });
    }

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
        $this->assertEquals('related_models', $query->joins[0]->table);
        $this->assertEquals('models.related_id', $query->joins[0]->wheres[0]['first']);
        $this->assertEquals('related_models.id', $query->joins[0]->wheres[0]['second']);

        // Sorts
        $this->assertCount(2, $query->orders);
        $this->assertEquals('models.name', (string) $query->orders[0]['column']);
        $this->assertEquals('desc', $query->orders[0]['direction']);
        $this->assertEquals('models.date', (string) $query->orders[1]['column']);
        $this->assertEquals('asc', $query->orders[1]['direction']);

        // Filters
        $this->assertCount(3, $query->wheres);
        $this->assertEquals('models.name', (string) $query->wheres[0]['column']);
        $this->assertEquals('jack', $query->wheres[0]['value']);
        $this->assertEquals('=', $query->wheres[0]['operator']);
        $this->assertEquals('related_models.id', (string) $query->wheres[1]['column']);
        $this->assertEquals('0', $query->wheres[1]['value']);
        $this->assertEquals('>', $query->wheres[1]['operator']);
        $this->assertEquals('related_models.name', (string) $query->wheres[2]['column']);
        $this->assertEquals('%my%', $query->wheres[2]['value']);
        $this->assertEquals('like', $query->wheres[2]['operator']);
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
        $this->assertEquals('models.name', $subquery->wheres[0]['column']);
        $this->assertEquals('models.id', $subquery->wheres[1]['column']);
    }

    public function testCasts()
    {
        $request = Request::create('http://test.com/api?filter[date]=Jan+23+2020&filter[related.datetime]=Jan+23+2020&filter[bool]=true&filter[custom_date]=01/01/2019');
        $this->instance('request', $request);

        /** @var Builder $builder */
        $query = Model::query()->buildFromRequest()->getQuery();

        $this->assertCount(1, $query->joins);
        $this->assertCount(4, $query->wheres);

        [$date, $datetime, $bool, $customDate] = $query->wheres;

        $this->assertEquals('models.date', $date['column']);
        $this->assertEquals('2020-01-23 00:00:00', $date['value']);

        $this->assertEquals('related_models.datetime', (string) $datetime['column']);
        $this->assertEquals('2020-01-23 00:00:00', $datetime['value']);

        $this->assertEquals('models.bool', $bool['column']);
        $this->assertEquals(1, $bool['value']);

        $this->assertEquals('models.custom_date', $customDate['column']);
        $this->assertEquals('2019-01', $customDate['value']);
    }

    public function testWithIsIgnoredIfDoesNotExists()
    {
        $request = Request::create('http://test.com/api?with=related.doesnotexist,related_model');
        $this->instance('request', $request);

        /** @var Builder $builder */
        $this->assertCount(0, Model::query()->buildFromRequest()->getEagerLoads());
    }

    public function testWithIsSetWhenExists()
    {
        $request = Request::create('http://test.com/api?with=related,related_model');
        $this->instance('request', $request);
        $this->assertCount(1, Model::query()->buildFromRequest()->getEagerLoads());
        $this->assertEquals(['related'], array_keys(Model::query()->buildFromRequest()->getEagerLoads()));
    }

    public function testIgnoresInvalidColumns()
    {
        $request = Request::create('http://test.com/api?filter[jack+smith]=jack');
        $this->instance('request', $request);
        $this->assertCount(0, Model::query()->buildFromRequest()->getQuery()->wheres);
    }

    public function testDoesNotAddTableNameIfColumnDoesNotExist()
    {
        $request = Request::create('http://test.com/api?filter[jack]=wohlfert');
        $this->instance('request', $request);
        $this->assertEquals('jack', Model::query()->buildFromRequest()->getQuery()->wheres[0]['column']);
    }

    public function testDefaultOperatorIsGuessedAsArrayWhenComma()
    {
        $request = Request::create('http://test.com/api?filter[name]=jack,collin');
        $this->instance('request', $request);
        $this->assertEquals(['jack', 'collin'], Model::query()->buildFromRequest()->getQuery()->wheres[0]['values']);
        $this->assertEquals('In', Model::query()->buildFromRequest()->getQuery()->wheres[0]['type']);
    }

    public function testDefaultOperatorIsGuessedAsStringWhenNoComma()
    {
        $request = Request::create('http://test.com/api?filter[name]=jack');
        $this->instance('request', $request);
        $this->assertEquals('jack', Model::query()->buildFromRequest()->getQuery()->wheres[0]['value']);
        $this->assertEquals('Basic', Model::query()->buildFromRequest()->getQuery()->wheres[0]['type']);
    }
}
