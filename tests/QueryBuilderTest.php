<?php

namespace Tests;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Jwohlfert23\LaravelApiQuery\LaravelApiQueryServiceProvider;
use Kirschbaum\PowerJoins\EloquentJoins;
use Models\Model;
use Models\RestrictedModel;
use Orchestra\Testbench\TestCase;

class QueryBuilderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelApiQueryServiceProvider::class,
        ];
    }

    protected function setUp(): void
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

    public function test_all()
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

    public function test_query()
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

    public function test_casts()
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

    public function test_with_is_ignored_if_does_not_exists()
    {
        $request = Request::create('http://test.com/api?with=related.doesnotexist,related_model');
        $this->instance('request', $request);

        /** @var Builder $builder */
        $this->assertCount(0, Model::query()->buildFromRequest()->getEagerLoads());
    }

    public function test_with_is_set_when_exists()
    {
        $request = Request::create('http://test.com/api?with=related,related_model');
        $this->instance('request', $request);
        $this->assertCount(1, Model::query()->buildFromRequest()->getEagerLoads());
        $this->assertEquals(['related'], array_keys(Model::query()->buildFromRequest()->getEagerLoads()));
    }

    public function test_ignores_invalid_columns()
    {
        $request = Request::create('http://test.com/api?filter[jack+smith]=jack');
        $this->instance('request', $request);
        $this->assertCount(0, Model::query()->buildFromRequest()->getQuery()->wheres);
    }

    public function test_does_not_add_table_name_if_column_does_not_exist()
    {
        $request = Request::create('http://test.com/api?filter[jack]=wohlfert');
        $this->instance('request', $request);
        $this->assertCount(0, Model::query()->buildFromRequest()->getQuery()->wheres);
    }

    public function test_default_operator_is_guessed_as_array_when_comma()
    {
        $request = Request::create('http://test.com/api?filter[name]=jack,collin');
        $this->instance('request', $request);
        $this->assertEquals(['jack', 'collin'], Model::query()->buildFromRequest()->getQuery()->wheres[0]['values']);
        $this->assertEquals('In', Model::query()->buildFromRequest()->getQuery()->wheres[0]['type']);
    }

    public function test_default_operator_is_guessed_as_string_when_no_comma()
    {
        $request = Request::create('http://test.com/api?filter[name]=jack');
        $this->instance('request', $request);
        $this->assertEquals('jack', Model::query()->buildFromRequest()->getQuery()->wheres[0]['value']);
        $this->assertEquals('Basic', Model::query()->buildFromRequest()->getQuery()->wheres[0]['type']);
    }

    public function test_invalid_sort_column_is_silently_ignored()
    {
        $request = Request::create('http://test.com/api?sort=nonexistent_column');
        $this->instance('request', $request);
        $query = Model::query()->buildFromRequest()->getQuery();
        $this->assertEmpty($query->orders);
    }

    public function test_invalid_relationship_in_sort_is_silently_ignored()
    {
        $request = Request::create('http://test.com/api?sort=fakrelation.name');
        $this->instance('request', $request);
        $query = Model::query()->buildFromRequest()->getQuery();
        $this->assertEmpty($query->orders);
    }

    public function test_invalid_relationship_in_filter_is_silently_ignored()
    {
        $request = Request::create('http://test.com/api?filter[fakerelation.name]=test');
        $this->instance('request', $request);
        $query = Model::query()->buildFromRequest()->getQuery();
        $this->assertCount(0, $query->wheres);
    }

    public function test_queryable_restricts_both_sort_and_filter()
    {
        // RestrictedModel has sortable() and filterable(), so queryable() is the fallback.
        // To test queryable alone, use a model that only defines queryable().
        // Here we test that restricted model's sortable/filterable work correctly.
        // 'bool' is in filterable but not in sortable.
        $request = Request::create('http://test.com/api?sort=bool&filter[bool]=true');
        $this->instance('request', $request);
        $query = RestrictedModel::query()->buildFromRequest()->getQuery();

        // Sort on 'bool' should be ignored (not in sortable)
        $this->assertEmpty($query->orders);
        // Filter on 'bool' should work (in filterable)
        $this->assertCount(1, $query->wheres);
    }

    public function test_sortable_overrides_queryable_for_sorts()
    {
        // RestrictedModel: sortable=['name', 'created_at'], queryable=['name', 'date']
        // 'date' is in queryable but not sortable — sort should be ignored
        $request = Request::create('http://test.com/api?sort=date');
        $this->instance('request', $request);
        $query = RestrictedModel::query()->buildFromRequest()->getQuery();
        $this->assertEmpty($query->orders);

        // 'name' is in sortable — should work
        $request = Request::create('http://test.com/api?sort=name');
        $this->instance('request', $request);
        $query = RestrictedModel::query()->buildFromRequest()->getQuery();
        $this->assertCount(1, $query->orders);
        $this->assertEquals('models.name', (string) $query->orders[0]['column']);
    }

    public function test_filterable_overrides_queryable_for_filters()
    {
        // RestrictedModel: filterable=['name', 'bool'], queryable=['name', 'date']
        // 'date' is in queryable but not filterable — filter should be ignored
        $request = Request::create('http://test.com/api?filter[date]=2020-01-01');
        $this->instance('request', $request);
        $query = RestrictedModel::query()->buildFromRequest()->getQuery();
        $this->assertCount(0, $query->wheres);

        // 'name' is in filterable — should work
        $request = Request::create('http://test.com/api?filter[name]=jack');
        $this->instance('request', $request);
        $query = RestrictedModel::query()->buildFromRequest()->getQuery();
        $this->assertCount(1, $query->wheres);
        $this->assertEquals('models.name', (string) $query->wheres[0]['column']);
    }

    public function test_no_allowlist_allows_all_columns()
    {
        // Model has no queryable/sortable/filterable — all schema columns are allowed
        $request = Request::create('http://test.com/api?sort=name,date,bool&filter[name]=jack&filter[bool]=true');
        $this->instance('request', $request);
        $query = Model::query()->buildFromRequest()->getQuery();
        $this->assertCount(3, $query->orders);
        $this->assertCount(2, $query->wheres);
    }

    public function test_closure_sort_expression()
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            use \Jwohlfert23\LaravelApiQuery\BuildQueryFromRequest;

            protected $table = 'models';

            public function sortable(): array
            {
                return [
                    'name',
                    'custom_score' => fn () => \Illuminate\Support\Facades\DB::raw('(SELECT 1)'),
                ];
            }
        };

        $request = Request::create('http://test.com/api?sort=custom_score');
        $this->instance('request', $request);

        $builder = $model->newQuery();
        \Jwohlfert23\LaravelApiQuery\ApiQueryBuilder::applyInputToBuilder($builder, $request->query);
        $query = $builder->getQuery();

        $this->assertCount(1, $query->orders);
        $this->assertEquals('(SELECT 1)', $query->orders[0]['column']->getValue($builder->getQuery()->getGrammar()));
        $this->assertEquals('asc', $query->orders[0]['direction']);
    }

    // ── Filter operator tests ───────────────────────────────────────

    public function test_filter_not_operator()
    {
        $request = Request::create('http://test.com/api?filter[name][not]=jack');
        $this->instance('request', $request);
        $where = Model::query()->buildFromRequest()->getQuery()->wheres[0];
        $this->assertEquals('models.name', $where['column']);
        $this->assertEquals('!=', $where['operator']);
        $this->assertEquals('jack', $where['value']);
    }

    public function test_filter_lt_operator()
    {
        $request = Request::create('http://test.com/api?filter[id][lt]=10');
        $this->instance('request', $request);
        $where = Model::query()->buildFromRequest()->getQuery()->wheres[0];
        $this->assertEquals('<', $where['operator']);
        $this->assertEquals('10', $where['value']);
    }

    public function test_filter_gte_lte_operators()
    {
        $request = Request::create('http://test.com/api?filter[id][gte]=5&filter[id][lte]=10');
        $this->instance('request', $request);
        $wheres = Model::query()->buildFromRequest()->getQuery()->wheres;
        $this->assertCount(2, $wheres);
        $this->assertEquals('>=', $wheres[0]['operator']);
        $this->assertEquals('5', $wheres[0]['value']);
        $this->assertEquals('<=', $wheres[1]['operator']);
        $this->assertEquals('10', $wheres[1]['value']);
    }

    public function test_filter_sw_operator()
    {
        $request = Request::create('http://test.com/api?filter[name][sw]=ja');
        $this->instance('request', $request);
        $where = Model::query()->buildFromRequest()->getQuery()->wheres[0];
        $this->assertEquals('like', $where['operator']);
        $this->assertEquals('ja%', $where['value']);
    }

    public function test_filter_ew_operator()
    {
        $request = Request::create('http://test.com/api?filter[name][ew]=ck');
        $this->instance('request', $request);
        $where = Model::query()->buildFromRequest()->getQuery()->wheres[0];
        $this->assertEquals('like', $where['operator']);
        $this->assertEquals('%ck', $where['value']);
    }

    public function test_filter_null_operator()
    {
        $request = Request::create('http://test.com/api?filter[name][null]=1');
        $this->instance('request', $request);
        $where = Model::query()->buildFromRequest()->getQuery()->wheres[0];
        $this->assertEquals('Null', $where['type']);
        $this->assertEquals('models.name', $where['column']);
    }

    public function test_filter_notnull_operator()
    {
        $request = Request::create('http://test.com/api?filter[name][notnull]=1');
        $this->instance('request', $request);
        $where = Model::query()->buildFromRequest()->getQuery()->wheres[0];
        $this->assertEquals('NotNull', $where['type']);
        $this->assertEquals('models.name', $where['column']);
    }

    public function test_filter_between_operator()
    {
        $request = Request::create('http://test.com/api?filter[id][between]=1,10');
        $this->instance('request', $request);
        $where = Model::query()->buildFromRequest()->getQuery()->wheres[0];
        $this->assertEquals('between', $where['type']);
        $this->assertEquals(['1', '10'], $where['values']);
    }

    public function test_filter_nin_operator()
    {
        $request = Request::create('http://test.com/api?filter[name][nin]=jack,collin');
        $this->instance('request', $request);
        $where = Model::query()->buildFromRequest()->getQuery()->wheres[0];
        $this->assertEquals('NotIn', $where['type']);
        $this->assertEquals(['jack', 'collin'], $where['values']);
    }

    // ── sortBy{Column} backward compat ──────────────────────────────

    public function test_sort_by_column_method_on_model()
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            use \Jwohlfert23\LaravelApiQuery\BuildQueryFromRequest;

            protected $table = 'models';

            public function sortByCustomRank(): string
            {
                return 'models.name';
            }
        };

        $request = Request::create('http://test.com/api?sort=-custom_rank');
        $this->instance('request', $request);

        $builder = $model->newQuery();
        \Jwohlfert23\LaravelApiQuery\ApiQueryBuilder::applyInputToBuilder($builder, $request->query);
        $query = $builder->getQuery();

        $this->assertCount(1, $query->orders);
        $this->assertEquals('desc', $query->orders[0]['direction']);
    }

    // ── with_count ──────────────────────────────────────────────────

    public function test_with_count()
    {
        $request = Request::create('http://test.com/api?with_count=related');
        $this->instance('request', $request);

        $builder = Model::query()->buildFromRequest();
        $query = $builder->getQuery();

        // withCount adds an aggregate select sub-query
        $this->assertNotEmpty($query->columns);
        $sql = $builder->toSql();
        $this->assertStringContainsString('related_count', $sql);
    }

    // ── queryable() alone as fallback ───────────────────────────────

    public function test_queryable_alone_restricts_sort_and_filter()
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            use \Jwohlfert23\LaravelApiQuery\BuildQueryFromRequest;

            protected $table = 'models';

            public function queryable(): array
            {
                return ['name'];
            }
        };

        // 'name' allowed, 'bool' not
        $request = Request::create('http://test.com/api?sort=name,bool&filter[name]=jack&filter[bool]=true');
        $this->instance('request', $request);

        $builder = $model->newQuery();
        \Jwohlfert23\LaravelApiQuery\ApiQueryBuilder::applyInputToBuilder($builder, $request->query);
        $query = $builder->getQuery();

        $this->assertCount(1, $query->orders);
        $this->assertEquals('models.name', (string) $query->orders[0]['column']);
        $this->assertCount(1, $query->wheres);
        $this->assertEquals('models.name', $query->wheres[0]['column']);
    }

    // ── normalizeQueryString edge cases ─────────────────────────────

    public function test_normalize_false_string_to_zero()
    {
        $request = Request::create('http://test.com/api?filter[bool]=false');
        $this->instance('request', $request);
        $where = Model::query()->buildFromRequest()->getQuery()->wheres[0];
        $this->assertSame(0, $where['value']);
    }

    public function test_normalize_null_string_to_null()
    {
        // "null" string is normalized to PHP null; where($col, null) becomes whereNull
        $request = Request::create('http://test.com/api?filter[name][eq]=null');
        $this->instance('request', $request);
        $where = Model::query()->buildFromRequest()->getQuery()->wheres[0];
        $this->assertEquals('Null', $where['type']);
        $this->assertEquals('models.name', $where['column']);
    }

    // ── Non-GET requests skipped ────────────────────────────────────

    public function test_non_get_request_is_ignored()
    {
        $request = Request::create('http://test.com/api?sort=name&filter[name]=jack', 'POST');
        $this->instance('request', $request);

        $builder = Model::query()->buildFromRequest();
        $query = $builder->getQuery();

        $this->assertEmpty($query->orders);
        $this->assertEmpty($query->wheres);
    }
}
