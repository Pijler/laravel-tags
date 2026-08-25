<?php

use Pijler\LaravelTags\Models\Tag as PackageTag;
use Pijler\LaravelTags\TagCreator;
use Workbench\App\Models\Tag;

beforeEach(function () {
    TagCreator::useTagModel(Tag::class);
});

test('it should have default tag model', function () {
    TagCreator::$tagModel = PackageTag::class;

    expect(TagCreator::$tagModel)->toBe(PackageTag::class);
});

test('it should set tag model correctly', function () {
    TagCreator::useTagModel(Tag::class);

    expect(TagCreator::$tagModel)->toBe(Tag::class);
});

test('it should resolve model instance correctly', function () {
    $result = TagCreator::resolveModel();

    expect($result)->toBeInstanceOf(Tag::class);
});

test('it should find or create a tag using the configured model', function () {
    $tag = TagCreator::findOrCreate('Laravel');

    expect($tag->name)->toBe('Laravel');
    expect($tag->slug)->toBe('laravel');
    expect($tag)->toBeInstanceOf(Tag::class);
});
