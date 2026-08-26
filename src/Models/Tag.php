<?php

namespace Pijler\LaravelTags\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Pijler\LaravelTags\TagCreator;

class Tag extends Model
{
    use HasUuids;

    /**
     * The attributes that aren't mass assignable.
     */
    protected $guarded = [];

    /**
     * Taggable models that should have their snapshot column refreshed after this tag is deleted.
     */
    protected ?Collection $taggablesPendingSnapshotSync = null;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }

    /**
     * Normalize blank types to the default type.
     */
    protected function type(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value) => TagCreator::normalizeType($value),
        );
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::creating(function (Tag $tag) {
            if (blank($tag->order)) {
                $tag->order = static::nextOrder($tag->type);
            }
        });

        static::saving(function (Tag $tag) {
            if (blank($tag->slug) || ($tag->isDirty('name') && ! $tag->isDirty('slug'))) {
                $tag->slug = Str::slug((string) $tag->name);
            }
        });

        static::updated(function (Tag $tag) {
            $changed = collect($tag->getChanges())->except([
                $tag->getCreatedAtColumn(),
                $tag->getUpdatedAtColumn(),
            ]);

            if ($changed->isNotEmpty()) {
                $tag->syncTaggableSnapshots();
            }
        });

        static::deleting(function (Tag $tag) {
            $tag->taggablesPendingSnapshotSync = $tag->getTaggableModels();
        });

        static::deleted(function (Tag $tag) {
            $tag->taggablesPendingSnapshotSync?->each(function (Model $model) {
                if (method_exists($model, 'syncTagsColumn')) {
                    $model->syncTagsColumn();
                }
            });
        });
    }

    /**
     * Limit the query to tags of the given type.
     */
    public function scopeWithType(Builder $query, mixed $type): Builder
    {
        return $query->where('type', TagCreator::normalizeType($type));
    }

    /**
     * Order tags by their catalog order column.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->getModel()->qualifyColumn('order'));
    }

    /**
     * Determine whether the given value is a tag snapshot array.
     */
    public static function isSnapshot(mixed $values): bool
    {
        return is_array($values) && ! array_is_list($values) && filled(data_get($values, 'name'));
    }

    /**
     * Get the next catalog order for the given type.
     */
    public static function nextOrder(mixed $type = null): int
    {
        return (int) static::query()->where('type', TagCreator::normalizeType($type))->max('order') + 1;
    }

    /**
     * Ensure an existing tag matches the expected type when one is provided.
     */
    protected static function guardType(self $tag, mixed $type): void
    {
        if (is_null($type)) {
            return;
        }

        $type = TagCreator::normalizeType($type);

        if ($tag->type !== $type) {
            throw new InvalidArgumentException("Expected type [{$type}], but the tag has type [{$tag->type}].");
        }
    }

    /**
     * Convert the tag into a snapshot array for storage on taggable models.
     *
     * Hidden attributes and timestamps are omitted. Extra columns on the
     * tags table are included automatically.
     */
    public function toSnapshot(): array
    {
        return Arr::except($this->attributesToArray(), array_filter([
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ], function ($column) {
            return filled($column);
        }));
    }

    /**
     * Refresh the snapshot column on every model that currently has this tag.
     */
    public function syncTaggableSnapshots(): void
    {
        $this->getTaggableModels()->each(function (Model $model) {
            if (method_exists($model, 'syncTagsColumn')) {
                $model->syncTagsColumn();
            }
        });
    }

    /**
     * Find a tag by name or slug without creating it.
     */
    public static function findFromString(string $name, mixed $type = null): ?static
    {
        $type = TagCreator::normalizeType($type);

        return static::query()
            ->where('type', $type)
            ->where(function (Builder $query) use ($name) {
                $query->where('name', $name)->orWhere('slug', Str::slug($name));
            })->first();
    }

    /**
     * Find an existing tag by name or create it when it does not exist.
     */
    public static function findOrCreateFromString(string $name, mixed $type = null): static
    {
        $type = TagCreator::normalizeType($type);

        return static::findFromString($name, $type) ?? static::query()->create([
            'name' => $name,
            'type' => $type,
            'slug' => Str::slug($name),
        ]);
    }

    /**
     * Get every taggable model currently attached to this tag.
     */
    public function getTaggableModels(): Collection
    {
        $rows = $this->newQuery()->getConnection()
            ->table('taggables')
            ->where('tag_id', $this->getKey())
            ->get(['taggable_type', 'taggable_id']);

        return $rows->groupBy('taggable_type')->flatMap(function (Collection $group, string $type) {
            $class = Relation::getMorphedModel($type) ?? $type;

            if (! class_exists($class)) {
                return [];
            }

            $instance = new $class;

            $query = $instance->newQuery();

            if (method_exists($instance, 'withTrashed')) {
                $query = $query->withTrashed();
            }

            return $query->whereIn($instance->getKeyName(), $group->pluck('taggable_id'))->get();
        })->values();
    }

    /**
     * Find an existing tag or create it when it does not exist.
     *
     * A string returns a single tag. An iterable returns a collection of tags.
     */
    public static function findOrCreate(mixed $values, mixed $type = null): EloquentCollection|static
    {
        if ($values instanceof static) {
            static::guardType($values, $type);

            return $values;
        }

        if (static::isSnapshot($values)) {
            return static::findOrCreateFromString(data_get($values, 'name'), data_get($values, 'type', $type));
        }

        if (is_string($values)) {
            return static::findOrCreateFromString($values, $type);
        }

        return (new static)->newCollection(
            collect($values)->map(function ($value) use ($type) {
                return static::findOrCreate($value, $type);
            })->all()
        );
    }
}
