<?php

namespace App\Domain\Analytics\Query;

/**
 * Dimension filters shared by every analytics query. Empty list = no filter.
 * IDs are always re-scoped to the organization in SQL; a foreign id simply
 * matches nothing.
 */
final readonly class Filters
{
    /**
     * @param  list<int>  $branchIds
     * @param  list<int>  $categoryIds
     * @param  list<int>  $departmentIds
     */
    public function __construct(
        public array $branchIds = [],
        public array $categoryIds = [],
        public array $departmentIds = [],
    ) {}

    public function withBranch(int $id): self
    {
        return new self([$id], $this->categoryIds, $this->departmentIds);
    }

    public function withCategory(int $id): self
    {
        return new self($this->branchIds, [$id], $this->departmentIds);
    }

    public function isEmpty(): bool
    {
        return $this->branchIds === [] && $this->categoryIds === [] && $this->departmentIds === [];
    }

    /** @return array{branches: list<int>, categories: list<int>, departments: list<int>} */
    public function toArray(): array
    {
        return ['branches' => $this->branchIds, 'categories' => $this->categoryIds, 'departments' => $this->departmentIds];
    }

    public function fingerprint(): string
    {
        $sorted = fn (array $ids) => implode(',', (function () use ($ids) {
            sort($ids);

            return $ids;
        })());

        return "b={$sorted($this->branchIds)};c={$sorted($this->categoryIds)};d={$sorted($this->departmentIds)}";
    }
}
