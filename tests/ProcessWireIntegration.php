<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit(1);
}

$options = getopt('', ['root:', 'run', 'help']);
if (isset($options['help']) || !isset($options['run'])) {
    echo "Usage: php tests/ProcessWireIntegration.php --run --root=/path/to/processwire\n";
    exit(isset($options['help']) ? 0 : 2);
}

$root = rtrim((string) ($options['root'] ?? ''), DIRECTORY_SEPARATOR);
$bootstrap = $root . '/wire/core/ProcessWire.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "ProcessWire root was not found.\n");
    exit(2);
}

require_once $bootstrap;
$config = \ProcessWire\ProcessWire::buildConfig($root);
$processWire = new \ProcessWire\ProcessWire($config);
$modules = $processWire->wire('modules');
$module = $modules->getModule('Relay', ['noPermissionCheck' => true]);
if (!$module instanceof \ProcessWire\Relay) {
    fwrite(STDERR, "Relay is not installed in this ProcessWire instance.\n");
    exit(3);
}

$users = $processWire->wire('users');
$superuser = $users->get((int) $config->superUserPageID);
if (!$superuser->id) {
    fwrite(STDERR, "Superuser was not found.\n");
    exit(3);
}
$users->setCurrentUser($superuser);

$pages = $processWire->wire('pages');
$database = $processWire->wire('database');
$processWire->wire('page', $pages->get((int) $config->adminRootPageID));
$originalImitationMode = (int) $module->imitation_mode;
$module->set('imitation_mode', 0);
$testPage = null;
$jobIds = [];

try {
    $template = $processWire->wire('templates')->get('basic-page');
    if (!$template instanceof \ProcessWire\Template || !$template->id) {
        $template = null;
        foreach ($processWire->wire('templates')->find('flags=0, sort=id') as $candidate) {
            if ($candidate->name !== 'admin' && !$candidate->noUnpublish) {
                $template = $candidate;
                break;
            }
        }
    }
    if (!$template instanceof \ProcessWire\Template || !$template->id) {
        throw new RuntimeException('No suitable template is available for the integration test.');
    }

    $testPage = $pages->newPage($template);
    $testPage->parent = $pages->get(1);
    $testPage->name = 'schedule-integration-' . bin2hex(random_bytes(5));
    $testPage->title = 'Relay integration test';
    $testPage->addStatus(\ProcessWire\Page::statusUnpublished);
    $pages->save($testPage);

    $reflection = new ReflectionMethod($module, 'store');
    /** @var \ProcessWire\RelayStore $store */
    $store = $reflection->invoke($module);
    $timezone = 'UTC';
    $future = new DateTimeImmutable('+1 hour', new DateTimeZone('UTC'));

    $publishId = $store->schedule(
        (int) $testPage->id,
        'publish',
        $future,
        $timezone,
        (int) $superuser->id,
        (int) $superuser->id,
        'Automated integration test'
    );
    $jobIds[] = $publishId;
    $database->prepare("UPDATE relay_jobs SET scheduled_at=:due WHERE id=:id")
        ->execute([':due' => gmdate('Y-m-d H:i:s', time() - 5), ':id' => $publishId]);
    $first = $module->runDue(1, 'integration-test');
    $pages->uncache($testPage);
    $testPage = $pages->get((int) $testPage->id);
    if ($testPage->isUnpublished() || $first['completed'] !== 1) {
        throw new RuntimeException('Scheduled publish did not complete.');
    }

    $unpublishId = $store->schedule(
        (int) $testPage->id,
        'unpublish',
        $future,
        $timezone,
        (int) $superuser->id,
        (int) $superuser->id,
        'Automated integration test'
    );
    $jobIds[] = $unpublishId;
    $database->prepare("UPDATE relay_jobs SET scheduled_at=:due WHERE id=:id")
        ->execute([':due' => gmdate('Y-m-d H:i:s', time() - 5), ':id' => $unpublishId]);
    $second = $module->runDue(1, 'integration-test');
    $pages->uncache($testPage);
    $testPage = $pages->get((int) $testPage->id);
    if (!$testPage->isUnpublished() || $second['completed'] !== 1) {
        throw new RuntimeException('Scheduled unpublish did not complete.');
    }

    $visibleRelayId = $module->scheduleAction(
        $testPage,
        'publish',
        (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days'),
        $superuser,
        'Page outcome visibility test'
    );
    $jobIds[] = $visibleRelayId;
    $nextScheduled = $store->nextScheduledForPage((int) $testPage->id);
    if (!$nextScheduled || (int) $nextScheduled['id'] !== $visibleRelayId) {
        throw new RuntimeException('Next scheduled page action was not returned.');
    }
    $rescheduledAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+3 days');
    if (!$store->reschedule($visibleRelayId, $rescheduledAt, $timezone, (int) $superuser->id, 'Rescheduled integration test')) {
        throw new RuntimeException('Pending page action could not be rescheduled.');
    }
    $rescheduled = $store->get($visibleRelayId);
    if (!$rescheduled || $rescheduled['scheduled_at'] !== $rescheduledAt->format('Y-m-d H:i:s')) {
        throw new RuntimeException('Rescheduled page action did not retain its new time.');
    }
    $windowIds = $module->schedulePublicationWindow(
        $testPage,
        (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+4 days'),
        (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+11 days'),
        $superuser,
        'Publication window integration test'
    );
    $jobIds[] = $windowIds['publish'];
    $jobIds[] = $windowIds['unpublish'];
    if (($store->get($visibleRelayId)['status'] ?? '') !== 'superseded') {
        throw new RuntimeException('Publication window did not supersede the previous publish action.');
    }

    foreach (['enable_agent_api', 'enable_rest_api', 'enable_interface_cli'] as $setting) $module->set($setting, 1);
    $api = $module->api($superuser);
    if (!$api->canRead() || !$api->canWrite()) {
        throw new RuntimeException('The enabled operational PHP facade denied the superuser.');
    }
    $capabilities = $api->capabilities();
    if (empty($capabilities['channels']['php_api']) || empty($capabilities['channels']['rest']) || empty($capabilities['channels']['cli'])) {
        throw new RuntimeException('The interface capability manifest does not reflect enabled channels.');
    }
    $telegramStatus = $module->telegramIntegrationStatus();
    if (empty($telegramStatus['integration_installed']) || empty($telegramStatus['integration_compatible']) || !in_array('published', $module->telegramNotificationEvents(), true)) {
        throw new RuntimeException('The TeleWire publication-notification integration is unavailable or incomplete.');
    }
    $module->set('enable_squad_assistance', 1);
    $squadStatus = $module->squadIntegrationStatus();
    if (empty($squadStatus['integration_installed']) || empty($squadStatus['integration_compatible']) || empty($squadStatus['provider_ready']) || empty($squadStatus['ready'])) {
        throw new RuntimeException('The Squad planning integration is unavailable or not ready.');
    }
    $parseSquadProposal = new ReflectionMethod($module, 'parseSquadProposal');
    $proposalStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+8 days')->format('Y-m-d\TH:i');
    $proposalEnd = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+10 days')->format('Y-m-d\TH:i');
    $validatedProposal = $parseSquadProposal->invoke($module, json_encode(['scheduled_at'=>$proposalStart,'scheduled_until'=>$proposalEnd,'note'=>'Squad draft','rationale'=>'Validated but not saved.']), 'window', 'UTC');
    if (($validatedProposal['scheduled_at'] ?? '') !== $proposalStart || ($validatedProposal['scheduled_until'] ?? '') !== $proposalEnd) {
        throw new RuntimeException('The Squad proposal boundary did not validate a future publication window.');
    }
    $apiJob = $api->job((int)$windowIds['publish']);
    if ((int)($apiJob['id'] ?? 0) !== (int)$windowIds['publish'] || !isset($apiJob['page_title'], $apiJob['requested_by_user_id'])) {
        throw new RuntimeException('The operational facade job DTO is incomplete.');
    }
    foreach ([
        '___executeInterfaces' => ['RelayInterfaces', 'RelayInterfaceGrid', 'Operational interfaces'],
        '___executeApi' => ['RelayTokenPanel', 'relay-api/v1', 'RelayInterfaceTable'],
        '___executeCli' => ['relay-interface', '--execute', 'RelayInterfaceTable'],
        '___executeCrontab' => ['RelayWorkerPanel', 'Generated crontab line', 'Server boundary'],
        '___executeLazyCron' => ['RelayWorkerPanel', 'LazyCron::everyMinute', 'Timing boundary'],
        '___executeEmail' => ['Email notifications', 'WireMail', 'Credential boundary'],
        '___executeTelegram' => ['Telegram notifications', 'TeleWire', 'Privacy boundary'],
        '___executeSquad' => ['Editorial planning assistance', 'Nothing is scheduled', 'Shared fields'],
        '___executeTransfer' => ['Move Relay jobs safely', 'Export scheduled jobs', 'Preview import'],
    ] as $method => $needles) {
        $markup = (string)$module->{$method}();
        foreach ($needles as $needle) {
            if (!str_contains($markup, $needle)) throw new RuntimeException("Relay interface $method is missing $needle.");
        }
    }
    foreach (['enable_agent_api', 'enable_rest_api', 'enable_interface_cli'] as $setting) $module->set($setting, 0);

    $panelMethod = new ReflectionMethod($module, 'renderPagePanel');
    $panel = (string) $panelMethod->invoke($module, $testPage);
    foreach (['RelayPageOutcome', 'Current state', 'Future changes', 'RelayPageOutcome__action is-publish', 'RelayPageOutcome__action is-unpublish', 'data-relay-edit', 'data-relay-squad-suggest', 'RelaySquadProposal'] as $panelNeedle) {
        if (!str_contains($panel, $panelNeedle)) {
            throw new RuntimeException("Relay page panel is missing $panelNeedle.");
        }
    }
    $module->set('enable_squad_assistance', 0);

    $configOverviewMethod = new ReflectionMethod($module, 'renderConfigOverview');
    $configOverview = (string) $configOverviewMethod->invoke($module, \ProcessWire\Relay::getDefaultConfig());
    foreach (['RelayConfigIntro', 'RelayConfigStatusGrid', 'RelayConfigNav', '#Inputfield_relay_config_planning'] as $configNeedle) {
        if (!str_contains($configOverview, $configNeedle)) {
            throw new RuntimeException("Relay settings overview is missing $configNeedle.");
        }
    }
    $configFields = $module->getModuleConfigInputfields((array)$modules->getConfig('Relay'));
    if (!$configFields instanceof \ProcessWire\InputfieldWrapper || !$configFields->getChildByName('relay_config_interfaces')) {
        throw new RuntimeException('Relay operational interface settings could not be built.');
    }
    $imitationConfig = \ProcessWire\Relay::getDefaultConfig();
    $imitationConfig['imitation_mode'] = 1;
    $imitationOverview = (string) $configOverviewMethod->invoke($module, $imitationConfig);
    if (!str_contains($imitationOverview, 'RelayConfigImitation') || !str_contains($imitationOverview, 'data-imitation="1"')) {
        throw new RuntimeException('Relay settings do not expose the active imitation state.');
    }

    $calendarMethod = new ReflectionMethod($module, 'renderCalendar');
    $viewClasses = [
        'month' => 'RelayView--month',
        'week' => 'RelayView--agenda',
        'quarter' => 'RelayView--quarter',
        'three-day' => 'RelayView--agenda',
        'kanban' => 'RelayView--kanban',
        'timeline' => 'RelayView--timeline',
    ];
    foreach ($viewClasses as $view => $viewClass) {
        $processWire->wire('input')->get->set('view', $view);
        $processWire->wire('input')->get->set('date', gmdate('Y-m-d'));
        $calendar = (string) $calendarMethod->invoke($module);
        foreach (['RelayAdminNavigation', 'RelayPageIntro', 'RelayStatusStrip', 'RelayCalendarPanel', 'RelayViewContext', 'RelayPopover', 'RelayToast', $viewClass] as $className) {
            if (!str_contains($calendar, $className)) {
                throw new RuntimeException("Relay $view UI is missing $className.");
            }
        }
        if ($view !== 'kanban' && !str_contains($calendar, 'data-relay-drop-date')) {
            throw new RuntimeException("Relay $view UI is missing date drop targets.");
        }
        if (in_array($view, ['month', 'quarter'], true) && !str_contains($calendar, 'view=three-day')) {
            throw new RuntimeException("Relay $view UI is missing the three-day date drilldown.");
        }
        if ($view === 'timeline' && !str_contains($calendar, 'is-action-unpublish')) {
            throw new RuntimeException('Relay timeline is missing its visible action marker.');
        }
        if (in_array($view, ['month', 'quarter', 'timeline'], true) && (!str_contains($calendar, 'data-relay-job="1"') || !str_contains($calendar, 'draggable="true"'))) {
            throw new RuntimeException("Relay $view UI is missing draggable event metadata.");
        }
    }
    $processWire->wire('input')->get->set('view', 'month');
    $processWire->wire('input')->get->set('action', 'publish');
    $filteredCalendar = (string) $calendarMethod->invoke($module);
    if (!str_contains($filteredCalendar, 'RelayCalendarFilters') || !str_contains($filteredCalendar, 'name="action"')) {
        throw new RuntimeException('Relay calendar action filters are missing.');
    }
    $processWire->wire('input')->get->remove('view');
    $processWire->wire('input')->get->remove('date');
    $processWire->wire('input')->get->remove('action');

    $realJobCountBeforeImitation = (int) $database->query('SELECT COUNT(*) FROM relay_jobs')->fetchColumn();
    $transferDocument = $module->exportJobs($superuser, 'scheduled');
    if (($transferDocument['schema'] ?? '') !== 'relay.jobs' || empty($transferDocument['jobs'])) {
        throw new RuntimeException('Relay did not export a portable scheduled-job document.');
    }
    $transferJson = json_encode($transferDocument, JSON_THROW_ON_ERROR);
    $transferPreview = $module->importJobs($superuser, $transferJson, false);
    if (empty($transferPreview['ok']) || (int)$transferPreview['importable'] < 1 || !empty($transferPreview['job_ids'])) {
        throw new RuntimeException('Relay import preview is invalid or performed a write.');
    }
    $module->set('imitation_mode', 1);
    $transferImport = $module->importJobs($superuser, $transferJson, true);
    if (empty($transferImport['job_ids']) || min($transferImport['job_ids']) >= 0) {
        throw new RuntimeException('Relay imitation import did not create isolated negative job IDs.');
    }
    $demoId = $module->scheduleAction(
        $testPage,
        'publish',
        (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+6 days'),
        $superuser,
        'Session-only imitation test'
    );
    if ($demoId >= 0 || $store->get($demoId) !== null) {
        throw new RuntimeException('Imitation mode did not return an isolated negative demo ID.');
    }
    $realJobCountAfterImitation = (int) $database->query('SELECT COUNT(*) FROM relay_jobs')->fetchColumn();
    if ($realJobCountAfterImitation !== $realJobCountBeforeImitation) {
        throw new RuntimeException('Imitation mode changed the real schedule table.');
    }
    $imitationPanel = (string) $panelMethod->invoke($module, $testPage);
    foreach (['RelayImitationBanner', 'data-relay-reset-imitation', 'Session-only imitation test', 'data-relay-edit="' . $demoId . '"'] as $imitationNeedle) {
        if (!str_contains($imitationPanel, $imitationNeedle)) {
            throw new RuntimeException("Imitation page panel is missing $imitationNeedle.");
        }
    }
    $imitationReschedule = new ReflectionMethod($module, 'imitationReschedule');
    if (!$imitationReschedule->invoke(
        $module,
        $demoId,
        (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+7 days'),
        'UTC',
        (int) $superuser->id,
        'Updated imitation test'
    )) {
        throw new RuntimeException('Imitation action could not be rescheduled.');
    }
    $imitationCancel = new ReflectionMethod($module, 'imitationCancel');
    if (!$imitationCancel->invoke($module, $demoId)) {
        throw new RuntimeException('Imitation action could not be cancelled.');
    }
    $processWire->wire('session')->remove('RelayImitationJobs');
    $module->set('imitation_mode', 0);

    echo "Relay ProcessWire integration passed.\n";
} finally {
    $module->set('imitation_mode', $originalImitationMode);
    if ($jobIds) {
        $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
        $database->prepare("DELETE FROM relay_jobs WHERE id IN ($placeholders)")->execute($jobIds);
    }
    if ($testPage instanceof \ProcessWire\Page && $testPage->id) {
        $pages->delete($testPage, true);
    }
}
