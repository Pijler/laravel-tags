<p align="center">
<img src="https://raw.githubusercontent.com/Pijler/docs/main/assets/pijler-icon-dark.svg" height="100" alt="Pijler logo">
</p>

<h2><p align="center">Laravel Tags</p></h2>

### 🚀 Introduction

Add tagging to Eloquent models with a shared catalog (`tags` + `taggables`) and an optional JSON snapshot on the model itself.

The attach, sync, and query API is inspired by [spatie/laravel-tags](https://github.com/spatie/laravel-tags). This package does not translate tags. Instead, when a model has a `tags` JSON column, attached tags are copied onto that column and kept in sync — so you can read them without querying the relation.

### 🧩 Features

- ✅ **Taggable models**: Add tagging to any Eloquent model via a trait
- ✅ **Reusable tags**: Shared `tags` and `taggables` tables
- ✅ **Snapshot column**: Optional `tags` JSON column kept in sync automatically
- ✅ **Tag types**: Separate groups such as categories and topics, including backed enums
- ✅ **Query scopes**: Filter models with any, all, or none of the given tags
- ✅ **Custom models**: Swap the Tag model
- ✅ **UUID catalog**: Tags use UUID primary keys

### 📦 Installation

You can install the package via Composer:

```bash
composer require pijler/laravel-tags
```

### 🗄️ Publishing Migrations

Publish the package migrations:

```bash
php artisan vendor:publish --tag=laravel-tags-migrations
```

Run the migrations:

```bash
php artisan migrate
```

The `taggables` table uses Laravel's standard `morphs()` helper (`taggable_id` as `bigint`). Models with UUID primary keys need a custom migration.

To keep a snapshot of tags on a model, add a JSON `tags` column to that model's table:

```php
Schema::table('posts', function (Blueprint $table) {
    $table->json('tags')->nullable();
});
```

The column is optional. Models without it still use the `tags` and `taggables` tables.

### ⚙️ Configuration

The package works out-of-the-box, but you can customize the behavior:

```php
use Pijler\LaravelTags\TagCreator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        TagCreator::useDefaultType('tag');

        TagCreator::useTagModel(CustomTag::class);
    }
}
```

Call `useDefaultType()` before running the migrations so the `type` column default matches.

### 🧠 Usage

#### 1. Using the HasTags Trait

Add the `HasTags` trait to your model:

```php
use Illuminate\Database\Eloquent\Model;
use Pijler\LaravelTags\Traits\HasTags;

class Post extends Model
{
    use HasTags;
}
```

#### 2. Attaching Tags

```php
$post = Post::create([
    'title' => 'The Article Title',
    'tags' => ['Laravel', 'PHP'],
]);

$post->attachTag('Vue');
$post->attachTag('News', 'category');
$post->attachTags(['Livewire', 'Inertia']);
$post->attachTags(['Eloquent', 'Queues'], 'topic');
```

#### 3. Detaching Tags

```php
$post->detachTag('Vue');
$post->detachTag('News', 'category');
$post->detachTags(['Livewire', 'Inertia']);
$post->detachTags(['Eloquent', 'Queues'], 'topic');
```

#### 4. Syncing Tags

```php
$post->syncTags(['Laravel', 'PHP']); // all other tags on this model will be detached

$post->syncTagsWithType(['News', 'Tutorial'], 'category');
$post->syncTagsWithType(['Eloquent', 'Queues'], 'topic');
```

#### 5. Reading Tags

`$post->tags` is the relation (a collection of `Tag` models). When the model has a `tags` JSON column, `getTags()` returns the snapshot without querying that relation. The snapshot contains every tag field except `created_at` and `updated_at`; `order` is the pivot order on that model. `tagsWithType()` also returns snapshot arrays, not Tag models. Without a type, `tagsWithType()` and `hasTag()` use the default type.

```php
$post->tags; // collection of Tag models
$post->getTags();
// [
//     ['id' => '...', 'name' => 'Laravel', 'slug' => 'laravel', 'type' => 'default', 'order' => 1],
//     ['id' => '...', 'name' => 'PHP', 'slug' => 'php', 'type' => 'default', 'order' => 2],
// ]

$post->tagsWithType(); // default type
$post->tagsWithType('category');
$post->hasTag('Laravel');
$post->hasTag('News', 'category');
```

When a model has the snapshot column, `toArray()` / `toJson()` expose that snapshot under `tags`, not the relation, unless `tags` is hidden.

When a tag is renamed, updated, or deleted, attached snapshots are refreshed automatically.

Tags keep an `order` column in the catalog (`tags.order`, scoped by type) and on each model (`taggables.order`). `$post->tags` and `getTags()` follow the pivot order. `syncTags()` rewrites that order from the given list; `attachTags()` appends new tags at the end.

#### 6. Querying Models by Tags

```php
Post::withAnyTags(['Laravel', 'PHP'])->get();
Post::withAllTags(['Laravel', 'PHP'])->get();
Post::withoutTags(['Vue'])->get();
Post::withAnyTagsOfType('category')->get();
```

#### 7. Working with the Tag Model

```php
use Pijler\LaravelTags\Models\Tag;

$tag = Tag::findOrCreate('Laravel');
$tag = Tag::findOrCreate('News', 'category');

$tag->slug; // "laravel"
$tag->type; // "default"
$tag->order; // 1

Tag::query()->ordered()->get();
```

### 🧩 API Reference

#### TagCreator

```php
// Configuration
TagCreator::defaultType(): string
TagCreator::useTagModel(string $model): void
TagCreator::useDefaultType(string $type): void
TagCreator::normalizeType(mixed $type): string

// Methods
TagCreator::resolveModel(): Tag
TagCreator::findOrCreate(mixed $values, mixed $type = null): mixed
```

#### Tag Model

```php
$tag->toSnapshot(): array
$tag->syncTaggableSnapshots(): void

Tag::findOrCreate(mixed $values, mixed $type = null): Collection|static
Tag::findFromString(string $name, mixed $type = null): ?static
Tag::nextOrder(mixed $type = null): int
Tag::query()->withType(mixed $type)
Tag::query()->ordered()
```

#### HasTags Trait

```php
$model->tags; // Tag relation
$model->tags(): MorphToMany
$model->getTags(): array // snapshot column, without querying the relation
$model->hasTagsColumn(): bool
$model->attachTag(mixed $tag, mixed $type = null): static
$model->attachTags(mixed $tags, mixed $type = null): static
$model->detachTag(mixed $tag, mixed $type = null): static
$model->detachTags(mixed $tags, mixed $type = null): static
$model->syncTags(mixed $tags): static
$model->syncTagsWithType(mixed $tags, mixed $type = null): static
$model->hasTag(mixed $tag, mixed $type = null): bool // without type, uses the default type
$model->tagsWithType(mixed $type = null): array // snapshot arrays of that type; default type when omitted
$model->syncTagsColumn(): static
```

### 🙏 Credits

The public API is inspired by [spatie/laravel-tags](https://github.com/spatie/laravel-tags). If you need translated tag names, their package is the better fit.

### 📝 License

Open-source under the [MIT license](LICENSE).

### 🚀 Thanks!
