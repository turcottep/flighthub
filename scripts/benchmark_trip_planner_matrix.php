#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\TripPlannerMatrixBenchmark;

require __DIR__.'/../vendor/autoload.php';

$options = getopt('', [
    'data::',
    'per-group::',
    'planner::',
    'repeats::',
    'warmups::',
    'json',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<'TXT'
Usage:
  php -d memory_limit=1536M scripts/benchmark_trip_planner_matrix.php [options]

Options:
  --data=PATH       Trip data JSON path. Default: data/generated/trip_data_full.json
  --planner=v3|v4   Planner implementation to benchmark. Default: v4
  --per-group=N     Queries sampled per benchmark group. Default: 20
  --repeats=N       Timed runs per query. Default: 3
  --warmups=N       Warmup runs per query before timing. Default: 1
  --json            Emit JSON instead of a text table
  --help            Show this help

TXT);
    exit(0);
}

ini_set('memory_limit', '1536M');

$dataPath = $options['data'] ?? (__DIR__.'/../data/generated/trip_data_full.json');
$plannerVersion = strtolower((string) ($options['planner'] ?? 'v4'));
$perGroup = isset($options['per-group']) ? (int) $options['per-group'] : 20;
$repeats = isset($options['repeats']) ? (int) $options['repeats'] : 3;
$warmups = isset($options['warmups']) ? (int) $options['warmups'] : 1;

$startedAt = hrtime(true);
$benchmark = TripPlannerMatrixBenchmark::fromDataPath($dataPath, plannerVersion: $plannerVersion);
$setupMs = (hrtime(true) - $startedAt) / 1_000_000;
$report = $benchmark->run($perGroup, $repeats, $warmups);
$report['planner'] = $plannerVersion;
$report['setup_ms'] = round($setupMs, 2);
$report['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1_048_576, 1);

if (isset($options['json'])) {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT)."\n");
    exit(0);
}

printf(
    "TripPlanner matrix benchmark (%s)\nData: %d airlines, %d airports, %d flights\nSetup: %.2f ms, peak memory: %.1f MB\nQueries: %d, timed executions: %d, repeats: %d, warmups: %d\n\n",
    $report['planner'],
    $report['data']['airlines'],
    $report['data']['airports'],
    $report['data']['flights'],
    $report['setup_ms'],
    $report['peak_memory_mb'],
    $report['case_count'],
    $report['timed_executions'],
    $report['repeats'],
    $report['warmups'],
);

printf("%-24s %7s %7s %7s %7s %7s %7s %7s %7s\n", 'group', 'queries', 'execs', 'p50', 'p75', 'p90', 'p95', 'p99', 'max');
printf("%'-88s\n", '');

foreach ($report['groups'] as $group => $summary) {
    printf(
        "%-24s %7d %7d %6.1f %6.1f %6.1f %6.1f %6.1f %6.1f\n",
        $group,
        $summary['queries'],
        $summary['executions'],
        $summary['p50_ms'],
        $summary['p75_ms'],
        $summary['p90_ms'],
        $summary['p95_ms'],
        $summary['p99_ms'],
        $summary['max_ms'],
    );
}

$overall = $report['overall'];
printf("%'-88s\n", '');
printf(
    "%-24s %7d %7d %6.1f %6.1f %6.1f %6.1f %6.1f %6.1f\n",
    'overall',
    $overall['queries'],
    $overall['executions'],
    $overall['p50_ms'],
    $overall['p75_ms'],
    $overall['p90_ms'],
    $overall['p95_ms'],
    $overall['p99_ms'],
    $overall['max_ms'],
);
