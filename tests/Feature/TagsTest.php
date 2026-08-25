<?php

use Pijler\LaravelTags\TagCreator;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tag;

beforeEach(function () {
    TagCreator::useTagModel(Tag::class);
});

test('it should keep the snapshot column synchronized with attached tags', function () {
    $post = Post::factory()->create();

    $post->attachTags(['Laravel', 'PHP']);

    $snapshot = $post->getTags();

    expect($snapshot)->toHaveCount(2);
    expect(data_get($snapshot, '0'))->not->toHaveKey('created_at');
    expect(data_get($snapshot, '0'))->not->toHaveKey('updated_at');
    expect(collect($snapshot)->pluck('name')->all())->toBe(['Laravel', 'PHP']);
    expect(data_get($snapshot, '0.id'))->toBe(Tag::findFromString('Laravel')->id);
});

test('it should round-trip snapshot values when syncing tags', function () {
    $post = Post::factory()->create([
        'tags' => ['Laravel', 'PHP'],
    ]);

    $post->tags = $post->getTags();

    expect($post->tags()->count())->toBe(2);
    expect($post->tags->pluck('name')->all())->toBe(['Laravel', 'PHP']);
});
