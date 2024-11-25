<?php

namespace Alfa6661\EloquentHasManySync;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Support\Arr;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        HasMany::macro('sync', function (array $data, $relatedKeyName = null, $deleting = true, $supportDeleteEvents = true) {
            $changes = [
                'created' => [], 'deleted' => [], 'updated' => [],
            ];

            /** @var HasMany $this */

            // Get the relation's matching key.
            $relatedKeyName = $relatedKeyName ?? $this->getRelated()->getKeyName();

            // Get the current key values.
            $current = $this->newQuery()->pluck($relatedKeyName)->all();

            // Cast the given key to an integer if it is numeric.
            $castKey = function ($value) {
                if (is_null($value)) {
                    return $value;
                }

                return is_numeric($value) ? (int) $value : (string) $value;
            };

            // Cast the given keys to integers if they are numeric and string otherwise.
            $castKeys = function ($keys) use ($castKey) {
                return (array) array_map(function ($key) use ($castKey) {
                    return $castKey($key);
                }, $keys);
            };

            // Get any non-matching rows.
            $deletedKeys = array_diff($current, $castKeys(
                Arr::pluck($data, $relatedKeyName))
            );

            if ($deleting && count($deletedKeys) > 0) {
                $deletingQuery = $this->getRelated()
                    ->where($this->getForeignKeyName(), $this->getParentKey())
                    ->whereIn($relatedKeyName, $deletedKeys);

                if($supportDeleteEvents) {
                    $deletingRecords = $deletingQuery->get();

                    foreach ($deletingRecords as $deletingRecord) {
                        $deletingRecord->delete();
                    }
                } else {
                    $deletingQuery->delete();
                }

                $changes['deleted'] = $deletedKeys;
            }

            // Separate the submitted data into "update" and "new"
            // We determine "newRows" as those whose $relatedKeyName is NOT in $current
            $newRows = Arr::where($data, function ($row) use ($current, $relatedKeyName) {
                return ! in_array(Arr::get($row, $relatedKeyName), $current);
            });

            // We determine "updateRows" as those whose $relatedKeyName is already in $current
            $updatedRows = Arr::where($data, function ($row) use ($current, $relatedKeyName) {
                return in_array(Arr::get($row, $relatedKeyName), $current);
            });

            if (count($newRows) > 0) {
                $newRecords = $this->createMany($newRows);
                $changes['created'] = $castKeys(
                    $newRecords->pluck($relatedKeyName)->toArray()
                );
            }

            foreach ($updatedRows as $row) {
                $related = $this->getRelated()
                    ->where($this->getForeignKeyName(), $this->getParentKey())
                    ->where($relatedKeyName, $castKey(Arr::get($row, $relatedKeyName)))
                    ->first()
                    ->fill($row)
                    ->save();
            }

            $changes['updated'] = $castKeys(Arr::pluck($updatedRows, $relatedKeyName));

            return $changes;
        });
    }
}
