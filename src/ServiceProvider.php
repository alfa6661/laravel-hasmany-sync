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
        HasMany::macro('sync', function (array $data, $deleting = true, $softDelete = true, $touchParent = true) {
            $changes = [
                'created' => [], 'deleted' => [], 'updated' => [], 'update_failed' => [],
            ];

            /** @var HasMany $this */

            // Get the primary key.
            $relatedKeyName = $this->getRelated()->getKeyName();

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
                if ($softDelete) {
                    // if the model has soft delete, soft delete it
                    // otherwise, performed as same as the destroy function.
                    $this->getRelated()->delete($deletedKeys);
                } else {
                    $this->getRelated()->destroy($deletedKeys);
                }
                $changes['deleted'] = $deletedKeys;
            }

            // Separate the submitted data into "update" and "new"
            // We determine "newRows" as those whose $relatedKeyName (usually 'id') is null.
            // sometime front end pass a row with a negative id to indicate a new row
            $newRows = Arr::where($data, function ($row) use ($relatedKeyName) {
                return Arr::get($row, $relatedKeyName) === null || Arr::get($row, $relatedKeyName) < 0;
            });

            // We determine "updateRows" as those whose $relatedKeyName (usually 'id') is set, not null.
            $updatedRows = Arr::where($data, function ($row) use ($relatedKeyName) {
                return Arr::get($row, $relatedKeyName) !== null && Arr::get($row, $relatedKeyName) > 0;
            });

            if (count($newRows) > 0) {
                $newRecords = $this->createMany($newRows);
                $changes['created'] = $castKeys(
                    $newRecords->pluck($relatedKeyName)->toArray()
                );
            }

            foreach ($updatedRows as $row) {
                if ($this->getRelated()->where($relatedKeyName, $castKey(Arr::get($row, $relatedKeyName)))
                ->update($row)) {
                    $changes['updated'][] = $castKey($row[$relatedKeyName]);
                } else {
                    // updating failed, probably because of giving an invalid id. 
                    $changes['update_failed'][] = $castKey($row[$relatedKeyName]);
                }
            }

            if ($touchParent) {
                // update the timestamp of the parent
                $this->parent->touch();
            }

            return $changes;
        });
    }
}
