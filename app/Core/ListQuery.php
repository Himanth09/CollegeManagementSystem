<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Builds WHERE clauses, whitelisted sorting and pagination for list endpoints.
 * Sort columns are mapped from a whitelist; user input never reaches SQL text.
 */
final class ListQuery
{
    public array $where = [];
    public array $params = [];

    public function add(string $sql, mixed ...$params): self
    {
        $this->where[] = $sql;
        array_push($this->params, ...$params);
        return $this;
    }

    /** Adds a filter only when the value is not null. */
    public function when(mixed $value, string $sql, int $repeat = 1): self
    {
        if ($value !== null && $value !== '') {
            $this->add($sql, ...array_fill(0, $repeat, $value));
        }
        return $this;
    }

    /** LIKE search across several columns. */
    public function search(?string $term, array $columns): self
    {
        if ($term === null || $term === '') {
            return $this;
        }
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $parts = array_map(static fn ($c) => "$c LIKE ?", $columns);
        return $this->add('(' . implode(' OR ', $parts) . ')', ...array_fill(0, count($columns), $like));
    }

    public function whereSql(): string
    {
        return $this->where ? ' WHERE ' . implode(' AND ', $this->where) : '';
    }

    /** @param array<string,string> $sortMap public key => SQL expression */
    public static function orderBy(array $sortMap, string $default, string $defaultDir = 'asc'): string
    {
        $sort = Request::queryStr('sort');
        $dir = strtolower((string) Request::queryStr('dir')) === 'desc' ? 'DESC' : 'ASC';
        if ($sort === null || !isset($sortMap[$sort])) {
            $sort = $default;
            $dir = strtoupper($defaultDir) === 'DESC' ? 'DESC' : 'ASC';
        }
        return ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir;
    }

    /**
     * Runs a paginated query.
     * @return array{data: array, meta: array}
     */
    public function paginate(string $select, string $from, string $orderBy, int $defaultPer = 15, ?string $countFrom = null): array
    {
        $page = max(1, (int) Request::queryInt('page', 1));
        $per = (int) Request::queryInt('per_page', $defaultPer);
        $per = max(5, min(100, $per));
        $where = $this->whereSql();
        // $countFrom: a cheaper FROM for the count when the joins cannot change the row count.
        $total = (int) Database::value('SELECT COUNT(*) ' . ($countFrom ?? $from) . " $where", $this->params);
        $pages = max(1, (int) ceil($total / $per));
        $page = min($page, $pages);
        $offset = ($page - 1) * $per;
        $rows = Database::all("SELECT $select $from $where $orderBy LIMIT $per OFFSET $offset", $this->params);
        return ['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total, 'pages' => $pages]];
    }

    /** Same query without pagination (reports). Pass a limit to cap on-screen views. */
    public function all(string $select, string $from, string $orderBy, ?int $limit = null): array
    {
        $sql = "SELECT $select $from {$this->whereSql()} $orderBy" . ($limit !== null ? ' LIMIT ' . max(1, $limit) : '');
        return Database::all($sql, $this->params);
    }

    /** Streams every matching row (CSV exports) without loading them all into a PHP array. */
    public function cursor(string $select, string $from, string $orderBy): \Generator
    {
        return Database::cursor("SELECT $select $from {$this->whereSql()} $orderBy", $this->params);
    }
}
