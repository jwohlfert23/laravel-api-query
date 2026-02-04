# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview

`jwohlfert23/laravel-api-query` is a Laravel package that builds Eloquent queries from HTTP request query parameters. It supports filtering (13+ operators), sorting, eager loading, and full-text search — all driven by URL query strings like `?filter[name][contains]=foo&sort=-created_at&with=author`.

## Commands

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/phpunit

# Run a single test
./vendor/bin/phpunit --filter test_name

# Code formatting
./vendor/bin/pint
```

Tests use Orchestra Testbench with an in-memory SQLite database. The `DB_CONNECTION=testing` env is set in `phpunit.xml`.

## Architecture

### Core Flow

`BuildQueryFromRequest` (trait on models) → `ApiQueryBuilder` (processing engine)

1. A model uses the `BuildQueryFromRequest` trait, which adds scopes like `buildFromRequest()`, `buildFrom()`, and `buildFromArray()`.
2. These scopes instantiate `ApiQueryBuilder` with the Eloquent builder and request parameters.
3. `ApiQueryBuilder::apply()` runs the pipeline: `applyWiths()` → `applyJoins()` → `applyFilters()` → `applySorts()`.

### Key Classes

- **`src/BuildQueryFromRequest.php`** — Trait added to Eloquent models. Provides `scopeBuildFromRequest()` (reads from current HTTP request), `scopeBuildFrom()` (accepts a `ParameterBag`), and `scopeBuildFromArray()`.
- **`src/ApiQueryBuilder.php`** — Core engine (~425 lines). Handles column resolution, filter operators, sorting, eager loading, auto-joining via `eloquent-power-joins`, and value normalization/casting.
- **`src/Searchable.php`** — Optional trait for full-text search via `?query=` parameter. Splits terms across searchable columns with OR conditions.
- **`src/LaravelApiQueryServiceProvider.php`** — Registers a `filterValidColumns()` macro on Collection for column name validation.

### Column Access Control

`ApiQueryBuilder::resolveColumn()` uses a three-tier allowlist:
1. Model's `sortable()` or `filterable()` method (operation-specific)
2. Falls back to model's `queryable()` method (shared allowlist)
3. If none defined, allows all schema columns

### Test Models

`models/` contains test-only model fixtures (`Model.php`, `RelatedModel.php`, `RestrictedModel.php`) loaded via `bootstrap.php`. `RestrictedModel` demonstrates the allowlist methods.

### Value Normalization

`ApiQueryBuilder::normalizeQueryStringSingular()` casts query string values based on the model's `$casts` — `"null"` → `null`, `"true"` → `1`, `"false"` → `0`, date strings parsed with Carbon respecting timezone, and `custom_date:FORMAT` patterns.