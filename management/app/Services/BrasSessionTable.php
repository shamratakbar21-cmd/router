<?php

namespace App\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class BrasSessionTable
{
    private const COLUMNS = ['interface', 'username', 'calling_station', 'address', 'rate_limit', 'type', 'compression', 'state', 'uptime'];

    /** @return array<int, array<string, string>> */
    public function parse(string $table): array
    {
        $rows = [];
        foreach (explode("\n", $table) as $line) {
            $cells = array_map('trim', explode('|', trim($line)));
            if (count($cells) !== count(self::COLUMNS) || $cells[0] === 'ifname' || str_starts_with($cells[0], '-')) {
                continue;
            }
            if (count($rows) >= 100000) {
                break;
            }
            $rows[] = array_combine(self::COLUMNS, $cells);
        }

        return $rows;
    }

    /** @param array<int, array<string, string>> $rows */
    public function page(array $rows, string $query, string $sort, int $page, string $url): LengthAwarePaginator
    {
        if ($query !== '') {
            $rows = array_values(array_filter($rows, fn (array $row): bool => str_contains(strtolower(implode(' ', $row)), strtolower($query))));
        }
        $allowed = ['username', 'address', 'state', 'uptime'];
        $sort = in_array($sort, $allowed, true) ? $sort : 'username';
        usort($rows, static function (array $a, array $b) use ($sort): int {
            return strnatcmp($a[$sort], $b[$sort]) ?: strnatcmp($a['interface'], $b['interface']);
        });
        $page = max(1, min($page, 100000));
        $perPage = 25;

        return (new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page, ['path' => $url]))
            ->appends(Arr::except(request()->query(), 'page'));
    }
}
