<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TripSearchSessionStore
{
    public const DEFAULT_PER_PAGE = 10;

    public const MAX_PER_PAGE = 50;

    private const TTL_MINUTES = 5;

    /**
     * @param array<string, mixed> $params
     * @param list<array<string, mixed>> $results
     * @return array{data: list<array<string, mixed>>, meta: array{pagination: array<string, mixed>}}
     */
    public function create(string $type, array $params, array $results, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = $this->normalizePerPage($perPage);
        $searchId = (string) Str::uuid();
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);
        $resultCount = count($results);

        DB::transaction(function () use ($searchId, $type, $params, $results, $expiresAt, $resultCount): void {
            DB::table('search_sessions')->insert([
                'id' => $searchId,
                'type' => $type,
                'search_hash' => $this->hashParams($type, $params),
                'params' => $this->encodeJson($params),
                'result_count' => $resultCount,
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (array_chunk($results, 100) as $chunkOffset => $chunk) {
                $rows = [];

                foreach ($chunk as $index => $result) {
                    $rows[] = [
                        'search_session_id' => $searchId,
                        'position' => ($chunkOffset * 100) + $index + 1,
                        'itinerary' => $this->encodeJson($result),
                    ];
                }

                if ($rows !== []) {
                    DB::table('search_session_results')->insert($rows);
                }
            }
        });

        return $this->formatPage(
            searchId: $searchId,
            results: $this->sliceResults($results, $page, $perPage),
            total: $resultCount,
            page: $page,
            perPage: $perPage,
            expiresAt: $expiresAt->toAtomString(),
        );
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{pagination: array<string, mixed>}}|null
     */
    public function page(string $searchId, int $page, int $perPage): ?array
    {
        $page = max(1, $page);
        $perPage = $this->normalizePerPage($perPage);
        $session = DB::table('search_sessions')
            ->where('id', $searchId)
            ->where('expires_at', '>', now())
            ->first(['id', 'result_count', 'expires_at']);

        if ($session === null) {
            return null;
        }

        $rows = DB::table('search_session_results')
            ->where('search_session_id', $searchId)
            ->orderBy('position')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->pluck('itinerary')
            ->all();

        $results = array_map(function (mixed $itinerary): array {
            if (is_array($itinerary)) {
                return $itinerary;
            }

            return json_decode((string) $itinerary, true, flags: JSON_THROW_ON_ERROR);
        }, $rows);

        return $this->formatPage(
            searchId: $searchId,
            results: $results,
            total: (int) $session->result_count,
            page: $page,
            perPage: $perPage,
            expiresAt: (string) $session->expires_at,
        );
    }

    public function pruneExpired(): int
    {
        return DB::table('search_sessions')
            ->where('expires_at', '<=', now())
            ->delete();
    }

    private function normalizePerPage(int $perPage): int
    {
        return max(1, min(self::MAX_PER_PAGE, $perPage));
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    private function sliceResults(array $results, int $page, int $perPage): array
    {
        return array_slice($results, ($page - 1) * $perPage, $perPage);
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return array{data: list<array<string, mixed>>, meta: array{pagination: array<string, mixed>}}
     */
    private function formatPage(string $searchId, array $results, int $total, int $page, int $perPage, string $expiresAt): array
    {
        $totalPages = max(1, (int) ceil($total / $perPage));

        return [
            'data' => $results,
            'meta' => [
                'pagination' => [
                    'search_id' => $searchId,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'has_previous' => $page > 1,
                    'has_next' => $page < $totalPages,
                    'expires_at' => $expiresAt,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function hashParams(string $type, array $params): string
    {
        $params = $this->sortRecursive($params);

        return hash('sha256', $type.'|'.$this->encodeJson($params));
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function sortRecursive(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        return $value;
    }
}
