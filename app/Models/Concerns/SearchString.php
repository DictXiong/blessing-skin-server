<?php

// This file was generated entirely by AI.
// It has not been reviewed line by line by a human.

namespace App\Models\Concerns;

use App\Services\Search\Applier;
use App\Services\Search\Parser;
use Illuminate\Database\Eloquent\Builder;

trait SearchString
{
    public function scopeUsingSearchString(Builder $query, ?string $search): Builder
    {
        if ($search === null || trim($search) === '') {
            return $query;
        }
        try {
            return (new Applier($this->searchStringColumns))->apply(clone $query, (new Parser())->parse($search));
        } catch (\InvalidArgumentException $exception) {
            return $query->whereRaw('1 = 0');
        }
    }
}
