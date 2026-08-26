<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pijler\LaravelTags\TagCreator;
use Workbench\App\Enums\TagType;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tag;

beforeEach(function () {
    TagCreator::useTagModel(Tag::class);

    TagCreator::useDefaultType('default');
});

test('it should create a tag with a generated slug', function () {
    $tag = Tag::findOrCreate('Hello World');

    expect($tag->id)->toBeString();
    expect($tag->slug)->toBe('hello-world');
    expect($tag->name)->toBe('Hello World');
    expect($tag->type)->toBe(TagCreator::defaultType());
});

test('it should not create a duplicate tag with the same name', function () {
    $first = Tag::findOrCreate('Laravel');
    $second = Tag::findOrCreate('Laravel');

    expect(Tag::query()->count())->toBe(1);
    expect($second->is($first))->toBeTrue();
});

test('it should allow the same tag name with different types', function () {
    $untagged = Tag::findOrCreate('News');
    $category = Tag::findOrCreate('News', 'category');

    expect(Tag::query()->count())->toBe(2);
    expect($category->type)->toBe('category');
    expect($untagged->is($category))->toBeFalse();
    expect($untagged->type)->toBe(TagCreator::defaultType());
});

test('it should find or create tags from an array', function () {
    $tags = Tag::findOrCreate(['PHP', 'Laravel']);

    expect($tags)->toHaveCount(2);
    expect($tags->pluck('name')->all())->toBe(['PHP', 'Laravel']);
});

test('it should find or create a tag from a snapshot array', function () {
    $tag = Tag::findOrCreate(['name' => 'Laravel', 'type' => 'topic']);

    expect($tag->type)->toBe('topic');
    expect($tag->name)->toBe('Laravel');
});

test('it should find or create a tag using an enum type', function () {
    $tag = Tag::findOrCreate('News', TagType::Category);

    expect($tag->type)->toBe('category');
});

test('it should throw when an existing tag does not match the given type', function () {
    $tag = Tag::findOrCreate('News', 'category');

    Tag::findOrCreate($tag, 'topic');
})->throws(InvalidArgumentException::class);

test('it should keep an existing tag when no type is given', function () {
    $tag = Tag::findOrCreate('News', 'category');

    expect(Tag::findOrCreate($tag)->is($tag))->toBeTrue();
    expect(Tag::findOrCreate($tag)->type)->toBe('category');
});

test('it should update the slug when the name changes', function () {
    $tag = Tag::findOrCreate('Laravel');

    $tag->update(['name' => 'Laravel Framework']);

    expect($tag->slug)->toBe('laravel-framework');
});

test('it should not overwrite a custom slug when the name changes', function () {
    $tag = Tag::findOrCreate('Laravel');

    $tag->update([
        'name' => 'Laravel Framework',
        'slug' => 'custom-slug',
    ]);

    expect($tag->slug)->toBe('custom-slug');
});

test('it should convert a tag into a snapshot without timestamps', function () {
    $tag = Tag::findOrCreate('Laravel', 'topic');

    $snapshot = $tag->toSnapshot();

    expect($snapshot)->not->toHaveKey('created_at');
    expect($snapshot)->not->toHaveKey('updated_at');
    expect(data_get($snapshot, 'id'))->toBe($tag->id);
    expect(data_get($snapshot, 'type'))->toBe('topic');
    expect(data_get($snapshot, 'name'))->toBe('Laravel');
    expect(data_get($snapshot, 'slug'))->toBe('laravel');
    expect($snapshot)->toHaveKeys(['id', 'name', 'slug', 'type', 'order']);
});

test('it should omit hidden attributes from the snapshot', function () {
    $tag = Tag::findOrCreate('Laravel');
    $tag->makeHidden(['slug']);

    expect($tag->toSnapshot())->not->toHaveKey('slug');
    expect(data_get($tag->toSnapshot(), 'name'))->toBe('Laravel');
});

test('it should include extra tag columns in the snapshot', function () {
    Schema::table('tags', function (Blueprint $table) {
        $table->string('color')->nullable();
    });

    $tag = Tag::findOrCreate('Laravel');
    $tag->update(['color' => '#ff2d20']);

    expect($tag->toSnapshot())->toMatchArray([
        'id' => $tag->id,
        'name' => 'Laravel',
        'slug' => 'laravel',
        'color' => '#ff2d20',
        'order' => $tag->order,
        'type' => TagCreator::defaultType(),
    ]);
});

test('it should refresh snapshots when a tag is renamed', function () {
    $post = Post::factory()->create();
    $post->attachTag('Laravel');

    $tag = Tag::findFromString('Laravel');
    $tag->update(['name' => 'Laravel Framework']);

    $post->refresh();

    expect($post->getTags())->toHaveCount(1);
    expect(data_get($post->getTags(), '0'))->not->toHaveKey('created_at');
    expect(data_get($post->getTags(), '0.slug'))->toBe('laravel-framework');
    expect(data_get($post->getTags(), '0.name'))->toBe('Laravel Framework');
});

test('it should refresh snapshots when extra tag fields change', function () {
    Schema::table('tags', function (Blueprint $table) {
        $table->string('color')->nullable();
    });

    $post = Post::factory()->create();
    $post->attachTag('Laravel');

    $tag = Tag::findFromString('Laravel');
    $tag->update(['color' => '#ff2d20']);

    $post->refresh();

    expect(data_get($post->getTags(), '0.color'))->toBe('#ff2d20');
});

test('it should refresh snapshots when a tag is deleted', function () {
    $post = Post::factory()->create();
    $post->attachTags(['Laravel', 'PHP']);

    Tag::findFromString('Laravel')->delete();

    $post->refresh();

    expect($post->tags()->count())->toBe(1);
    expect($post->getTags())->toHaveCount(1);
    expect(data_get($post->getTags(), '0.name'))->toBe('PHP');
    expect($post->tags->pluck('pivot.order')->all())->toBe([1]);
});

test('it should limit tags by type', function () {
    Tag::findOrCreate('News', 'category');
    Tag::findOrCreate('PHP', 'topic');

    expect(Tag::query()->withType('category')->pluck('name')->all())->toBe(['News']);
});

test('it should assign catalog order per type', function () {
    $first = Tag::findOrCreate('Laravel');
    $second = Tag::findOrCreate('PHP');
    $category = Tag::findOrCreate('News', 'category');

    expect($first->order)->toBe(1);
    expect($second->order)->toBe(2);
    expect($category->order)->toBe(1);
});

test('it should order tags by the catalog order column', function () {
    Tag::findOrCreate('PHP');
    Tag::findOrCreate('Laravel');

    Tag::findFromString('Laravel')->update(['order' => 1]);
    Tag::findFromString('PHP')->update(['order' => 2]);

    expect(Tag::query()->ordered()->pluck('name')->all())->toBe(['Laravel', 'PHP']);
});

test('it should reject duplicate slugs of the same type', function () {
    Tag::findOrCreate('Laravel');

    Tag::query()->create([
        'name' => 'Laravel',
        'slug' => 'laravel',
        'type' => TagCreator::defaultType(),
    ]);
})->throws(QueryException::class);
