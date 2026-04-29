#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\TripPlannerBenchmark;

require __DIR__.'/../vendor/autoload.php';

$options = getopt('', [
    'data::',
    'case::',
    'repeats::',
    'warmups::',
    'json',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<'TXT'
Usage:
  php -d memory_limit=1536M scripts/benchmark_trip_planner.php [options]

Options:
  --data=PATH       Trip data JSON path. Default: data/generated/trip_data_full.json
  --case=NAME       Benchmark case to run. Repeatable.
  --repeats=N       Timed runs per case. Default: 5
  --warmups=N       Warmup runs per case before timing. Default: 1
  --json            Emit JSON instead of a text table
  --help            Show this help

TXT);
    exit(0);
}

ini_set('memory_limit', '1536M');

$dataPath = $options['data'] ?? (__DIR__.'/../data/generated/trip_data_full.json');
$repeats = isset($options['repeats']) ? (int) $options['repeats'] : 5;
$warmups = isset($options['warmups']) ? (int) $options['warmups'] : 1;
$caseNames = $options['case'] ?? null;

if (is_string($caseNames)) {
    $caseNames = [$caseNames];
}

$startedAt = hrtime(true);
$benchmark = TripPlannerBenchmark::fromDataPath($dataPath);
$setupMs = (hrtime(true) - $startedAt) / 1_000_000;
$report = $benchmark->run($caseNames, $repeats, $warmups);
$report['setup_ms'] = round($setupMs, 2);
$report['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1_048_576, 1);

if (isset($options['json'])) {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT)."\n");
    exit(0);
}

printf(
    "TripPlanner benchmark\nData: %d airlines, %d airports, %d flights\nSetup: %.2f ms, peak memory: %.1f MB\nRepeats: %d, warmups: %d\n\n",
    $report['data']['airlines'],
    $report['data']['airports'],
    $report['data']['flights'],
    $report['setup_ms'],
    $report['peak_memory_mb'],
    $report['repeats'],
    $report['warmups'],
);

printf("%-26s %8s %9s %9s %9s %9s %s\n", 'case', 'results', 'min', 'p50', 'p95', 'max', 'description');
printf("%'-92s\n", '');

foreach ($report['cases'] as $case) {
    printf(
        "%-26s %8d %8.2fms %8.2fms %8.2fms %8.2fms %s\n",
        $case['name'],
        $case['results'],
        $case['min_ms'],
        $case['p50_ms'],
        $case['p95_ms'],
        $case['max_ms'],
        $case['description'],
    );
}

printf(
    "\nSummary: cases=%d p50=%.2fms p95=%.2fms max=%.2fms\n",
    $report['summary']['case_count'],
    $report['summary']['p50_ms'],
    $report['summary']['p95_ms'],
    $report['summary']['max_ms'],
);
