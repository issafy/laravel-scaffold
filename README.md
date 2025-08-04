# Laravel Scaffold

🚀 A powerful scaffolding tool for Laravel that streamlines the creation of models, controllers, routes, and more — with smart field definitions and reverse scaffolding from migrations.

Perfect for rapid development, database-first workflows, and teams that want to generate full REST resources in one command.

---

## ✨ Features

- **`make:model-with-fields`**  
  Generate a model, migration, controller, and REST routes — all from a single CLI command with field definitions.

- **Smart field syntax**  
  Define types, defaults, foreign keys, and constraints inline:
  ```bash
  --fields="title:string:required, active:boolean:default(true), user_id:foreign"

- **Auto-generated REST controller**

    Full index, store, show, update, destroy methods with JSON responses and validation. 

- **Route registration**

    Automatically adds Route::resource() to routes/web.php (excludes create/edit). 

- **Reverse scaffolding**

    Run scaffold:sync to scan existing migrations and auto-generate missing models and controllers. 

- **MySQL-optimized**

    Handles boolean defaults (1/0), foreign keys, indexes, and soft deletes detection. 
     

 
## 📦 Installation 

Require the package via Composer (in your Laravel app): 
```bash
composer require acme/laravel-scaffold @dev
```
💡 Tip: Use a path repository  during development: 
```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../acme/laravel-scaffold"
    }
  ]
}
```

The package will auto-register via Laravel's package discovery. 


## 🔧 Usage 

### 1. Generate a Resource with Fields

```bash
php artisan make:model-with-fields Post --fields="title:string:required, content:text:nullable, published:boolean:default(true), user_id:foreign:onDelete(cascade)"
```

#### This will create: 
- ✅ app/Models/Post.php (with $fillable and belongsTo(user))
- ✅ Migration with all fields and foreign key
- ✅ app/Http/Controllers/PostController.php (REST methods + validation)
- ✅ Route: Route::resource('posts', PostController::class)->except(['create', 'edit'])
- 💡 Suggests adding posts() relationship to User model
     

 
### 2. Sync Missing Models from Migrations
If you have migrations without models, sync them:
```bash
php artisan scaffold:sync
```
**Options**
- ```--dry-run```

    Preview what would be created:
    ```sh
    php artisan scaffold:sync --dry-run
    ```

- ```--soft-deletes```

    Preview what would be created:
    ```bash
    php artisan scaffold:sync --soft-deletes
    ```

- ```--watch```

    Watch for new migrations and auto-scaffold:
    ```bash
    php artisan scaffold:sync --watch
    ```

- ```--poll=2```

    Set polling interval (seconds):
    ```bash
    php artisan scaffold:sync --watch --poll=2
    ```

## ⚙️ Configuration (Optional) 

Publish the config file: 
```bash
php artisan vendor:publish --provider="Acme\\Scaffold\\ScaffoldServiceProvider" --tag=config
```

Then customize in ```config/scaffold.php```: 
- Model directory
- Controller namespace
- Default frontend stack (for future use)
     

 
## 🛠️ Supported Field Types 
| Type         | Example       |
|--------------|---------------|
| ```string``` | ```name:string:nullable```
| ```text```   | ```content:text```
| ```integer```,```biginteger``` | ```age:integer```
| ```boolean``` | ```name:string:nullable```
| ```json``` | ```meta:jsonstring:nullable```
| ```uuid``` | ```uuid:uuid```
| ```decimal``` | ```price:decimal:default(0)```
| ```foreign``` | ```user_id:foreign:onDelete(cascade)```

🔹 Modifiers: ```nullable```, ```default(value)```, ```index```, ```unique```, ```onDelete```, ```onUpdate``` 

## ⚠️ Notes 

- Optimized for MySQL
- Boolean defaults use 1/0. Not tested on PostgreSQL or SQLite. 

- No view generation (yet)
- Controller methods return JSON. Blade, React, or Vue support coming soon. 

- Does not modify existing files
- Safe to run multiple times. 
     

 
## 📚 Future Roadmap 

- ```--api``` flag for API resource mode
- Generate frontend: Blade, React (JSX/TSX), Vue
- Support uuid, enum, morphs
- Publishable stubs
- Auto-add SoftDeletes trait
     
 
## 🤝 Contributing 

Pull requests welcome! Please follow PSR-12 and test any new features. 

## 📄 License 

MIT