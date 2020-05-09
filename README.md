# Build Eloquent Queries from Request Query Params

### Installation
`composer require jwohlfert23/laravel-api-query`

### Usage
This package is implemented as a trait, which provides the `buildFromRequest` scope.

```php
use Jwohlfert23\LaravelApiQuery\BuildQueryFromRequest;

class Post {
    use BuildQueryFromRequest;
}
```
```php
Post::buildFromRequest()->get();
```

#### ?sort=-id,name
is the same as:
```php
Post::orderByDesc('id')->orderBy('name');
```

#### ?filter[name]=Bobby&filter[author.name][contains]=Bob
is the same as:
```php
Post::where('name', 'Bobby')->whereHas('author', function($q) {
    $q->where('name', 'like', '%Bob%');
});
```
Note: this package doesn't use "whereHas", but rather performs left joins internally. However, the results should be the same as the above code.

Filters default to using the "equal" operator. These are the operators available to use in filtering (contains is use above).
- eq (=)
- gt (>)
- gte (>=)
- lt (<)
- lte (<=)
- contains

#### ?with=author,comments
is the same as
```php
Post::with('author','comments');
```
