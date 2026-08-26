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
     * The type assigned when none is specified.
     */
    public static string $defaultType = 'default';

    /**
     * Specify a custom tag model class.
     */
    public static function useTagModel(string $model): void
    {
        static::$tagModel = $model;
    }

    /**
     * Specify the type assigned when none is given.
     */
    public static function useDefaultType(string $type): void
    {
        static::$defaultType = $type;
    }

    /**
     * Get the default tag type.
     */
    public static function defaultType(): string
    {
        return static::$defaultType;
    }

    /**
     * Resolve a new instance of the configured Tag model.
     */
    public static function resolveModel(): Tag
    {
        return resolve(static::$tagModel);
    }

    /**
     * Normalize a tag type for storage and querying.
     */
    public static function normalizeType(mixed $type): string
    {
        $value = enum_value($type);

        return filled($value) ? (string) $value : static::defaultType();
    }

    /**
     * Proxy method to find or create a tag using the configured model.
     */
    public static function findOrCreate(mixed $values, mixed $type = null): mixed
    {
        return static::resolveModel()::findOrCreate($values, enum_value($type));
    }
}
