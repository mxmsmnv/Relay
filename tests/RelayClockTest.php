<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/RelayClock.php';

use ProcessWire\RelayClock;

function expectSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "$message\nExpected: $expected\nActual: $actual\n");
        exit(1);
    }
}

$utc = RelayClock::localToUtc('2026-08-13T09:30', 'America/New_York');
expectSame('2026-08-13 13:30:00', $utc->format('Y-m-d H:i:s'), 'Summer timezone conversion failed.');
expectSame('2026-08-13 09:30', RelayClock::utcToLocal('2026-08-13 13:30:00', 'America/New_York'), 'UTC rendering failed.');

$thrown = false;
try {
    RelayClock::localToUtc('2026-03-08T02:30', 'America/New_York');
} catch (InvalidArgumentException $e) {
    $thrown = true;
}
expectSame(true, $thrown, 'DST gap must be rejected.');

expectSame(
    '2026-12-13 17:00:00',
    RelayClock::localToUtc('2026-12-13T09:00', 'America/Los_Angeles')->format('Y-m-d H:i:s'),
    'Winter timezone conversion failed.'
);

echo "RelayClock tests passed.\n";
