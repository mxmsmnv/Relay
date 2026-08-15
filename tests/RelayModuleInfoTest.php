<?php

declare(strict_types=1);

namespace ProcessWire;

class Process
{
    private array $values = [];

    private array $services = [];

    private array $hooks = [];

    public function __construct()
    {
        $this->services['session'] = new TestSession();
    }

    public function set(string $name, mixed $value): void
    {
        $this->values[$name] = $value;
    }

    public function __get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function setTestService(string $name, object $service): void
    {
        $this->services[$name] = $service;
    }

    public function wire(string $name): object
    {
        return $this->services[$name];
    }

    public function addHook(string $method, object $target, string $handler): void
    {
        $this->hooks[] = ['type' => 'hook', 'method' => $method, 'handler' => $handler];
    }

    public function addHookAfter(string $method, object $target, string $handler): void
    {
        $this->hooks[] = ['type' => 'after', 'method' => $method, 'handler' => $handler];
    }

    public function getTestHooks(): array
    {
        return $this->hooks;
    }
}

class TestSession
{
    private array $values = [];

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, mixed $value): void
    {
        $this->values[$name] = $value;
    }

    public function remove(string $name): void
    {
        unset($this->values[$name]);
    }
}

interface Module
{
}

interface ConfigurableModule
{
}

class InputfieldWrapper
{
}

class HookEvent
{
}

class Page
{
}

class User
{
}

class Wire
{
}

final class TestModules
{
    public function __construct(private bool $lazyCronInstalled)
    {
    }

    public function isInstalled(string $name): bool
    {
        return $name === 'LazyCron' && $this->lazyCronInstalled;
    }
}

final class TestAssetList
{
    public array $items = [];

    public function add(string $url): void
    {
        $this->items[] = $url;
    }
}

final class TestLog
{
    public array $entries = [];

    public function save(string $channel, string $message): void
    {
        $this->entries[] = [$channel, $message];
    }
}

final class TestUrls
{
    public string $siteModules = '/site/modules/';

    public function __invoke(object $module): string
    {
        return '/site/modules/Relay/';
    }
}

final class TestConfig
{
    public TestUrls $urls;

    public TestAssetList $styles;

    public TestAssetList $scripts;

    public function __construct()
    {
        $this->urls = new TestUrls();
        $this->styles = new TestAssetList();
        $this->scripts = new TestAssetList();
    }

    public function urls(object $module): string
    {
        return '/site/modules/Relay/';
    }
}

require_once dirname(__DIR__) . '/Relay.module.php';

function expectModuleInfoSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "$message\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$info = Relay::getModuleInfo();
$defaults = Relay::getDefaultConfig();

expectModuleInfoSame(100, $info['version'] ?? null, 'The ProcessWire module version is incorrect.');
expectModuleInfoSame(['ProcessWire>=3.0.244', 'PHP>=8.2'], $info['requires'] ?? null, 'The minimum ProcessWire and PHP requirements are incorrect.');
expectModuleInfoSame(true, isset($info['permissions']['relay-admin']), 'The interface administration permission is missing.');
expectModuleInfoSame(true, isset($info['permissions']['relay-api']), 'The operational API permission is missing.');
expectModuleInfoSame(true, $info['autoload'] ?? null, 'Relay must autoload on public pageviews for LazyCron.');
expectModuleInfoSame(0, $defaults['lazy_cron_fallback'] ?? null, 'LazyCron must remain disabled by default.');
expectModuleInfoSame(1, $defaults['cron_interval_minutes'] ?? null, 'Crontab generation must default to every minute.');
expectModuleInfoSame('', $defaults['cron_php_binary'] ?? null, 'Crontab generation must default to the detected PHP binary.');
expectModuleInfoSame('site/assets/logs/relay-cron.log', $defaults['cron_log_path'] ?? null, 'The default cron log path is incorrect.');
expectModuleInfoSame(1, $defaults['enable_logging'] ?? null, 'Relay logging must remain enabled by default for backward compatibility.');
expectModuleInfoSame('month', $defaults['default_view'] ?? null, 'Month must remain the default planning view.');
expectModuleInfoSame('monday', $defaults['week_starts_on'] ?? null, 'Calendars must default to Monday as the first weekday.');
expectModuleInfoSame(1, $defaults['highlight_weekends'] ?? null, 'Weekend highlighting must be enabled by default.');
expectModuleInfoSame(1, $defaults['enable_template_controls'] ?? null, 'Template filtering and sorting must be enabled by default.');
expectModuleInfoSame('09:00', $defaults['default_time'] ?? null, 'The default scheduling time is incorrect.');
expectModuleInfoSame("Morning|09:00\nMidday|12:00\nAfternoon|15:00\nEvening|18:00", $defaults['time_presets'] ?? null, 'The default time presets are incorrect.');
expectModuleInfoSame('[]', $defaults['scheduling_rules'] ?? null, 'Scheduling rules must start with an empty bounded library.');
expectModuleInfoSame(5, $defaults['max_future_years'] ?? null, 'The default planning horizon is incorrect.');
expectModuleInfoSame(1, $defaults['show_page_tree_markers'] ?? null, 'Page tree markers must be enabled by default.');
expectModuleInfoSame(0, $defaults['imitation_mode'] ?? null, 'Imitation mode must remain disabled by default.');
expectModuleInfoSame(1, $defaults['enable_drag_drop'] ?? null, 'Drag-and-drop rescheduling must be enabled by default.');
expectModuleInfoSame(0, $defaults['enable_agent_api'] ?? null, 'The PHP API must remain disabled by default.');
expectModuleInfoSame(0, $defaults['enable_rest_api'] ?? null, 'The REST API must remain disabled by default.');
expectModuleInfoSame(0, $defaults['enable_interface_cli'] ?? null, 'The interface CLI must remain disabled by default.');
expectModuleInfoSame(0, $defaults['calendar_feed_enabled'] ?? null, 'The calendar subscription feed must remain disabled by default.');
expectModuleInfoSame('', $defaults['calendar_feed_token_hash'] ?? null, 'The calendar subscription token must not have a default credential.');
expectModuleInfoSame(0, $defaults['calendar_feed_include_titles'] ?? null, 'Calendar subscriptions must hide page titles by default.');
expectModuleInfoSame(0, $defaults['calendar_feed_include_links'] ?? null, 'Calendar subscriptions must hide public links by default.');
expectModuleInfoSame(0, $defaults['mail_notifications_enabled'] ?? null, 'Operational email must remain disabled by default.');
expectModuleInfoSame(0, $defaults['telegram_notifications_enabled'] ?? null, 'Telegram must remain disabled by default.');
expectModuleInfoSame(['published', 'failed'], $defaults['telegram_notification_events'] ?? null, 'Telegram must default to publication and failure events when explicitly enabled.');
expectModuleInfoSame(0, $defaults['enable_squad_assistance'] ?? null, 'Squad assistance must remain disabled by default.');
expectModuleInfoSame('title', $defaults['squad_context_fields'] ?? null, 'Squad must disclose only the title field by default.');

$listNormalizer = new \ReflectionMethod(Relay::class, 'configListValues');
$normalizerTarget = new Relay();
$presetNormalizer = new \ReflectionMethod(Relay::class, 'configuredTimePresets');
expectModuleInfoSame(
    [
        ['label' => 'Morning', 'time' => '09:00'],
        ['label' => 'Evening', 'time' => '18:30'],
        ['label' => '21:15', 'time' => '21:15'],
    ],
    $presetNormalizer->invoke($normalizerTarget, "<b>Morning</b>|09:00\nDuplicate|09:00\nInvalid|25:00\nEvening|18:30\n21:15"),
    'Time presets must be sanitized, validated, deduplicated, and support time-only lines.'
);
$ruleNormalizer = new \ReflectionMethod(Relay::class, 'normalizeSchedulingRule');
$normalizedRule = $ruleNormalizer->invoke($normalizerTarget, [
    'id' => 'abcdef123456', 'name' => '<b>Blog rhythm</b>', 'enabled' => 1, 'template' => 'blog-post',
    'action' => 'publish', 'start_date' => '2026-08-03', 'start_time' => '09:00',
    'frequency' => 'week', 'interval' => 2, 'weekdays' => [1, 3, 3, 9], 'ends' => 'never',
]);
expectModuleInfoSame('Blog rhythm', $normalizedRule['name'] ?? null, 'Scheduling rule names must be sanitized.');
expectModuleInfoSame([1, 3], $normalizedRule['weekdays'] ?? null, 'Scheduling rule weekdays must be bounded and deduplicated.');
$minuteRule = $ruleNormalizer->invoke($normalizerTarget, [
    'id' => 'fedcba654321', 'name' => 'Rapid cadence', 'enabled' => 1, 'template' => 'blog-post',
    'action' => 'publish', 'start_date' => '2026-08-14', 'start_time' => '09:00',
    'frequency' => 'minute', 'interval' => 69, 'ends' => 'never',
]);
expectModuleInfoSame(69, $minuteRule['interval'] ?? null, 'Minute scheduling intervals must be preserved.');
$quickPresetNormalizer = new \ReflectionMethod(Relay::class, 'normalizeSchedulingPreset');
$quickPreset = $quickPresetNormalizer->invoke($normalizerTarget, [
    'name'=>'<b>Campaign rhythm</b>', 'template'=>'article', 'action'=>'window', 'start_date'=>'2026-08-15',
    'start_time'=>'10:30', 'frequency'=>'week', 'interval'=>2, 'weekdays'=>[2,4,4,9], 'ends'=>'on',
    'until_date'=>'2026-09-14', 'occurrences'=>8, 'window_minutes'=>2880, 'note'=>'<i>Review first</i>',
]);
expectModuleInfoSame('Campaign rhythm', $quickPreset['name'] ?? null, 'Quick preset names must be sanitized.');
expectModuleInfoSame([2,4], $quickPreset['weekdays'] ?? null, 'Quick preset weekdays must be bounded and deduplicated.');
expectModuleInfoSame(30, $quickPreset['until_days'] ?? null, 'Quick presets must store a reusable relative end offset.');
$nextRuleOccurrence = new \ReflectionMethod(Relay::class, 'nextSchedulingRuleOccurrence');
$ruleTimezone = new \DateTimeZone('UTC');
expectModuleInfoSame(
    '2026-08-05 09:00',
    $nextRuleOccurrence->invoke($normalizerTarget, $normalizedRule, new \DateTimeImmutable('2026-08-04 12:00', $ruleTimezone), $ruleTimezone)?->format('Y-m-d H:i'),
    'A weekly rule must offer the next selected weekday in its active interval.'
);
expectModuleInfoSame(
    '2026-08-14 11:18',
    $nextRuleOccurrence->invoke($normalizerTarget, $minuteRule, new \DateTimeImmutable('2026-08-14 10:30', $ruleTimezone), $ruleTimezone)?->format('Y-m-d H:i'),
    'A 69-minute rule must calculate its next anchored occurrence exactly.'
);
$calendarTokenFactory = new \ReflectionMethod(Relay::class, 'newCalendarFeedToken');
$calendarTokenValidator = new \ReflectionMethod(Relay::class, 'calendarFeedTokenIsValid');
$calendarToken = $calendarTokenFactory->invoke($normalizerTarget);
expectModuleInfoSame(true, preg_match('/^relay_calendar_[A-Za-z0-9_-]{43}$/D', $calendarToken) === 1, 'Calendar subscriptions must use a 256-bit URL-safe token.');
$normalizerTarget->set('calendar_feed_enabled', 1);
$normalizerTarget->set('calendar_feed_token_hash', hash('sha256', $calendarToken));
expectModuleInfoSame(true, $calendarTokenValidator->invoke($normalizerTarget, $calendarToken), 'The current calendar subscription token must validate.');
expectModuleInfoSame(false, $calendarTokenValidator->invoke($normalizerTarget, $calendarToken . 'x'), 'A modified calendar subscription token must fail closed.');
$icsEscape = new \ReflectionMethod(Relay::class, 'icsEscape');
$icsFold = new \ReflectionMethod(Relay::class, 'icsFoldLine');
expectModuleInfoSame('Title\\, value\\; line\\nnext', $icsEscape->invoke($normalizerTarget, "Title, value; line\nnext"), 'iCalendar text escaping is incorrect.');
$folded = $icsFold->invoke($normalizerTarget, 'SUMMARY:' . str_repeat('é', 50));
expectModuleInfoSame(true, str_contains($folded, "\r\n ") && preg_match('//u', $folded) === 1, 'Long iCalendar lines must fold without splitting UTF-8 characters.');
expectModuleInfoSame(
    '2026-08-17 09:00',
    $nextRuleOccurrence->invoke($normalizerTarget, $normalizedRule, new \DateTimeImmutable('2026-08-06 12:00', $ruleTimezone), $ruleTimezone)?->format('Y-m-d H:i'),
    'A custom two-week interval must skip the inactive week.'
);
$ongoingDailyRule = $ruleNormalizer->invoke($normalizerTarget, [
    'id' => '123456abcdef', 'name' => 'Long-running daily slot', 'enabled' => 1, 'template' => 'blog-post',
    'start_date' => '2020-01-01', 'start_time' => '09:00', 'frequency' => 'day', 'interval' => 1, 'ends' => 'never',
]);
expectModuleInfoSame(
    '2026-08-14 09:00',
    $nextRuleOccurrence->invoke($normalizerTarget, $ongoingDailyRule, new \DateTimeImmutable('2026-08-14 08:00', $ruleTimezone), $ruleTimezone)?->format('Y-m-d H:i'),
    'A never-ending rule must keep offering slots beyond its initial planning horizon.'
);
expectModuleInfoSame(
    ['basic-page', 'article', 'event'],
    $listNormalizer->invoke($normalizerTarget, "basic-page, article\nevent;article"),
    'Delimited multi-value settings must normalize without duplicates.'
);
expectModuleInfoSame(
    ['editor', 'member'],
    $listNormalizer->invoke($normalizerTarget, ['editor', '', 'member', 'editor']),
    'AsmSelect array settings must normalize without duplicates.'
);

$log = new TestLog();
$normalizerTarget->setTestService('log', $log);
$writeLog = new \ReflectionMethod(Relay::class, 'writeRelayLog');
$writeLog->invoke($normalizerTarget, 'enabled entry');
$normalizerTarget->set('enable_logging', 0);
$writeLog->invoke($normalizerTarget, 'disabled entry');
expectModuleInfoSame([['relay', 'enabled entry']], $log->entries, 'Disabling Relay logging must suppress new module log entries.');

$compositionSource = (string)file_get_contents(dirname(__DIR__) . '/Relay.module.php');
$traitFiles = glob(dirname(__DIR__) . '/src/Traits/*.php') ?: [];
sort($traitFiles);
$traitSource = implode("\n", array_map(static fn(string $file): string => (string)file_get_contents($file), $traitFiles));
$moduleSource = $compositionSource . "\n" . $traitSource;
$workspaceCss = (string)file_get_contents(dirname(__DIR__) . '/assets/relay.css');
$configCss = (string)file_get_contents(dirname(__DIR__) . '/assets/relay-config.css');
$workspaceJs = (string)file_get_contents(dirname(__DIR__) . '/assets/relay.js');
$restSource = (string)file_get_contents(dirname(__DIR__) . '/src/RelayRestApi.php');
$storeSource = (string)file_get_contents(dirname(__DIR__) . '/src/RelayStore.php');
$rulesSource = (string)file_get_contents(dirname(__DIR__) . '/src/Traits/RelaySchedulingRulesTrait.php');
$calendarFeedSource = (string)file_get_contents(dirname(__DIR__) . '/src/Traits/RelayCalendarFeedTrait.php');
$readme = (string)file_get_contents(dirname(__DIR__) . '/README.md');
$apiDocs = (string)file_get_contents(dirname(__DIR__) . '/API.md');
$agentDocs = (string)file_get_contents(dirname(__DIR__) . '/AGENTS.md');
$changelog = (string)file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
$funding = (string)file_get_contents(dirname(__DIR__) . '/.github/FUNDING.yml');
$doodle = (string)file_get_contents(dirname(__DIR__) . '/assets/Relay.png');
expectModuleInfoSame(true, str_contains($compositionSource, "'version' => 100"), 'ProcessWire module metadata must expose a literal numeric version for directory indexing.');
expectModuleInfoSame(false, str_contains($compositionSource, "'version' => self::VERSION"), 'ProcessWire module metadata must not hide its version behind a class constant.');
expectModuleInfoSame(true, str_contains($readme, '![Relay](assets/Relay.png)'), 'README must show the current Relay doodle.');
expectModuleInfoSame("\x89PNG\r\n\x1a\n", substr($doodle, 0, 8), 'The Relay README doodle must be a valid PNG asset.');
expectModuleInfoSame(true, str_contains($funding, 'github: mxmsmnv') && str_contains($funding, 'https://smnv.org/sponsor/'), 'Repository sponsorship metadata is incomplete.');
expectModuleInfoSame(false, preg_match('/\b(?:release|version)\s+`?\d+\.\d+\.\d+`?/i', $readme) === 1 || str_contains($readme, 'module version'), 'README must not display release or module version numbers.');
expectModuleInfoSame(true, str_contains($apiDocs, 'Relay 1.0.0') && str_contains($apiDocs, 'Module version `100`'), 'API documentation must identify Relay 1.0.0 / module version 100.');
expectModuleInfoSame(1, preg_match_all('/^## \d+\.\d+\.\d+ — /m', $changelog), 'CHANGELOG must contain exactly one entry for the first public release.');
expectModuleInfoSame(true, str_contains($agentDocs, 'Olivia Site-Building Workflow') && str_contains($agentDocs, 'High Risk Or Destructive'), 'Agent guidance must document Olivia site-building and safety boundaries.');
expectModuleInfoSame(true, substr_count($compositionSource, "\n") < 250, 'Relay.module.php must remain a compact ProcessWire composition root.');
expectModuleInfoSame(15, count($traitFiles), 'Relay domain behavior must remain split across the documented trait boundaries.');
expectModuleInfoSame(true, str_contains($compositionSource, 'use RelayWorkerTrait;') && str_contains($compositionSource, 'use RelayCalendarUiTrait;'), 'The composition root must assemble worker and calendar domains explicitly.');
expectModuleInfoSame(false, str_contains($workspaceCss . $configCss, ':hover'), 'Relay must not define custom mouse-hover effects.');
expectModuleInfoSame(true, str_contains($moduleSource, "class=\"RelayInterfaceIntro' . \$densityClass"), 'The Interfaces introduction is missing.');
expectModuleInfoSame(true, str_contains($moduleSource, "RelayInterfaceIntro' . \$densityClass . '\"><div><h2>"), 'Interfaces must use a contextual section heading inside the workspace.');
expectModuleInfoSame(true, str_contains($moduleSource, "count(\$states) > 4 ? ' RelayInterfaceIntro--dense'") && str_contains($workspaceCss, '.RelayInterfaceIntro--dense'), 'Dense interface summaries must not squeeze their heading and description.');
expectModuleInfoSame(false, str_contains($moduleSource, "'</small><h1>'"), 'Interfaces must not duplicate the native ProcessWire H1.');
expectModuleInfoSame(true, str_contains($moduleSource, 'Configuration snapshot'), 'Interface settings must use a clear configuration summary label.');
expectModuleInfoSame(true, str_contains($moduleSource, 'data-relay-scroll-target'), 'Local interface settings links must expose a repeatable scroll target.');
expectModuleInfoSame(true, str_contains($calendarFeedSource, 'id="RelayCalendarFeedControls" tabindex="-1"'), 'Calendar feed controls must accept programmatic focus after anchor navigation.');
expectModuleInfoSame(true, str_contains($calendarFeedSource, 'RelayCalendarFeedRange') && str_contains($calendarFeedSource, 'RelayCalendarFeedPrivacy'), 'Calendar feed settings must separate range and privacy controls.');
expectModuleInfoSame(true, str_contains($moduleSource, 'fa fa-shield'), 'Interface boundaries must have a recognizable safety affordance.');
expectModuleInfoSame(true, str_contains($moduleSource, 'View details'), 'Interface overview cards must use a descriptive action label.');
expectModuleInfoSame(true, str_contains($workspaceCss, '.RelayInterfaceSettings dt'), 'Interface settings must define restrained label typography.');
expectModuleInfoSame(true, str_contains($workspaceCss, '.RelayInterfaceSummary { display: flex; width: 100%; min-width: 0; flex-wrap: nowrap;'), 'Interface status summaries must remain on one horizontal row.');
expectModuleInfoSame(true, str_contains($workspaceCss, '.RelayInterfaceState { display: inline-flex; flex: 0 0 auto;'), 'Interface status pills must not shrink or wrap individually.');
expectModuleInfoSame(true, str_contains($workspaceJs, '[data-relay-month-picker]'), 'The enhanced month picker behavior is missing.');
expectModuleInfoSame(true, str_contains($moduleSource, 'input type="date" name="date"'), 'The compact calendar date picker is missing.');
expectModuleInfoSame(false, str_contains($moduleSource, 'input type="month" name="month"'), 'The exposed month text control is still rendered.');
expectModuleInfoSame(true, str_contains($moduleSource, 'class="RelayDay__open"'), 'Populated month cells must expose direct day navigation.');
expectModuleInfoSame(false, str_contains($moduleSource, 'fa-calendar-o" aria-hidden="true"></i><span>'), 'The direct day action must remain icon-only.');
expectModuleInfoSame(true, str_contains($moduleSource, 'class="RelayDay__events"'), 'Dense month cells must retain every action for correct drag-and-drop reconciliation.');
expectModuleInfoSame(true, str_contains($workspaceCss, '.RelayDay__events > .RelayEvent:nth-child(n + 4)'), 'Dense month cells must visually limit inline action previews.');
expectModuleInfoSame(true, str_contains($workspaceCss, "align-items: center;\n  gap: .3rem;\n  min-height: 1.75rem;"), 'Calendar events must stay compact and vertically centred.');
expectModuleInfoSame(true, str_contains($moduleSource, 'class="RelayDay__more"'), 'Dense month cells must expose hidden action counts.');
expectModuleInfoSame(true, str_contains($moduleSource, "_('+%d more')"), 'Dense month cells must use a compact hidden-action label.');
expectModuleInfoSame(false, str_contains($moduleSource, "'</strong><span>' . \$this->_('Open day')"), 'The dense-day footer must not repeat a text day action.');
expectModuleInfoSame(true, str_contains($workspaceCss, ".RelayDay__more {\n  display: flex;") && str_contains($workspaceCss, 'white-space: nowrap;'), 'The dense-day footer must remain on one line.');
expectModuleInfoSame(true, str_contains($moduleSource, "'data-public-url'"), 'Calendar jobs must expose the publication URL to the details popover.');
expectModuleInfoSame(true, str_contains($moduleSource, "'data-template-name'"), 'Calendar jobs must expose their page template to the details popover.');
expectModuleInfoSame(true, str_contains($moduleSource, 'data-popover-title'), 'The publication details popover must include the page title.');
expectModuleInfoSame(true, str_contains($moduleSource, 'data-popover-note-row'), 'The publication details popover must retain permission-aware notes.');
expectModuleInfoSame(true, str_contains($moduleSource, "_('Open publication URL')"), 'The publication URL must have an accessible name before the popover is populated.');
expectModuleInfoSame(true, str_contains($moduleSource, 'RelayPopover__details'), 'The publication popover must group its detail sections responsively.');
expectModuleInfoSame(true, str_contains($workspaceCss, '.RelayPopover__summary time'), 'The publication popover must define readable value typography.');
expectModuleInfoSame(true, str_contains($workspaceCss, ".RelayAdmin .uk-button,\n.RelayPanel .ui-button,\n.RelayPopover .ui-button") && str_contains($workspaceCss, 'gap: .45rem;'), 'Relay buttons must keep consistent space between icons and labels.');
expectModuleInfoSame(true, str_contains($workspaceCss, 'font-variant-numeric: tabular-nums;'), 'Relay dates and counts must use stable numeric widths.');
expectModuleInfoSame(true, str_contains($workspaceCss, '.RelayTransferFile__control') && str_contains($workspaceJs, '[data-relay-import-file]'), 'The JSON file picker must use the Relay control system and expose its filename.');
expectModuleInfoSame(true, str_contains($workspaceCss, ".RelayCalendarFilters select {\n  width: 100%;") && str_contains($workspaceCss, 'font-size: 1rem;'), 'Calendar filter values must use standard control typography.');
expectModuleInfoSame(true, str_contains($moduleSource, 'private const IMITATION_SEED_VERSION = 2;'), 'The dense imitation dataset version is missing.');
expectModuleInfoSame(true, substr_count($moduleSource, 'Demo:') >= 39, 'Imitation mode must provide a dense, varied demo dataset.');
expectModuleInfoSame(true, str_contains($moduleSource, '___executeSeedImitation'), 'Empty imitation sessions must be recoverable without changing module configuration.');
expectModuleInfoSame(true, str_contains($workspaceJs, '[data-relay-seed-imitation]'), 'The demo-data reload control is missing.');
expectModuleInfoSame(true, str_contains($workspaceCss, 'max-height: min(42rem, 70vh);'), 'Dense Kanban columns must remain bounded and scrollable.');
expectModuleInfoSame(true, str_contains($workspaceCss, '--relay-kanban-state-color') && str_contains($workspaceCss, 'border-left: 3px solid var(--relay-kanban-state-color);'), 'Kanban columns and cards must expose visible status colors.');
expectModuleInfoSame(true, str_contains($moduleSource, 'class="RelayKanbanCard__meta"><time>'), 'Kanban cards must give their metadata row to the date and time.');
expectModuleInfoSame(false, str_contains($moduleSource, "RelayBadge is-'\n                    . \$this->wire('sanitizer')->name((string) \$job['status'])"), 'Kanban cards must not repeat their column status.');
expectModuleInfoSame(true, str_contains($workspaceCss, ".RelayKanbanCard time {\n  display: block;\n  width: 100%;"), 'Kanban date and time must use the full card width.');
expectModuleInfoSame(true, str_contains($workspaceCss, '.RelayComposer input,') && str_contains($workspaceCss, 'min-width: 0;'), 'The page composer controls must shrink within narrow layouts.');
expectModuleInfoSame(true, str_contains($workspaceCss, ".RelayTimeline__marker {\n  display: inline-flex;\n  align-items: center;\n  justify-content: flex-start;"), 'Timeline markers must align their icon and time to the compact leading edge.');
expectModuleInfoSame(true, str_contains($moduleSource, 'public function exportJobs(User $actor'), 'The bounded Relay jobs exporter is missing.');
expectModuleInfoSame(true, str_contains($moduleSource, 'public function importJobs(User $actor'), 'The preview-first Relay jobs importer is missing.');
expectModuleInfoSame(true, str_contains($moduleSource, "'transfer'=>[\$base.'transfer/'"), 'Interfaces navigation must expose Import / Export.');
expectModuleInfoSame(true, str_contains($workspaceCss, '.RelayTransferPreview'), 'Import preview styling is missing.');
expectModuleInfoSame(true, str_contains($moduleSource, 'TRANSFER_MAX_BYTES = 1048576'), 'Import files must retain the 1 MiB boundary.');
expectModuleInfoSame(true, str_contains($moduleSource, '$form->prepend($tab)'), 'The Relay page tab must render ahead of global page fields.');
expectModuleInfoSame(true, str_contains($moduleSource, 'RelayPanel__toolbar'), 'The page scheduler must expose its primary calendar action at the top.');
expectModuleInfoSame(true, str_contains($moduleSource, "'enable_logging' => 1"), 'The logging preference is missing from module defaults.');
expectModuleInfoSame(true, str_contains($moduleSource, "name = 'enable_logging'"), 'The logging preference is missing from module settings.');
expectModuleInfoSame(true, substr_count($moduleSource, "_('Publication times')") >= 3 && str_contains($moduleSource, 'Recurrence is configured in Rules.'), 'One-time publication times must be clearly distinguished from recurring Rules.');
expectModuleInfoSame(true, str_contains($moduleSource, "PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'"), 'Disabled logging must discard generated worker output portably.');
expectModuleInfoSame(true, str_contains($restSource, 'relay->enable_logging'), 'REST failures must respect the Relay logging preference.');
expectModuleInfoSame(true, str_contains($moduleSource, 'RelayComposer__header'), 'The page scheduler composer must explain its planning workflow.');
expectModuleInfoSame(true, str_contains($moduleSource, 'RelayComposer__header"><span><i'), 'The page scheduler must retain its planning affordance.');
expectModuleInfoSame(true, str_contains($moduleSource, 'RelayComposer__actionGuide'), 'The page scheduler must explain the selected publication action.');
expectModuleInfoSame(true, str_contains($workspaceJs, '[data-relay-action-guide]'), 'The publication action guide must stay synchronized with the selected action.');
expectModuleInfoSame(true, str_contains($moduleSource, "name = 'time_presets'") && str_contains($moduleSource, 'relay_config_presets'), 'Time presets must have their own module settings section.');
expectModuleInfoSame(true, str_contains($moduleSource, 'data-relay-time-preset'), 'The page scheduler must render configured time preset controls.');
expectModuleInfoSame(true, str_contains($workspaceJs, 'withPresetTime'), 'Time presets must preserve the selected calendar date.');
expectModuleInfoSame(true, str_contains($workspaceCss, '.RelayTimePreset[aria-pressed="true"]'), 'The active time preset must be visually identifiable.');
expectModuleInfoSame(true, str_contains($rulesSource, '___executeRules') && str_contains($moduleSource, "'rules'=>[\$base.'rules/'"), 'The Rules workspace must be routed between calendars and Interfaces.');
expectModuleInfoSame(true, str_contains($rulesSource, 'data-relay-rule-select'), 'Matching template rules must be available in the page planner.');
expectModuleInfoSame(true, str_contains($workspaceJs, '[data-relay-rule-form]') && str_contains($workspaceJs, 'applySchedulingRule'), 'Scheduling rule form and planner interactions are missing.');
expectModuleInfoSame(true, str_contains($workspaceCss, '.RelayRulesLayout') && str_contains($workspaceCss, '.RelayComposer__rule'), 'Scheduling rules must have responsive workspace and planner styling.');
expectModuleInfoSame(true, str_contains($storeSource, "PRESETS_TABLE = 'relay_presets'") && str_contains($storeSource, 'public function savePreset'), 'Quick presets must use their own internal database table and bounded store API.');
expectModuleInfoSame(true, str_contains($rulesSource, 'data-relay-rule-preset') && str_contains($rulesSource, 'Save cadence as preset'), 'Rules must expose quick cadence preset apply and save actions.');
expectModuleInfoSame(true, str_contains($workspaceJs, 'applyQuickPreset') && str_contains($workspaceCss, '.RelayQuickPresetGrid'), 'Quick preset interactions and responsive UI are missing.');
expectModuleInfoSame(true, str_contains($calendarFeedSource, '___executeCalendarFeed') && str_contains($moduleSource, "'calendar-feed'=>[\$base.'calendar-feed/'"), 'Interfaces must expose read-only calendar subscriptions.');
expectModuleInfoSame(true, str_contains($calendarFeedSource, "hash_equals(\$stored, hash('sha256', \$token))"), 'Calendar feed tokens must be verified against a stored SHA-256 hash.');
expectModuleInfoSame(true, str_contains($calendarFeedSource, 'Internal notes, users, errors, page content') && !str_contains($calendarFeedSource, "['note']"), 'Calendar subscriptions must exclude internal notes and private operational data.');
expectModuleInfoSame(true, str_contains($calendarFeedSource, "Content-Type: text/calendar; charset=utf-8") && str_contains($calendarFeedSource, 'X-Robots-Tag: noindex'), 'Calendar feed responses must use safe calendar response headers.');
expectModuleInfoSame(true, str_contains($workspaceJs, '[data-relay-copy-calendar]') && str_contains($workspaceCss, '.RelayCalendarFeedForm'), 'Calendar subscription controls must have responsive copy and settings UI.');
expectModuleInfoSame(true, str_contains($workspaceCss, '.RelayJobs th'), 'The page scheduler table must define restrained header typography.');
expectModuleInfoSame(true, str_contains($moduleSource, '___executeCrontab'), 'Interfaces must expose a Crontab configuration route.');
expectModuleInfoSame(true, str_contains($moduleSource, '___executeLazyCron'), 'Interfaces must expose a LazyCron configuration route.');
expectModuleInfoSame(true, str_contains($moduleSource, 'Relay never changes the operating-system crontab itself.'), 'The Crontab screen must disclose its server boundary.');
expectModuleInfoSame(true, str_contains($workspaceJs, '[data-relay-copy-command]'), 'The generated crontab line must have a copy control.');
expectModuleInfoSame(true, str_contains($moduleSource, "preg_match('/(?:php-cgi|php-fpm)"), 'Crontab generation must reject web-server PHP binaries.');
expectModuleInfoSame(true, str_contains($workspaceCss, '.RelayCalendarPanel a:visited'), 'Calendar links must remove theme-provided underlines in every state.');
expectModuleInfoSame(true, str_contains($moduleSource, 'fa fa-calendar-o'), 'The Open day action must use a calendar affordance.');
expectModuleInfoSame(true, str_contains($moduleSource, 'RelayEvent__drag'), 'Pending calendar events must expose a visible drag handle.');
expectModuleInfoSame(true, str_contains($moduleSource, 'data-relay-drag-status'), 'Calendar drag-and-drop must announce its state accessibly.');
expectModuleInfoSame(true, str_contains($workspaceJs, 'setDragPreview'), 'Calendar drag-and-drop must provide a stable drag preview.');
expectModuleInfoSame(true, str_contains($moduleSource, "name = 'week_starts_on'"), 'Relay settings must expose the first weekday control.');
expectModuleInfoSame(true, str_contains($moduleSource, "name = 'highlight_weekends'"), 'Relay settings must expose the weekend highlighting control.');
expectModuleInfoSame(true, str_contains($moduleSource, "name = 'enable_template_controls'"), 'Relay settings must expose the template controls toggle.');
expectModuleInfoSame(true, str_contains($moduleSource, 'name="template" data-relay-auto-submit'), 'The calendar template filter is missing.');
expectModuleInfoSame(true, str_contains($moduleSource, 'name="sort" data-relay-auto-submit'), 'The calendar ordering control is missing.');
expectModuleInfoSame(true, str_contains($moduleSource, "if (\$sortOrder === 'template')"), 'Template ordering must be applied to hydrated calendar jobs.');
expectModuleInfoSame(true, str_contains($moduleSource, 'RelayMobileViewJump'), 'Wide mobile planning views must expose direct navigation controls.');
expectModuleInfoSame(true, str_contains($workspaceJs, 'revealActiveNavigation'), 'Scrollable navigation must reveal the active section.');
expectModuleInfoSame(true, str_contains($workspaceJs, 'refreshMonthDay'), 'Drag-and-drop must reconcile dense month-day state.');
expectModuleInfoSame(true, str_contains($moduleSource, 'formatCalendarDate'), 'Calendar dates must use the ProcessWire-aware formatter.');

$templateFilterOptions = new \ReflectionMethod(Relay::class, 'calendarTemplateFilterOptions');
expectModuleInfoSame(
    ['article' => 'Article', 'manager' => 'Catalog manager', 'news' => 'News'],
    $templateFilterOptions->invoke(new Relay(), ['news' => 'News', 'manager' => 'Catalog manager', 'article' => 'Article']),
    'Choosing one template must not remove the other available template choices.'
);

$templateSorter = new \ReflectionMethod(Relay::class, 'sortCalendarJobsByTemplate');
$sortedJobs = $templateSorter->invoke(new Relay(), [
    ['id' => 3, '_template_label' => 'News', 'scheduled_at' => '2026-08-17 10:00:00'],
    ['id' => 2, '_template_label' => 'Article', 'scheduled_at' => '2026-08-17 12:00:00'],
    ['id' => 1, '_template_label' => 'Article', 'scheduled_at' => '2026-08-16 12:00:00'],
]);
expectModuleInfoSame([1, 2, 3], array_column($sortedJobs, 'id'), 'Template ordering must group by template and retain chronological order inside each group.');

$weekStart = new \ReflectionMethod(Relay::class, 'calendarWeekStart');
$weekdayLabels = new \ReflectionMethod(Relay::class, 'calendarWeekdayLabels');
$calendarPreferences = new Relay();
$wednesday = new \DateTimeImmutable('2026-08-05', new \DateTimeZone('UTC'));
expectModuleInfoSame('2026-08-03', $weekStart->invoke($calendarPreferences, $wednesday)->format('Y-m-d'), 'Monday-first ranges are incorrect.');
expectModuleInfoSame('Mon', $weekdayLabels->invoke($calendarPreferences)[0] ?? null, 'Monday-first labels are incorrect.');
$calendarPreferences->set('week_starts_on', 'sunday');
expectModuleInfoSame('2026-08-02', $weekStart->invoke($calendarPreferences, $wednesday)->format('Y-m-d'), 'Sunday-first ranges are incorrect.');
expectModuleInfoSame('Sun', $weekdayLabels->invoke($calendarPreferences)[0] ?? null, 'Sunday-first labels are incorrect.');
$weekendClass = new \ReflectionMethod(Relay::class, 'calendarWeekendClass');
expectModuleInfoSame(' is-weekend', $weekendClass->invoke($calendarPreferences, new \DateTimeImmutable('2026-08-08')), 'Saturday must be highlighted.');
expectModuleInfoSame('', $weekendClass->invoke($calendarPreferences, new \DateTimeImmutable('2026-08-10')), 'Weekdays must not be highlighted.');
$calendarPreferences->set('highlight_weekends', 0);
expectModuleInfoSame('', $weekendClass->invoke($calendarPreferences, new \DateTimeImmutable('2026-08-09')), 'Weekend highlighting must respect the disabled setting.');

$disabled = new Relay();
$disabled->setTestService('page', (object) ['template' => 'basic-page']);
$disabled->setTestService('modules', new TestModules(true));
$disabled->init();
expectModuleInfoSame([], $disabled->getTestHooks(), 'LazyCron must not be registered when the fallback is disabled.');

$rest = new Relay();
$rest->set('enable_agent_api', 1);
$rest->set('enable_rest_api', 1);
$rest->setTestService('page', (object) ['template' => 'basic-page']);
$rest->setTestService('modules', new TestModules(false));
$rest->init();
expectModuleInfoSame(
    [['type' => 'hook', 'method' => '/relay-api/{version}/{resource}/', 'handler' => 'handleRestRequest']],
    $rest->getTestHooks(),
    'The REST route must be registered only when both API settings are enabled.'
);

$calendarFeed = new Relay();
$calendarFeed->set('calendar_feed_enabled', 1);
$calendarFeed->set('calendar_feed_token_hash', str_repeat('a', 64));
$calendarFeed->setTestService('page', (object) ['template' => 'basic-page']);
$calendarFeed->setTestService('modules', new TestModules(false));
$calendarFeed->init();
expectModuleInfoSame(
    [['type' => 'hook', 'method' => '/relay-calendar/{token}.ics', 'handler' => 'handleCalendarFeed']],
    $calendarFeed->getTestHooks(),
    'The read-only calendar route must be registered only when it is enabled and configured.'
);

$public = new Relay();
$public->set('lazy_cron_fallback', 1);
$public->setTestService('page', (object) ['template' => 'basic-page']);
$public->setTestService('modules', new TestModules(true));
$public->init();
expectModuleInfoSame(
    [['type' => 'hook', 'method' => 'LazyCron::everyMinute', 'handler' => 'hookLazyCron']],
    $public->getTestHooks(),
    'Public pageviews must register the enabled LazyCron fallback.'
);

$admin = new Relay();
$admin->set('lazy_cron_fallback', 1);
$admin->setTestService('page', (object) ['template' => 'admin']);
$admin->setTestService('modules', new TestModules(true));
$admin->setTestService('config', new TestConfig());
$admin->init();
$admin->ready();
expectModuleInfoSame(
    [
        ['type' => 'hook', 'method' => 'LazyCron::everyMinute', 'handler' => 'hookLazyCron'],
        ['type' => 'after', 'method' => 'ProcessPageEdit::buildForm', 'handler' => 'hookPageEditTab'],
        ['type' => 'after', 'method' => 'ProcessPageListRender::getPageLabel', 'handler' => 'hookPageListLabel'],
    ],
    $admin->getTestHooks(),
    'Admin pageviews must retain the editor and page-list hooks and register the enabled LazyCron fallback.'
);

$missing = new Relay();
$missing->set('lazy_cron_fallback', 1);
$missing->setTestService('page', (object) ['template' => 'basic-page']);
$missing->setTestService('modules', new TestModules(false));
$missing->init();
expectModuleInfoSame([], $missing->getTestHooks(), 'Relay must not register a hook when LazyCron is unavailable.');

$imitation = new Relay();
$imitation->set('lazy_cron_fallback', 1);
$imitation->set('imitation_mode', 1);
$imitation->setTestService('page', (object) ['template' => 'basic-page']);
$imitation->setTestService('modules', new TestModules(true));
$imitation->init();
expectModuleInfoSame([], $imitation->getTestHooks(), 'Imitation mode must pause the LazyCron fallback.');

echo "Relay module info tests passed.\n";
