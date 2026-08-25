<?php

namespace Pijler\LaravelTags;

use Pijler\LaravelTags\Models\Tag;

use function Illuminate\Support\enum_value;

class TagCreator
{
    /**
     * The fully qualified class name of the tag model.
     *
     * This model is responsible for persisting and managing tags
     * in the database. You can override this to use a custom implementation.
     */
    public static string $tagModel = Tag::class;

    /**
     * Specify a custom tag model class.
     */
    public static function useTagModel(string $model): void
    {
        static::$tagModel = $model;
    }

    /**
     * Resolve a new instance of the configured Tag model.
     */
    public static function resolveModel(): Tag
    {
        return resolve(static::$tagModel);
    }

    /**
     * Proxy method to find or create a tag using the configured model.
     */
    public static function findOrCreate(mixed $values, mixed $type = null): mixed
    {
        return static::resolveModel()::findOrCreate($values, enum_value($type));
    }
}
