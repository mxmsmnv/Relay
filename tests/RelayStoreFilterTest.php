<?php

declare(strict_types=1);

namespace ProcessWire;

class WireDatabasePDO extends \PDO
{
}

class Wire
{
    public function __construct(private WireDatabasePDO $database)
    {
    }

    public function wire(string $name): WireDatabasePDO
    {
        if ($name !== 'database') throw new \RuntimeException('Unknown test service: ' . $name);
        return $this->database;
    }
}

require_once dirname(__DIR__) . '/src/RelayStore.php';

$installDb = new WireDatabasePDO('sqlite::memory:');
$installDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$installStore = new RelayStore(new Wire($installDb));
$installStore->install();
if ((int)$installDb->query('SELECT COUNT(*) FROM relay_jobs')->fetchColumn() !== 0) {
    fwrite(STDERR, "Relay must install an empty jobs table.\n");
    exit(1);
}
if ((int)$installDb->query('SELECT COUNT(*) FROM relay_presets')->fetchColumn() !== 6) {
    fwrite(STDERR, "Relay must install six useful cadence presets.\n");
    exit(1);
}
$minutePreset = $installDb->query("SELECT interval_value, frequency FROM relay_presets WHERE name='Every 69 minutes'")->fetch(\PDO::FETCH_ASSOC);
if (!$minutePreset || (int)$minutePreset['interval_value'] !== 69 || $minutePreset['frequency'] !== 'minute') {
    fwrite(STDERR, "Relay must seed a real 69-minute cadence preset.\n");
    exit(1);
}
$customPresetId = $installStore->savePreset([
    'name'=>'Editorial sprint', 'template'=>'article', 'action'=>'window', 'start_time'=>'10:30',
    'frequency'=>'week', 'interval'=>2, 'weekdays'=>[2,4], 'ends'=>'after', 'until_days'=>60,
    'occurrences'=>6, 'window_minutes'=>2880, 'note'=>'Reviewed preset',
], 41);
$savedPreset = array_values(array_filter($installStore->presets(), static fn(array $preset): bool => (int)$preset['id'] === $customPresetId))[0] ?? null;
if (!$savedPreset || $savedPreset['weekdays'] !== [2,4] || $savedPreset['template'] !== 'article') {
    fwrite(STDERR, "Quick presets must round-trip bounded cadence and template data.\n");
    exit(1);
}
$updatedPresetId = $installStore->savePreset(array_merge($savedPreset, ['start_time'=>'11:45']), 42);
if ($updatedPresetId !== $customPresetId || count($installStore->presets()) !== 7) {
    fwrite(STDERR, "Saving the same quick preset name must update instead of duplicate it.\n");
    exit(1);
}
if (!$installStore->deletePreset($customPresetId) || count($installStore->presets()) !== 6) {
    fwrite(STDERR, "Quick presets must be deletable without affecting the built-in library.\n");
    exit(1);
}

$db = new WireDatabasePDO('sqlite::memory:');
$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY, templates_id INTEGER NOT NULL)');
$db->exec('CREATE TABLE relay_jobs (
    id INTEGER PRIMARY KEY, page_id INTEGER NOT NULL, action TEXT NOT NULL,
    scheduled_at TEXT NOT NULL, status TEXT NOT NULL
)');

$insertPage = $db->prepare('INSERT INTO pages (id, templates_id) VALUES (?, ?)');
$insertJob = $db->prepare('INSERT INTO relay_jobs (id, page_id, action, scheduled_at, status) VALUES (?, ?, ?, ?, ?)');
$start = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
for ($id = 1; $id <= 510; $id++) {
    $templateId = $id <= 500 ? 1 : 2;
    $insertPage->execute([$id, $templateId]);
    $insertJob->execute([$id, $id, 'publish', $start->modify('+' . $id . ' minutes')->format('Y-m-d H:i:s'), 'scheduled']);
}

$store = new RelayStore(new Wire($db));
$jobs = $store->between(
    $start,
    $start->modify('+1 year'),
    501,
    null,
    null,
    null,
    2
);

if (count($jobs) !== 10 || (int)$jobs[0]['id'] !== 501 || (int)$jobs[9]['id'] !== 510) {
    fwrite(STDERR, "Template filtering must happen in SQL before the calendar result limit.\n");
    exit(1);
}

$templateIds = $store->templateIdsBetween($start, $start->modify('+1 year'), null, 'scheduled', 'publish');
if ($templateIds !== [1, 2]) {
    fwrite(STDERR, "Calendar template choices must come from templates that have matching actions in the current range.\n");
    exit(1);
}

echo "Relay store installation and filter tests passed.\n";
