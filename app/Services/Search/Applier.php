<?php

// This file was generated entirely by AI.
// It has not been reviewed line by line by a human.
// Adapted from mk990/blessing-skin-server 55597dbd (MIT); see SOURCE.md.

namespace App\Services\Search;

use Illuminate\Database\Eloquent\Builder;

class Applier
{
    private array $columns = [];
    private array $aliases = [];
    private array $searchable = [];

    public function __construct(array $definition)
    {
        foreach ($definition as $key => $value) {
            $column = is_int($key) ? $value : $key;
            $options = is_int($key) ? [] : $value;
            $this->columns[$column] = $options;
            $this->aliases[$column] = $column;
            foreach ($options['alias'] ?? [] as $alias) {
                $this->aliases[$alias] = $column;
            }
            if ($options['searchable'] ?? false) {
                $this->searchable[] = $column;
            }
        }
    }

    public function apply(Builder $query, ?array $node): Builder
    {
        $node = $this->extractKeywords($query, $node);
        if ($node !== null) {
            $this->applyNode($query, $node);
        }

        return $query;
    }

    private function extractKeywords(Builder $query, ?array $node, bool $negated = false): ?array
    {
        if ($node === null) {
            return null;
        }
        if (in_array($node['type'], ['constraint', 'in'], true) && in_array($node['field'], ['sort', 'fields', 'limit', 'from'], true)) {
            $values = $node['type'] === 'in' ? $node['values'] : [$node['value']];
            $this->applyKeyword($query, $node['field'], $values, $negated || ($node['operator'] ?? '=') === '!=');

            return null;
        }
        if ($node['type'] === 'not') {
            $node['node'] = $this->extractKeywords($query, $node['node'], !$negated);

            return $node['node'] === null ? null : $node;
        }
        if (in_array($node['type'], ['and', 'or'], true)) {
            $node['nodes'] = array_values(array_filter(array_map(fn ($child) => $this->extractKeywords($query, $child, $negated), $node['nodes'])));

            return match (count($node['nodes'])) {
                0 => null,
                1 => $node['nodes'][0],
                default => $node,
            };
        }

        return $node;
    }

    private function column(Builder $query, string $field): string
    {
        if (!isset($this->aliases[$field])) {
            throw new \InvalidArgumentException('Unknown search column.');
        }

        return $query->qualifyColumn($this->aliases[$field]);
    }

    private function applyKeyword(Builder $query, string $keyword, array $values, bool $negated): void
    {
        if (in_array($keyword, ['limit', 'from'], true)) {
            if ($negated || count($values) !== 1 || !is_string($values[0]) || !ctype_digit($values[0]) || filter_var($values[0], FILTER_VALIDATE_INT) === false) {
                throw new \InvalidArgumentException('Paging values must be non-negative integers.');
            }
            $keyword === 'limit' ? $query->limit((int) $values[0]) : $query->offset((int) $values[0]);

            return;
        }
        if ($keyword === 'sort') {
            $query->reorder();
            foreach ($values as $value) {
                if (!is_string($value) || $negated) {
                    throw new \InvalidArgumentException('Invalid sort value.');
                }
                $descending = str_starts_with($value, '-');
                $query->orderBy($this->column($query, $descending ? substr($value, 1) : $value), $descending ? 'desc' : 'asc');
            }

            return;
        }
        $columns = array_map(fn ($value) => $this->column($query, (string) $value), $values);
        if ($negated) {
            $columns = array_values(array_diff(array_map(fn ($field) => $this->column($query, $field), array_keys($this->columns)), $columns));
        }
        if ($columns === []) {
            throw new \InvalidArgumentException('No search columns selected.');
        }
        $query->select($columns);
    }

    private function applyNode(Builder $query, array $node, string $boolean = 'and'): void
    {
        match ($node['type']) {
            'and', 'or' => $query->where(function (Builder $nested) use ($node): void {
                foreach ($node['nodes'] as $child) {
                    $this->applyNode($nested, $child, $node['type']);
                }
            }, null, null, $boolean),
            'not' => $query->whereNot(function (Builder $nested) use ($node): void {
                $this->applyNode($nested, $node['node']);
            }, null, null, $boolean),
            'constraint' => $this->applyConstraint($query, $node, $boolean),
            'in' => $query->whereIn($this->column($query, $node['field']), $node['values'], $boolean),
            'term' => $this->applyTerm($query, $node['value'], $boolean),
        };
    }

    private function applyConstraint(Builder $query, array $node, string $boolean): void
    {
        $column = $this->column($query, $node['field']);
        $options = $this->columns[$this->aliases[$node['field']]];
        $operator = $node['operator'];
        $value = $node['value'];
        if ($value === null) {
            if (!in_array($operator, ['=', '!='], true)) {
                throw new \InvalidArgumentException('NULL only supports equality.');
            }
            $operator === '!=' ? $query->whereNotNull($column, $boolean) : $query->whereNull($column, $boolean);
        } elseif ($options['boolean'] ?? false) {
            $query->where($column, $operator, in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true), $boolean);
        } elseif ($options['date'] ?? false) {
            $this->applyDate($query, $column, $operator, $value, $boolean);
        } else {
            $query->where($column, $operator, $value, $boolean);
        }
    }

    private function applyTerm(Builder $query, string $value, string $boolean): void
    {
        $column = $this->aliases[$value] ?? null;
        if ($column !== null && ($this->columns[$column]['boolean'] ?? false)) {
            $query->where($this->column($query, $value), '=', true, $boolean);

            return;
        }
        if ($this->searchable === []) {
            return;
        }
        // An explicit portable ESCAPE character works with SQLite and MySQL.
        $pattern = '%'.strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        $query->where(function (Builder $nested) use ($pattern): void {
            foreach ($this->searchable as $column) {
                $wrapped = $nested->getQuery()->getGrammar()->wrap($this->column($nested, $column));
                $nested->orWhereRaw($wrapped." LIKE ? ESCAPE '!'", [$pattern]);
            }
        }, null, null, $boolean);
    }

    private function applyDate(Builder $query, string $column, string $operator, string $value, string $boolean): void
    {
        if (str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Invalid search date.');
        }
        $formats = ['Y' => '+1 year', 'Y-m' => '+1 month', 'Y-m-d' => '+1 day', 'Y-m-d H' => '+1 hour', 'Y-m-d H:i' => '+1 minute'];
        foreach ($formats + ['Y-m-d H:i:s' => null, 'Y-m-d H:i:s.u' => null] as $format => $interval) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date === false || $date->format($format) !== $value) {
                continue;
            }
            $start = $date->format('Y-m-d H:i:s.u');
            // Drop zero fractional seconds to compare consistently with SQLite's stored timestamps.
            $start = str_ends_with($start, '.000000') ? substr($start, 0, -7) : $start;
            if ($interval === null) {
                $query->where($column, $operator, $start, $boolean);

                return;
            }
            $end = $date->modify($interval)->format('Y-m-d H:i:s');
            if ($operator === '=' || $operator === '!=') {
                $query->where(function (Builder $nested) use ($column, $operator, $start, $end): void {
                    if ($operator === '=') {
                        $nested->where($column, '>=', $start)->where($column, '<', $end);
                    } else {
                        $nested->where($column, '<', $start)->orWhere($column, '>=', $end);
                    }
                }, null, null, $boolean);
            } else {
                [$comparison, $boundary] = match ($operator) {
                    '>' => ['>=', $end], '<=' => ['<', $end],
                    '<' => ['<', $start], '>=' => ['>=', $start],
                };
                $query->where($column, $comparison, $boundary, $boolean);
            }

            return;
        }
        throw new \InvalidArgumentException('Invalid search date.');
    }
}
