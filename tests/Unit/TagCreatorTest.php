<?php

use Pijler\LaravelTags\Models\Tag as PackageTag;
use Pijler\LaravelTags\TagCreator;
use Workbench\App\Enums\TagType;
use Workbench\App\Models\Tag;

beforeEach(function () {
    TagCreator::useTagModel(Tag::class);

    TagCreator::useDefaultType('default');
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

test('it should use the configured default type', function () {
    expect(TagCreator::defaultType())->toBe('default');

    TagCreator::useDefaultType('tag');

    expect(TagCreator::defaultType())->toBe('tag');
    expect(Tag::findOrCreate('Laravel')->type)->toBe('tag');
});

test('it should normalize blank types to the default type', function () {
    expect(TagCreator::normalizeType(null))->toBe('default');
    expect(TagCreator::normalizeType('category'))->toBe('category');
    expect(TagCreator::normalizeType(TagType::Category))->toBe('category');
});

test('it should find or create a tag using the configured model', function () {
    $tag = TagCreator::findOrCreate('Laravel');

    expect($tag->name)->toBe('Laravel');
    expect($tag->slug)->toBe('laravel');
    expect($tag)->toBeInstanceOf(Tag::class);
});
