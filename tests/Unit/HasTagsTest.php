<?php

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pijler\LaravelTags\TagCreator;
use Workbench\App\Enums\TagType;
use Workbench\App\Models\Article;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tag;

beforeEach(function () {
    TagCreator::useTagModel(Tag::class);

    TagCreator::useDefaultType('default');

    $this->post = Post::factory()->create();
    $this->article = Article::factory()->create();
});

test('it should return a morphToMany relationship for tags', function () {
    expect($this->post->tags())->toBeInstanceOf(MorphToMany::class);
});

test('it should attach tags and store a snapshot on the model', function () {
    $this->post->attachTags(['Laravel', 'PHP']);

    expect($this->post->tags)->toHaveCount(2);
    expect($this->post->getTags())->toHaveCount(2);
    expect($this->post->tags->first())->toBeInstanceOf(Tag::class);
    expect(data_get($this->post->getTags(), '0'))->not->toHaveKey('created_at');
    expect(data_get($this->post->getTags(), '0'))->not->toHaveKey('updated_at');
    expect(collect($this->post->getTags())->pluck('name')->all())->toBe(['Laravel', 'PHP']);
    expect(data_get($this->post->getTags(), '0'))->toHaveKeys(['id', 'name', 'slug', 'type', 'order']);
});

test('it should attach a single tag', function () {
    $this->post->attachTag('Laravel');

    expect($this->post->hasTag('Laravel'))->toBeTrue();
    expect($this->post->hasTag('laravel'))->toBeTrue();
    expect($this->post->hasTag('LARAVEL'))->toBeTrue();
    expect(Post::withAnyTags(['LARAVEL'])->count())->toBe(1);
    expect($this->article->attachTag('Laravel')->hasTag('LARAVEL'))->toBeTrue();
});

test('it should not attach the same tag twice', function () {
    $this->post->attachTag('Laravel');
    $this->post->attachTag('Laravel');

    expect($this->post->tags()->count())->toBe(1);
    expect($this->post->getTags())->toHaveCount(1);
});

test('it should create a model with tags through mass assignment', function () {
    $post = Post::create([
        'title' => 'The Article Title',
        'tags' => ['first tag', 'second tag'],
    ]);

    expect($post->tags)->toHaveCount(2);
    expect($post->tags->pluck('name')->all())->toBe(['first tag', 'second tag']);
});

test('it should sync tags through the tags mutator', function () {
    $this->post->attachTags(['Laravel', 'PHP']);

    $this->post->tags = ['Vue'];

    expect($this->post->getTags())->toHaveCount(1);
    expect($this->post->tags->pluck('name')->all())->toBe(['Vue']);
});

test('it should detach tags and refresh the snapshot', function () {
    $this->post->attachTags(['Laravel', 'PHP']);
    $this->post->detachTag('Laravel');

    expect($this->post->tags->pluck('name')->all())->toBe(['PHP']);
    expect(collect($this->post->getTags())->pluck('name')->all())->toBe(['PHP']);
});

test('it should replace all tags when syncing', function () {
    $this->post->attachTags(['Laravel', 'PHP']);
    $this->post->syncTags(['Vue']);

    expect($this->post->tags->pluck('name')->all())->toBe(['Vue']);
});

test('it should sync tags of a given type without affecting other types', function () {
    $this->post->attachTags(['Laravel', 'PHP'], 'topic');
    $this->post->attachTags(['News'], 'category');

    $this->post->syncTagsWithType(['Vue'], 'topic');

    expect(collect($this->post->tagsWithType('topic'))->pluck('name')->all())->toBe(['Vue']);
    expect(collect($this->post->tagsWithType('category'))->pluck('name')->all())->toBe(['News']);
});

test('it should attach tags using an enum type', function () {
    $this->post->attachTag('News', TagType::Category);

    expect($this->post->hasTag('News', TagType::Topic))->toBeFalse();
    expect($this->post->hasTag('News', TagType::Category))->toBeTrue();
});

test('it should check tags from the snapshot without loading the relation', function () {
    $this->post->attachTag('Laravel');

    expect($this->post->relationLoaded('tags'))->toBeFalse();
    expect($this->post->hasTag('Laravel'))->toBeTrue();
    expect(data_get($this->post->getTags(), '0.name'))->toBe('Laravel');
    expect($this->post->relationLoaded('tags'))->toBeFalse();
});

test('it should work without a tags snapshot column', function () {
    $this->article->attachTags(['Laravel', 'PHP']);

    expect($this->article->getTags())->toBe([]);
    expect($this->article->tags)->toHaveCount(2);
    expect($this->article->hasTagsColumn())->toBeFalse();
    expect($this->article->tags->pluck('name')->all())->toBe(['Laravel', 'PHP']);
});

test('it should queue tags attached before the model is persisted', function () {
    $post = new Post(['title' => 'Queued tags']);
    $post->attachTag('Laravel');
    $post->save();

    expect($post->hasTag('Laravel'))->toBeTrue();
});

test('it should retrieve models that have any of the given tags', function () {
    $this->post->attachTags(['Laravel', 'PHP']);
    Post::factory()->create()->attachTag('Vue');

    expect(Post::withAnyTags(['Laravel'])->count())->toBe(1);
    expect(Post::withAnyTags(['Laravel', 'Vue'])->count())->toBe(2);
});

test('it should retrieve models that have all of the given tags', function () {
    $this->post->attachTags(['Laravel', 'PHP']);
    Post::factory()->create()->attachTag('Laravel');

    expect(Post::withAllTags(['Laravel', 'PHP'])->count())->toBe(1);
});

test('it should retrieve models that do not have the given tags', function () {
    $this->post->attachTag('Laravel');
    Post::factory()->create()->attachTag('Vue');

    expect(Post::withoutTags(['Laravel'])->count())->toBe(Post::query()->count() - 1);
});

test('it should retrieve models that have tags of a given type', function () {
    $this->post->attachTag('News', 'category');
    Post::factory()->create()->attachTag('Laravel', 'topic');

    expect(Post::withAnyTagsOfType('category')->count())->toBe(1);
});

test('it should detach tags when the model is deleted', function () {
    $this->post->attachTag('Laravel');

    $this->post->delete();

    expect(Tag::query()->count())->toBe(1);
    expect($this->post->tags()->count())->toBe(0);
});

test('it should store tags on the model in the given order', function () {
    $this->post->attachTags(['Laravel', 'PHP']);

    expect($this->post->tags->pluck('pivot.order')->all())->toBe([1, 2]);
    expect($this->post->tags->pluck('name')->all())->toBe(['Laravel', 'PHP']);
    expect(collect($this->post->getTags())->pluck('name')->all())->toBe(['Laravel', 'PHP']);
    expect(collect($this->post->getTags())->pluck('order')->all())->toBe([1, 2]);
});

test('it should append newly attached tags after the current order', function () {
    $this->post->attachTags(['Laravel', 'PHP']);
    $this->post->attachTag('Vue');

    expect($this->post->tags->pluck('pivot.order')->all())->toBe([1, 2, 3]);
    expect($this->post->tags->pluck('name')->all())->toBe(['Laravel', 'PHP', 'Vue']);
});

test('it should rewrite taggable order when a tag is detached', function () {
    $this->post->attachTags(['Laravel', 'PHP', 'Vue']);
    $this->post->detachTag('PHP');

    expect($this->post->tags->pluck('pivot.order')->all())->toBe([1, 2]);
    expect($this->post->tags->pluck('name')->all())->toBe(['Laravel', 'Vue']);
    expect(collect($this->post->getTags())->pluck('name')->all())->toBe(['Laravel', 'Vue']);
});

test('it should rewrite taggable order when syncing tags', function () {
    $this->post->attachTags(['Laravel', 'PHP']);
    $this->post->syncTags(['Vue', 'Laravel']);

    expect($this->post->tags->pluck('pivot.order')->all())->toBe([1, 2]);
    expect($this->post->tags->pluck('name')->all())->toBe(['Vue', 'Laravel']);
    expect(collect($this->post->getTags())->pluck('name')->all())->toBe(['Vue', 'Laravel']);
    expect(collect($this->post->getTags())->pluck('order')->all())->toBe([1, 2]);
});

test('it should not match models when withAllTags includes a missing tag', function () {
    $this->post->attachTags(['Laravel', 'PHP']);

    expect(Post::withAllTags(['Missing'])->count())->toBe(0);
    expect(Post::withAllTags(['Laravel', 'Missing'])->count())->toBe(0);
});

test('it should not match a typed tag when hasTag omits the type', function () {
    $this->post->attachTag('News', 'category');
    $this->article->attachTag('News', 'category');

    expect($this->post->hasTag('News'))->toBeFalse();
    expect($this->post->hasTag('News', 'category'))->toBeTrue();
    expect($this->article->hasTag('News'))->toBeFalse();
    expect($this->article->hasTag('News', 'category'))->toBeTrue();
});

test('it should resolve hasTag from a tag model using that tag type', function () {
    $tag = Tag::findOrCreate('News', 'category');

    $this->post->attachTag($tag);

    expect($this->post->hasTag($tag))->toBeTrue();
    expect($this->post->hasTag($tag, 'topic'))->toBeFalse();
});

test('it should return default type tags when tagsWithType has no type', function () {
    $this->post->attachTags(['Laravel']);
    $this->post->attachTag('News', 'category');

    expect(collect($this->post->tagsWithType())->pluck('name')->all())->toBe(['Laravel']);
    expect(collect($this->article->attachTag('Laravel')->tagsWithType())->pluck('name')->all())->toBe(['Laravel']);
});

test('it should queue tags detached before the model is persisted', function () {
    $post = new Post(['title' => 'Queued tags']);
    $post->attachTags(['Laravel', 'PHP']);
    $post->detachTag('Laravel');
    $post->save();

    expect($post->tags->pluck('name')->all())->toBe(['PHP']);
});

test('it should keep tags when the model is soft deleted', function () {
    Schema::table('posts', function (Blueprint $table) {
        $table->softDeletes();
    });

    $post = new class extends Post
    {
        use SoftDeletes;

        protected $table = 'posts';
    };
    $post->title = 'Soft deleted post';
    $post->save();
    $post->attachTag('Laravel');

    $post->delete();

    expect($post->tags()->count())->toBe(1);

    $id = $post->getKey();
    $post->forceDelete();

    expect(DB::table('taggables')->where('taggable_id', $id)->count())->toBe(0);
});

test('it should serialize the snapshot column as tags', function () {
    $this->post->attachTags(['Laravel', 'PHP']);

    $this->post->unsetRelation('tags');

    expect(data_get($this->post->toArray(), 'tags.0'))->toBeArray();
    expect(collect(data_get($this->post->toArray(), 'tags'))->pluck('name')->all())->toBe(['Laravel', 'PHP']);
});

test('it should omit the snapshot from toArray when tags are hidden', function () {
    $this->post->attachTags(['Laravel']);

    $this->post->makeHidden('tags');

    expect($this->post->toArray())->not->toHaveKey('tags');
});
