<?php

namespace Pijler\LaravelTags\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Pijler\LaravelTags\Models\Tag;
use Pijler\LaravelTags\TagCreator;

use function Illuminate\Support\enum_value;

trait HasTags
{
    /**
     * Tag operations queued until the model is persisted.
     */
    protected array $queuedTagOperations = [];

    /**
     * Cached tags-column existence keyed by table and column name.
     */
    protected static array $tagsColumnExistence = [];

    /**
     * Bootstrap the HasTags trait.
     */
    public static function bootHasTags(): void
    {
        static::created(function (Model $model) {
            foreach ($model->queuedTagOperations as [$method, $tags, $type]) {
                $model->{$method}($tags, $type);
            }

            $model->queuedTagOperations = [];
        });

        static::deleted(function (Model $model) {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $model->tags()->detach();
        });
    }

    /**
     * Get the name of the snapshot column stored on this model.
     */
    public function tagsColumn(): string
    {
        return 'tags';
    }

    /**
     * Get the tags that belong to the model.
     */
    public function tags(): MorphToMany
    {
        $classModel = TagCreator::$tagModel;

        return $this->morphToMany($classModel, 'taggable')
            ->withTimestamps()
            ->withPivot('order')
            ->orderByPivot('order');
    }

    /**
     * Prefer the tags relation over the snapshot column when accessing $model->tags.
     */
    public function getAttribute($key): mixed
    {
        if ($key === $this->tagsColumn()) {
            return $this->getRelationValue($key);
        }

        return parent::getAttribute($key);
    }

    /**
     * Include the snapshot under the tags column when serializing.
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        if ($this->hasTagsColumn()) {
            $array[$this->tagsColumn()] = $this->getTags();
        }

        return $array;
    }

    /**
     * Determine whether this model has a snapshot column for tags.
     */
    public function hasTagsColumn(): bool
    {
        $key = $this->getTable().'.'.$this->tagsColumn();

        return static::$tagsColumnExistence[$key] ??= Schema::hasColumn($this->getTable(), $this->tagsColumn());
    }

    /**
     * Sync the tags associated with the model.
     *
     * When the model has not been persisted yet, the assignment is queued
     * and executed after the model is created.
     */
    public function setTagsAttribute(mixed $tags): void
    {
        if (! $this->exists) {
            $this->queuedTagOperations[] = ['syncTags', $tags, null];

            return;
        }

        $this->syncTags($tags);
    }

    /**
     * Get the snapshot stored on the tags column without querying the relation.
     *
     * $this->tags always returns the relation, so the column is read from the raw attributes.
     */
    public function getTags(): array
    {
        $value = data_get($this->attributes, $this->tagsColumn());

        if (is_array($value)) {
            return $value;
        }

        if (blank($value)) {
            return [];
        }

        return json_decode($value, true) ?: [];
    }

    /**
     * Attach the given tags to the model.
     */
    public function attachTags(mixed $tags, mixed $type = null): static
    {
        if (! $this->exists) {
            $this->queuedTagOperations[] = ['attachTags', $tags, $type];

            return $this;
        }

        $tags = $this->normalizeTags(TagCreator::findOrCreate($tags, $type));

        $payload = $this->newTaggableOrderPayload($tags);

        if (filled($payload)) {
            $this->tags()->syncWithoutDetaching($payload);
        }

        return $this->syncTagsColumn();
    }

    /**
     * Attach a single tag to the model.
     */
    public function attachTag(mixed $tag, mixed $type = null): static
    {
        return $this->attachTags([$tag], $type);
    }

    /**
     * Detach the given tags from the model.
     */
    public function detachTags(mixed $tags, mixed $type = null): static
    {
        if (! $this->exists) {
            $this->queuedTagOperations[] = ['detachTags', $tags, $type];

            return $this;
        }

        $tags = $this->locateTags($tags, $type);

        if ($tags->isNotEmpty()) {
            $this->tags()->detach($tags->pluck('id')->all());
        }

        return $this->syncTagsColumn();
    }

    /**
     * Detach a single tag from the model.
     */
    public function detachTag(mixed $tag, mixed $type = null): static
    {
        return $this->detachTags([$tag], $type);
    }

    /**
     * Replace all tags on the model with the given tags.
     */
    public function syncTags(mixed $tags): static
    {
        if (! $this->exists) {
            $this->queuedTagOperations[] = ['syncTags', $tags, null];

            return $this;
        }

        $tags = $this->normalizeTags(TagCreator::findOrCreate($tags));

        $this->tags()->sync($this->taggableOrderPayload($tags));

        return $this->syncTagsColumn();
    }

    /**
     * Replace tags of the given type, leaving tags of other types unchanged.
     */
    public function syncTagsWithType(mixed $tags, mixed $type = null): static
    {
        $type = enum_value($type);

        if (! $this->exists) {
            $this->queuedTagOperations[] = ['syncTagsWithType', $tags, $type];

            return $this;
        }

        $tags = $this->normalizeTags(TagCreator::findOrCreate($tags, $type));

        $ids = $tags->pluck('id')->all();

        $related = $this->tags()->getRelated();

        $current = $this->tags()
            ->where($related->qualifyColumn('type'), TagCreator::normalizeType($type))
            ->pluck($related->getQualifiedKeyName())
            ->all();

        $this->tags()->detach(array_diff($current, $ids));

        $payload = $this->taggableOrderPayload($tags, $this->nextTaggableOrder());

        if (filled($payload)) {
            $this->tags()->syncWithoutDetaching($payload);
        }

        return $this->syncTagsColumn();
    }

    /**
     * Determine whether the model has the given tag.
     */
    public function hasTag(mixed $tag, mixed $type = null): bool
    {
        $type = enum_value($type);

        if ($this->hasTagsColumn()) {
            return collect($this->getTags())->contains(function (array $snapshot) use ($tag, $type) {
                if (filled($type) && data_get($snapshot, 'type') !== $type) {
                    return false;
                }

                if ($tag instanceof Tag) {
                    return data_get($snapshot, 'id') === $tag->getKey();
                }

                return data_get($snapshot, 'name') === $tag
                    || data_get($snapshot, 'slug') === $tag;
            });
        }

        return $this->tags->when(filled($type), function (Collection $tags) use ($type) {
            return $tags->where('type', $type);
        })->contains(function (Tag $modelTag) use ($tag) {
            if ($tag instanceof Tag) {
                return $modelTag->is($tag);
            }

            return $modelTag->name === $tag || $modelTag->slug === $tag;
        });
    }

    /**
     * Get the snapshot tags that match the given type.
     */
    public function tagsWithType(mixed $type = null): array
    {
        $type = TagCreator::normalizeType($type);

        return collect($this->getTagsSnapshot())->filter(function (array $snapshot) use ($type) {
            return data_get($snapshot, 'type') === $type;
        })->values()->all();
    }

    /**
     * Rewrite pivot order and the snapshot column from the current tag relation.
     */
    public function syncTagsColumn(): static
    {
        if (! $this->exists) {
            return $this;
        }

        $tags = $this->tags()->get()->values();

        if ($tags->isNotEmpty()) {
            $this->tags()->sync($this->taggableOrderPayload($tags));
        }

        $this->unsetRelation('tags');

        if ($this->hasTagsColumn()) {
            $this->fillTagsColumn($tags->map(function (Tag $tag) {
                return $tag->toSnapshot();
            })->values()->all());
        }

        return $this;
    }

    /**
     * Limit the query to models that have all of the given tags.
     */
    public function scopeWithAllTags(Builder $query, mixed $tags, mixed $type = null): Builder
    {
        $requested = $this->normalizeTagInput($tags);

        if ($requested->isEmpty()) {
            return $query;
        }

        $located = $this->locateTags($tags, $type);

        if ($located->count() !== $requested->count()) {
            return $query->whereRaw('0 = 1');
        }

        $located->each(function (Tag $tag) use ($query) {
            $query->whereHas('tags', function (Builder $query) use ($tag) {
                $query->where($tag->getQualifiedKeyName(), $tag->getKey());
            });
        });

        return $query;
    }

    /**
     * Limit the query to models that have any of the given tags.
     */
    public function scopeWithAnyTags(Builder $query, mixed $tags, mixed $type = null): Builder
    {
        $ids = $this->locateTags($tags, $type)->pluck('id');

        return $query->whereHas('tags', function (Builder $query) use ($ids) {
            $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids);
        });
    }

    /**
     * Limit the query to models that do not have any of the given tags.
     */
    public function scopeWithoutTags(Builder $query, mixed $tags, mixed $type = null): Builder
    {
        $ids = $this->locateTags($tags, $type)->pluck('id');

        return $query->whereDoesntHave('tags', function (Builder $query) use ($ids) {
            $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids);
        });
    }

    /**
     * Limit the query to models that have any tags of the given type.
     */
    public function scopeWithAnyTagsOfType(Builder $query, mixed $type): Builder
    {
        $types = collect(Arr::wrap($type))->map(function (mixed $value) {
            return TagCreator::normalizeType($value);
        })->all();

        return $query->whereHas('tags', function (Builder $query) use ($types) {
            $query->whereIn('type', $types);
        });
    }

    /**
     * Persist the snapshot array on the tags column without triggering the mutator.
     */
    protected function fillTagsColumn(array $snapshot): void
    {
        $column = $this->tagsColumn();

        $encoded = json_encode(array_values($snapshot), JSON_UNESCAPED_UNICODE);

        data_set($this->attributes, $column, $encoded);

        $values = [];

        data_set($values, $column, $encoded);

        if ($this->usesTimestamps()) {
            $timestamp = $this->freshTimestamp();

            data_set($values, $this->getUpdatedAtColumn(), $this->fromDateTime($timestamp));

            $this->{$this->getUpdatedAtColumn()} = $timestamp;
        }

        $this->newQueryWithoutScopes()
            ->whereKey($this->getKey())
            ->update($values);

        $this->syncOriginalAttribute($column);

        if ($this->usesTimestamps()) {
            $this->syncOriginalAttribute($this->getUpdatedAtColumn());
        }
    }

    /**
     * Get the snapshot array from the column when it exists, otherwise from the relation.
     */
    protected function getTagsSnapshot(): array
    {
        if ($this->hasTagsColumn()) {
            return $this->getTags();
        }

        if (! $this->exists) {
            return [];
        }

        return $this->tags->map(function (Tag $tag) {
            return $tag->toSnapshot();
        })->values()->all();
    }

    /**
     * Build a pivot payload that assigns sequential order from the given list.
     */
    protected function taggableOrderPayload(Collection $tags, int $start = 1): array
    {
        return $tags->values()->mapWithKeys(function (Tag $tag, int $index) use ($start) {
            return [
                $tag->getKey() => ['order' => $start + $index],
            ];
        })->all();
    }

    /**
     * Build a pivot payload for tags that are not yet attached, appending them in list order.
     */
    protected function newTaggableOrderPayload(Collection $tags): array
    {
        $attached = $this->tags()->allRelatedIds()->map(function ($id) {
            return (string) $id;
        });

        return $this->taggableOrderPayload(
            $tags->reject(function (Tag $tag) use ($attached) {
                return $attached->contains((string) $tag->getKey());
            })->values(),
            $this->nextTaggableOrder(),
        );
    }

    /**
     * Get the next pivot order for tags attached to this model.
     */
    protected function nextTaggableOrder(): int
    {
        return (int) $this->tags()->max('taggables.order') + 1;
    }

    /**
     * Normalize findOrCreate results into a collection of tags.
     */
    protected function normalizeTags(mixed $tags): Collection
    {
        if ($tags instanceof Tag) {
            return collect([$tags]);
        }

        return collect($tags)->filter(function ($tag) {
            return filled($tag);
        })->values();
    }

    /**
     * Wrap the given tag input into a list of values to resolve.
     */
    protected function normalizeTagInput(mixed $tags): Collection
    {
        $class = TagCreator::$tagModel;

        if ($tags instanceof Tag || $class::isSnapshot($tags) || is_string($tags) || ! is_iterable($tags)) {
            return collect([$tags])->filter(function ($tag) {
                return filled($tag);
            })->values();
        }

        return collect($tags)->filter(function ($tag) {
            return filled($tag);
        })->values();
    }

    /**
     * Locate existing tags without creating new ones.
     */
    protected function locateTags(mixed $tags, mixed $type = null): Collection
    {
        $type = enum_value($type);

        $class = TagCreator::$tagModel;

        return $this->normalizeTagInput($tags)->map(function ($value) use ($class, $type) {
            if ($value instanceof Tag) {
                return $value;
            }

            if ($class::isSnapshot($value)) {
                return $class::findFromString(data_get($value, 'name'), data_get($value, 'type', $type));
            }

            return $class::findFromString((string) $value, $type);
        })->filter(function ($tag) {
            return filled($tag);
        })->values();
    }
}
