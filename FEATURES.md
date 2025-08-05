# Laravel Scaffold - Feature Summary

## Overview
A Laravel package to streamline resource creation: model, migration, controller, routes, and reverse scaffolding from migrations.

## Core Features

### ✅ Model Generation
- Created in `App\Models` (configurable via `config/scaffold.php`)
- Auto-populates `$fillable` from fields
- Adds `belongsTo()` relationships for `foreign` fields
- PSR-4 compliant

### ✅ Migration Generation
- Generates timestamped migration
- Supports field types:
  - `string`, `text`, `integer`, `boolean`, `json`, `uuid`, `decimal`, `bigInteger`, `float`, `double`, `dateTime`, `timestamp`
  - `foreign` → creates foreign key with `constrained()`
- MySQL-optimized syntax:
  - Boolean defaults: `1` (true), `0` (false)
  - Handles `nullable`, `default()`, `index`, `unique`, `onDelete`, `onUpdate`

### ✅ Controller Generation
- REST-only controller: `index`, `store`, `show`, `update`, `destroy`
- No `create`/`edit` (view-free)
- JSON responses with proper status codes (200, 201, 204)
- Auto-validation rules based on field types and modifiers
- Fillable fields injected in `store`/`update`

### ✅ Route Registration
- Auto-registers: `Route::resource('posts', PostController::class)->except(['create', 'edit'])`
- Appends to `routes/web.php`
- Uses plural snake_case route names

### ✅ Field Syntax
- CLI format: `--fields="name:type:modifier,value"`
- Example:  
  `title:string:required, active:boolean:default(true), user_id:foreign:onDelete(cascade)`

### ✅ Inverse Relationship Hints
- Suggests `hasMany()` on related model
- Outputs helpful warning (does not modify files)
- Example:  💡 SUGGESTION: Add to User.php:
```php
    public function posts() { 
        return $this->hasMany(Post::class); 
    } 
```

### ✅ Reverse Scaffolding (`scaffold:sync`)
- Command: `php artisan scaffold:sync`
- Scans all migrations for `Schema::create(...)`
- Detects tables without models
- Auto-generates missing model, controller, and route
- Reuses `make:model-with-fields` logic
- Skips existing models
- **Never modifies migration files** (read-only)

#### ✅ Sync Options
- `--dry-run`  
Preview what would be created (no changes)
- `--soft-deletes`  
Detects `->softDeletes()` and includes `deleted_at` field
- `--watch`  
Watches migration folder and auto-scaffolds new ones
- `--poll=N`  
Set polling interval in seconds (default: 1)

## Technical Details
- Laravel 11 & 12 compatible
- Requires `composer/installers`
- Stubs in `src/Stubs/` (internal)
- Configurable via `config/scaffold.php`
- Uses Laravel conventions and filesystem tools
- **Read-only migration parsing** — no changes ever made to migration files

## Limitations
- Optimized for MySQL (boolean defaults, etc.)
- Does not generate views (Blade, React, Vue, Inertia)
- Does not modify existing files
- No API resource mode (yet)
- Does not auto-add `SoftDeletes` trait to model (yet)

## Next Planned Features
- [ ] `--api` flag → API resource with `apiResource` and `ApiResource`
- [ ] Generate frontend: Blade, React (JSX/TSX), Vue
- [ ] Support `uuid`, `enum`, `morphs`
- [ ] Publishable stubs (`stubs/acme-scaffold/`)
- [ ] Auto-add `use SoftDeletes;` and `$dates` in model