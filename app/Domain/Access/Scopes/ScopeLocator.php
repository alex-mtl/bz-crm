<?php

declare(strict_types=1);

namespace App\Domain\Access\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tells the authorization service where an object lives on the two scope axes (ADR-008):
 * the org-unit tree and the territory tree. Paths are materialized ("/1/7/42/"),
 * so "inside the scope" is a prefix comparison — in PHP and in SQL alike.
 */
interface ScopeLocator
{
    /**
     * @return list<string> paths of the org units the object belongs to
     */
    public function unitPaths(object $object): array;

    /**
     * @return list<string> paths of the territories the object belongs to
     */
    public function territoryPaths(object $object): array;

    /**
     * Adds "belongs to one of these unit subtrees" to the query (inside an OR group).
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $unitPaths
     */
    public function whereInUnits(Builder $query, array $unitPaths): void;

    /**
     * Adds "belongs to one of these territory subtrees" to the query (inside an OR group).
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $territoryPaths
     */
    public function whereInTerritories(Builder $query, array $territoryPaths): void;
}
