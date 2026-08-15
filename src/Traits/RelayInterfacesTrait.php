<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * Operational Interfaces pages, shared UI and worker configuration helpers.
 */
trait RelayInterfacesTrait
{
    public function ___executeInterfaces(): string
    {
        $this->requireInterfaceAdmin();
        $this->configureAdminChrome($this->_('Interfaces'), $this->_('Interfaces'));
        $channels = $this->capabilities()['channels'];
        $crontab = $this->crontabStatus();
        $lazyCron = $this->lazyCronStatus();
        $cards = [
            ['exchange', 'API ' . self::REST_API_VERSION, $this->_('Versioned JSON API'), $this->_('Connect trusted internal tools through the PHP API or REST while keeping ProcessWire permissions in control.'), 'api', (bool)$channels['rest']],
            ['terminal', 'CLI', $this->_('Local command line'), $this->_('Read schedule data or run an explicit write command from the ProcessWire host with JSON input and output.'), 'cli', (bool)$channels['cli']],
            ['clock-o', 'CRON', $this->_('System crontab'), $this->_('Build the exact production worker command to review, copy, and install in the server scheduler.'), 'crontab', $crontab['ready'], $this->_('Ready'), $this->_('Needs attention')],
            ['history', 'ProcessWire', $this->_('LazyCron fallback'), $this->_('Use request-triggered processing only when a system cron is unavailable; publication timing is not exact.'), 'lazy-cron', $lazyCron['active'], $this->_('Active'), $this->_('Inactive')],
            ['envelope', 'WireMail', $this->_('Operational email'), $this->_('Send selected schedule lifecycle alerts to configured administrators through WireMail.'), 'email', (bool)$channels['email']],
            ['telegram', 'Telegram Bot API', $this->_('Telegram notifications'), $this->_('Send concise operational alerts to configured chats through TeleWire without sharing page content or notes.'), 'telegram', (bool)$channels['telegram']],
            ['magic', 'Squad', $this->_('AI planning proposals'), $this->_('Let an editor request an editable timing and note suggestion without scheduling or changing the page automatically.'), 'squad', (bool)$channels['squad']],
            ['calendar', 'iCalendar', $this->_('Google and Apple Calendar'), $this->_('Publish a revocable read-only subscription without granting calendar providers control of Relay or ProcessWire.'), 'calendar-feed', (int)$this->calendar_feed_enabled === 1 && trim((string)$this->calendar_feed_token_hash) !== '', $this->_('Active'), $this->_('Inactive')],
            ['exchange', 'JSON', $this->_('Import / Export'), $this->_('Move a bounded set of scheduled actions between Relay installations with a preview-first portable file.'), 'transfer', true, $this->_('Ready'), $this->_('Unavailable')],
        ];
        $out = $this->interfaceNav('overview') . $this->interfaceIntro($this->_('Operational interfaces'), $this->_('Choose an interface to see what it does, whether it is ready, what it can change, and where to configure it.'), [
            'API' => (bool)$channels['rest'], 'CLI' => (bool)$channels['cli'], 'Crontab' => [(bool)$crontab['ready'],$this->_('Ready'),$this->_('Check paths')],
            'LazyCron' => [(bool)$lazyCron['active'],$this->_('Active'),$this->_('Inactive')], 'Email' => (bool)$channels['email'], 'Telegram' => (bool)$channels['telegram'], 'Squad' => (bool)$channels['squad'], $this->_('Calendar feed') => [(int)$this->calendar_feed_enabled === 1 && trim((string)$this->calendar_feed_token_hash) !== '', $this->_('Active'), $this->_('Inactive')], $this->_('Transfer') => true]);
        $out .= '<section class="RelayInterfaceGrid">';
        foreach ($cards as $card) $out .= $this->interfaceCard(...$card);
        return $this->interfaceWrap($out . '</section>');
    }

    public function ___executeApi(): string
    {
        $this->requireInterfaceAdmin();
        if ($this->wire('input')->requestMethod('POST')) $this->handleBearerTokenAction();
        $this->configureAdminChrome($this->_('Relay API'), $this->_('API'), [[$this->processUrl() . 'interfaces/', $this->_('Interfaces')]]);
        $channels = $this->capabilities()['channels'];
        $actor = (int)$this->rest_bearer_user_id ? $this->wire('users')->get((int)$this->rest_bearer_user_id) : null;
        $once = trim((string)$this->wire('session')->get('relay_bearer_token_once')); $this->wire('session')->remove('relay_bearer_token_once');
        $base = rtrim((string)$this->wire('config')->urls->root, '/') . '/relay-api/' . self::REST_API_VERSION . '/';
        $out = $this->interfaceNav('api') . $this->interfaceIntro('API ' . self::REST_API_VERSION, $this->_('Connect a trusted application with a ProcessWire session or one scoped Bearer token. Every request still acts as the assigned user.'), [
            'PHP API'=>(bool)$channels['php_api'], 'REST'=>(bool)$channels['rest'], 'Bearer'=>[(bool)$channels['bearer'],$this->_('Configured'),$this->_('Not configured')]]);
        if ($once !== '') $out .= '<section class="RelayTokenOnce" role="status"><div><small>' . $this->_('Copy now') . '</small><h2>' . $this->_('New Bearer token') . '</h2><p>' . $this->_('Shown once; Relay stores only its SHA-256 hash.') . '</p></div><code>' . $this->wire('sanitizer')->entities($once) . '</code></section>';
        $out .= $this->interfaceSettings($this->_('API access'), [
            $this->_('Version') => self::REST_API_VERSION,
            $this->_('PHP facade') => $channels['php_api'] ? $this->_('Enabled') : $this->_('Disabled'),
            $this->_('REST routes') => $channels['rest'] ? $this->_('Enabled') : $this->_('Disabled'),
            $this->_('Bearer token') => trim((string)$this->rest_bearer_token_hash) !== '' ? $this->_('Configured') : $this->_('Not configured'),
            $this->_('Token actor') => $actor && $actor->id ? (string)$actor->name : $this->_('Not assigned'),
            $this->_('Rotated') => (string)$this->rest_bearer_token_created_at ?: $this->_('Never'),
        ]);
        $out .= $this->renderBearerPanel();
        $out .= $this->interfaceTable('REST', $this->_('Routes'), $base, [$this->_('Method'),$this->_('Route'),$this->_('Access'),$this->_('Purpose')], array_map(fn($r)=>[$r[0],$base.$r[1],$r[2],$r[3]], $this->relayApiRoutes()));
        $out .= $this->interfaceSafety($this->_('Security boundary'), $this->_('Authentication does not bypass permissions'), $this->_('Session writes require the Relay CSRF token. Bearer requests inherit the assigned actor permissions, are rate-limited, stay header-only, and receive no CORS opt-in.'));
        return $this->interfaceWrap($out);
    }

    public function ___executeCli(): string
    {
        $this->requireInterfaceAdmin();
        $this->configureAdminChrome($this->_('Relay CLI'), $this->_('CLI'), [[$this->processUrl() . 'interfaces/', $this->_('Interfaces')]]);
        $enabled = (int)$this->enable_interface_cli === 1; $command = 'php site/modules/Relay/bin/relay-interface';
        $out = $this->interfaceNav('cli') . $this->interfaceIntro($this->_('Local command interface'), $this->_('Inspect schedule data from the server shell. Commands that change data run only when --execute is supplied explicitly.'), ['CLI'=>$enabled]);
        $out .= $this->interfaceSettings($this->_('Command-line access'), [$this->_('Interface CLI')=>$enabled?$this->_('Enabled'):$this->_('Disabled'),$this->_('Executable')=>'site/modules/Relay/bin/relay-interface',$this->_('Production worker')=>'site/modules/Relay/bin/relay.php',$this->_('Output')=>'JSON']);
        $rows = [];
        foreach ($this->relayCliCommands() as $row) $rows[] = [$row[0], $command . ' ' . $row[1], $row[2]];
        $out .= $this->interfaceTable('CLI',$this->_('Commands'),$command.' help',[$this->_('Mode'),$this->_('Command'),$this->_('Purpose')],$rows);
        $out .= $this->interfaceSafety($this->_('Execution boundary'),$this->_('Reading is direct; changing data is deliberate'),$this->_('The local CLI accepts no remote API credentials. Relay, reschedule, cancel, and run commands require --execute. The production cron worker remains a separate command.'));
        return $this->interfaceWrap($out);
    }

    public function ___executeCrontab(): string
    {
        $this->requireInterfaceAdmin();
        if ($this->wire('input')->requestMethod('POST')) $this->handleWorkerInterfaceAction('crontab');
        $this->configureAdminChrome($this->_('Relay Crontab'), $this->_('Crontab'), [[$this->processUrl() . 'interfaces/', $this->_('Interfaces')]]);
        $status = $this->crontabStatus();
        $out = $this->interfaceNav('crontab') . $this->interfaceIntro($this->_('Production worker schedule'), $this->_('Build a server-ready crontab line from the detected paths and worker limits. This page never installs or executes it.'), [
            'Worker'=>[(bool)$status['worker_available'],$this->_('Available'),$this->_('Missing')],
            'PHP'=>[(bool)$status['php_available'],$this->_('Available'),$this->_('Verify path')],
            'Command'=>[(bool)$status['ready'],$this->_('Ready'),$this->_('Needs attention')],
        ]);
        $out .= $this->interfaceSettings($this->_('Resolved worker'), [
            $this->_('ProcessWire root')=>$status['root'],
            $this->_('Worker script')=>$status['worker'],
            $this->_('PHP binary')=>$status['php_binary'],
            $this->_('Interval')=>sprintf($this->_n('Every %d minute', 'Every %d minutes', $status['interval']), $status['interval']),
            $this->_('Batch limit')=>(string)$status['limit'],
            $this->_('Log file')=>$status['log_path'],
        ], $this->workerSettingsUrl());
        $out .= $this->renderCrontabPanel($status);
        $out .= $this->interfaceSafety($this->_('Server boundary'), $this->_('Relay prepares the command; the server runs it'), $this->_('Saving changes only rebuilds the preview. Review the line, copy it, and install it manually through the hosting control panel or crontab -e. Relay never changes the operating-system crontab itself.'));
        return $this->interfaceWrap($out);
    }

    public function ___executeLazyCron(): string
    {
        $this->requireInterfaceAdmin();
        if ($this->wire('input')->requestMethod('POST')) $this->handleWorkerInterfaceAction('lazy-cron');
        $this->configureAdminChrome($this->_('Relay LazyCron'), $this->_('LazyCron'), [[$this->processUrl() . 'interfaces/', $this->_('Interfaces')]]);
        $status = $this->lazyCronStatus();
        $out = $this->interfaceNav('lazy-cron') . $this->interfaceIntro($this->_('Traffic-driven fallback'), $this->_('Run a bounded worker check after ProcessWire receives traffic. Use this only when a system cron is unavailable and exact timing is not required.'), [
            'Module'=>[(bool)$status['installed'],$this->_('Installed'),$this->_('Missing')],
            'Fallback'=>[(bool)$status['enabled'],$this->_('Enabled'),$this->_('Disabled')],
            'Runtime'=>[(bool)$status['active'],$this->_('Active'),$status['imitation']?$this->_('Paused by imitation'):$this->_('Inactive')],
        ]);
        $out .= $this->interfaceSettings($this->_('Current runtime'), [
            $this->_('LazyCron module')=>$status['installed'] ? 'LazyCron ' . $status['version'] : $this->_('Not installed'),
            $this->_('Hook')=>'LazyCron::everyMinute',
            $this->_('Fallback')=>$status['enabled']?$this->_('Enabled'):$this->_('Disabled'),
            $this->_('Imitation mode')=>$status['imitation']?$this->_('Enabled — workers paused'):$this->_('Disabled'),
            $this->_('Maximum jobs per run')=>(string)$status['limit'],
            $this->_('Timing guarantee')=>$this->_('Traffic-dependent; not exact'),
        ], $this->workerSettingsUrl());
        $out .= $this->renderLazyCronPanel($status);
        $out .= $this->interfaceSafety($this->_('Timing boundary'), $this->_('A request triggers the check, not the clock'), $this->_('Low traffic, full-page caches, or downtime can delay publication because LazyCron runs only after ProcessWire serves a request. Use system crontab when publication time matters.'));
        return $this->interfaceWrap($out);
    }

    public function ___executeEmail(): string
    {
        $this->requireInterfaceAdmin();
        $this->configureAdminChrome($this->_('Relay Email'), $this->_('Email notifications'), [[$this->processUrl() . 'interfaces/', $this->_('Interfaces')]]);
        $status = $this->mailIntegrationStatus();
        $out = $this->interfaceNav('email') . $this->interfaceIntro($this->_('Email notifications'),$this->_('Send selected Relay lifecycle alerts to configured administrators through the chosen WireMail provider.'),[
            'Delivery'=>[(bool)$status['ready'],$this->_('Ready'),$this->_('Not ready')],
            'Provider'=>[(bool)$status['provider_available'],$this->_('Available'),$this->_('Unavailable')],
            'Recipients'=>[$status['recipient_count']>0,$this->_('Configured'),$this->_('Missing')],
        ]);
        $out .= $this->interfaceSettings($this->_('Email delivery'),[$this->_('Delivery')=>$status['enabled']?$this->_('Enabled'):$this->_('Disabled'),$this->_('Readiness')=>$status['ready']?$this->_('Ready'):$this->_('Not ready'),$this->_('Selected provider')=>$status['provider_label'],$this->_('Recipients')=>(string)$status['recipient_count'],$this->_('Sender')=>$status['sender']?:$this->_('Not configured'),$this->_('Events')=>implode(', ',(array)$status['events'])]);
        $out .= $this->interfaceSafety($this->_('Credential boundary'),$this->_('Relay uses WireMail without copying its secrets'),$this->_('API keys and SMTP passwords remain in the selected WireMail provider. Relay sends operational page and job metadata only; a delivery failure never blocks a job state change.'));
        return $this->interfaceWrap($out);
    }

    public function ___executeTelegram(): string
    {
        $this->requireInterfaceAdmin();
        $this->configureAdminChrome($this->_('Relay Telegram'), $this->_('Telegram notifications'), [[$this->processUrl() . 'interfaces/', $this->_('Interfaces')]]);
        $status = $this->telegramIntegrationStatus();
        $source = match ((string)$status['credential_source']) {
            'runtime' => $this->_('Private runtime override'),
            'module' => $this->_('Relay module configuration'),
            default => $this->_('Not configured'),
        };
        $eventLabels = ['published'=>$this->_('Published page'),'scheduled'=>$this->_('Scheduled'),'rescheduled'=>$this->_('Rescheduled'),'cancelled'=>$this->_('Cancelled'),'completed'=>$this->_('Completed action'),'failed'=>$this->_('Failed action')];
        $events = array_map(static fn(string $event): string => (string)($eventLabels[$event] ?? $event), (array)$status['events']);
        $out = $this->interfaceNav('telegram') . $this->interfaceIntro($this->_('Telegram notifications'),$this->_('Send concise Relay lifecycle alerts to configured administrator chats through TeleWire.'),[
            'TeleWire'=>[(bool)$status['integration_compatible'],$this->_('Available'),$this->_('Unavailable')],
            'Delivery'=>[(bool)$status['ready'],$this->_('Ready'),$this->_('Not ready')],
            'Credentials'=>[(bool)$status['configured'],$this->_('Configured'),$this->_('Missing')],
        ]);
        $out .= $this->interfaceSettings($this->_('Telegram delivery'),[$this->_('Integration')=>$status['integration_compatible']?'TeleWire 1.0.2+':$this->_('Unavailable'),$this->_('Delivery')=>$status['enabled']?$this->_('Enabled'):$this->_('Disabled'),$this->_('Credentials')=>$status['configured']?$this->_('Configured') . ' · ' . $source:$this->_('Not configured'),$this->_('Recipients')=>(string)$status['recipient_count'],$this->_('Events')=>implode(', ',$events),$this->_('Timeout')=>max(3,min(30,(int)$this->telegram_timeout_seconds)).'s']);
        $out .= $this->interfaceSafety($this->_('Privacy boundary'),$this->_('Alerts contain metadata, not editorial content'),$this->_('Telegram receives the event, job ID, action, page title, scheduled time, status, and authenticated admin link. Internal notes, credentials, unpublished fields, and page content are excluded.'));
        return $this->interfaceWrap($out);
    }

    public function ___executeSquad(): string
    {
        $this->requireInterfaceAdmin();
        $this->configureAdminChrome($this->_('Relay Squad'), $this->_('Squad assistance'), [[$this->processUrl() . 'interfaces/', $this->_('Interfaces')]]);
        $status = $this->squadIntegrationStatus();
        $out = $this->interfaceNav('squad') . $this->interfaceIntro($this->_('Editorial planning assistance'),$this->_('An editor can request an editable publication-time and note proposal. Nothing is scheduled and no page is changed automatically.'),[
            'Squad'=>[(bool)$status['integration_compatible'],$this->_('Available'),$this->_('Unavailable')],
            'Provider'=>[(bool)$status['provider_ready'],$this->_('Ready'),$this->_('Not ready')],
            'Assistance'=>(bool)$status['ready'],
        ]);
        $out .= $this->interfaceSettings($this->_('Squad assistance'),[
            $this->_('Integration')=>$status['integration_compatible']?'Squad 1.9+':$this->_('Unavailable'),
            $this->_('Assistance')=>$status['enabled']?$this->_('Enabled'):$this->_('Disabled'),
            $this->_('Provider')=>(string)$status['provider']?:$this->_('Not configured'),
            $this->_('Model')=>(string)$status['model']?:$this->_('Squad default'),
            $this->_('Shared fields')=>implode(', ',(array)$status['context_fields']),
            $this->_('Timeout')=>(int)$status['timeout_seconds'].'s',
        ]);
        $out .= $this->interfaceSafety($this->_('Review boundary'),$this->_('The editor remains responsible for every change'),$this->_('Squad receives only the configured page fields after an editor clicks Suggest with Squad. Its response fills editable draft fields; it never creates a job, changes a page, or publishes content.'));
        return $this->interfaceWrap($out);
    }

    private function interfaceWrap(string $html): string { return '<div class="pw-wrap pw-module-workspace RelayAdmin RelayInterfaces">' . $html . '</div>'; }

    private function interfaceNav(string $active): string
    {
        $base = $this->processUrl();
        $items = ['rules'=>[$base.'rules/',$this->_('Rules')],'overview'=>[$base.'interfaces/',$this->_('Overview')],'api'=>[$base.'api/',$this->_('API')],'cli'=>[$base.'cli/',$this->_('CLI')],'crontab'=>[$base.'crontab/',$this->_('Crontab')],'lazy-cron'=>[$base.'lazy-cron/',$this->_('LazyCron')],'email'=>[$base.'email/',$this->_('Email')],'telegram'=>[$base.'telegram/',$this->_('Telegram')],'squad'=>[$base.'squad/',$this->_('Squad')],'calendar-feed'=>[$base.'calendar-feed/',$this->_('Calendar feed')],'transfer'=>[$base.'transfer/',$this->_('Import / Export')]];
        $out = '<nav class="RelayAdminNavigation" aria-label="' . $this->_('Relay interface sections') . '"><ul class="uk-subnav uk-subnav-pill RelayAdminNav"><li><a href="' . $this->wire('sanitizer')->entities($base) . '">' . $this->_('Calendar') . '</a></li>';
        foreach ($items as $key=>[$url,$label]) $out .= '<li' . ($key===$active?' class="uk-active"':'') . '><a href="' . $this->wire('sanitizer')->entities($url) . '"' . ($key===$active?' aria-current="page"':'') . '>' . $label . '</a></li>';
        return $out . '</ul></nav>';
    }

    private function interfaceIntro(string $context,string $description,array $states): string
    {
        $densityClass = count($states) > 4 ? ' RelayInterfaceIntro--dense' : '';
        $out = '<section class="RelayInterfaceIntro' . $densityClass . '"><div><h2>' . $this->wire('sanitizer')->entities($context) . '</h2><p>' . $this->wire('sanitizer')->entities($description) . '</p></div><div class="RelayInterfaceSummary">';
        foreach ($states as $label=>$state) {
            $enabled = is_array($state) ? (bool)($state[0] ?? false) : (bool)$state;
            $enabledText = is_array($state) ? (string)($state[1] ?? '') : '';
            $disabledText = is_array($state) ? (string)($state[2] ?? '') : '';
            $out .= $this->interfaceState((string)$label,$enabled,$enabledText,$disabledText);
        }
        return $out . '</div></section>';
    }

    private function interfaceState(string $label,bool $enabled,string $enabledText = '',string $disabledText = ''): string
    {
        $stateText = $enabled
            ? ($enabledText !== '' ? $enabledText : $this->_('Enabled'))
            : ($disabledText !== '' ? $disabledText : $this->_('Disabled'));
        return '<span class="RelayInterfaceState" data-state="' . ($enabled?'enabled':'disabled') . '"><i></i>' . $this->wire('sanitizer')->entities($label) . ' <strong>' . $this->wire('sanitizer')->entities($stateText) . '</strong></span>';
    }

    private function interfaceCard(string $icon,string $eyebrow,string $title,string $description,string $route,bool $enabled,string $enabledText = '',string $disabledText = ''): string
    {
        return '<article class="RelayInterfaceCard"><header><span><i class="fa fa-' . $this->wire('sanitizer')->name($icon) . '"></i></span><div><small>' . $this->wire('sanitizer')->entities($eyebrow) . '</small><h2>' . $this->wire('sanitizer')->entities($title) . '</h2></div></header><p>' . $this->wire('sanitizer')->entities($description) . '</p><footer>' . $this->interfaceState($this->_('Status'),$enabled,$enabledText,$disabledText) . '<a class="uk-button uk-button-default" href="' . $this->processUrl() . $route . '/">' . $this->_('View details') . ' <i class="fa fa-arrow-right" aria-hidden="true"></i></a></footer></article>';
    }

    private function interfaceSettings(string $title,array $items,string $settingsUrl = ''): string
    {
        $settingsUrl = $settingsUrl !== '' ? $settingsUrl : $this->interfaceSettingsUrl();
        $fragment = (string) (parse_url($settingsUrl, PHP_URL_FRAGMENT) ?: '');
        $scrollAttribute = preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $fragment)
            ? ' data-relay-scroll-target="' . $this->wire('sanitizer')->entities($fragment) . '"'
            : '';
        $out = '<section class="RelayInterfaceSettings"><header><div><small>' . $this->_('Configuration snapshot') . '</small><h2>' . $this->wire('sanitizer')->entities($title) . '</h2></div><a class="uk-button uk-button-default" href="' . $this->wire('sanitizer')->entities($settingsUrl) . '"' . $scrollAttribute . '>' . $this->_('Edit settings') . '</a></header><dl>';
        foreach ($items as $label=>$value) $out .= '<div><dt>' . $this->wire('sanitizer')->entities((string)$label) . '</dt><dd>' . $this->wire('sanitizer')->entities((string)$value) . '</dd></div>';
        return $out . '</dl></section>';
    }

    private function interfaceTable(string $eyebrow,string $title,string $code,array $headers,array $rows): string
    {
        $out = '<section class="RelayInterfaceTable"><header><div><small>' . $this->wire('sanitizer')->entities($eyebrow) . '</small><h2>' . $this->wire('sanitizer')->entities($title) . '</h2></div><code>' . $this->wire('sanitizer')->entities($code) . '</code></header><div class="uk-overflow-auto"><table class="uk-table uk-table-divider uk-table-middle"><thead><tr>';
        foreach ($headers as $header) $out .= '<th>' . $this->wire('sanitizer')->entities((string)$header) . '</th>';
        $out .= '</tr></thead><tbody>';
        foreach ($rows as $row) { $out .= '<tr>'; foreach ($row as $index=>$value) $out .= '<td>' . ($index===1?'<code>'.$this->wire('sanitizer')->entities((string)$value).'</code>':$this->wire('sanitizer')->entities((string)$value)) . '</td>'; $out .= '</tr>'; }
        return $out . '</tbody></table></div></section>';
    }

    private function interfaceSafety(string $eyebrow,string $title,string $copy): string
    {
        return '<section class="RelayInterfaceSafety"><i class="fa fa-shield" aria-hidden="true"></i><div><small>' . $this->wire('sanitizer')->entities($eyebrow) . '</small><h2>' . $this->wire('sanitizer')->entities($title) . '</h2><p>' . $this->wire('sanitizer')->entities($copy) . '</p></div></section>';
    }

    private function crontabStatus(): array
    {
        $root = rtrim((string)$this->wire('config')->paths->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $modulePath = rtrim((string)$this->wire('config')->paths($this), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $worker = $modulePath . 'bin' . DIRECTORY_SEPARATOR . 'relay.php';
        $php = trim((string)$this->cron_php_binary);
        if ($php === '') $php = $this->detectedCliPhpBinary();
        $log = trim((string)$this->cron_log_path) ?: 'site/assets/logs/relay-cron.log';
        if (!str_starts_with($log, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\/]/', $log)) $log = $root . ltrim($log, '/\\');
        $interval = (int)$this->cron_interval_minutes;
        if (!in_array($interval, [1,2,5,10,15,30], true)) $interval = 1;
        $limit = max(1, min(500, (int)$this->max_batch));
        $expression = $interval === 1 ? '* * * * *' : '*/' . $interval . ' * * * *';
        $phpAvailable = $php !== '' && is_file($php) && is_executable($php)
            && !preg_match('/(?:php-cgi|php-fpm)(?:\.exe)?$/i', basename($php));
        $workerAvailable = is_file($worker);
        $phpForCommand = $php !== '' ? $php : '/absolute/path/to/php';
        $loggingEnabled = (int)$this->enable_logging === 1;
        $outputPath = $loggingEnabled ? $log : (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        $command = $expression . ' cd ' . escapeshellarg(rtrim($root, DIRECTORY_SEPARATOR))
            . ' && ' . escapeshellarg($phpForCommand) . ' ' . escapeshellarg($worker)
            . ' --root=' . escapeshellarg(rtrim($root, DIRECTORY_SEPARATOR)) . ' --limit=' . $limit
            . ' >> ' . escapeshellarg($outputPath) . ' 2>&1';
        return ['root'=>rtrim($root,DIRECTORY_SEPARATOR),'worker'=>$worker,'php_binary'=>$php !== '' ? $php : $this->_('Not detected'),'log_path'=>$log,'interval'=>$interval,'limit'=>$limit,
            'logging_enabled'=>$loggingEnabled,'worker_available'=>$workerAvailable,'php_available'=>$phpAvailable,'ready'=>$workerAvailable && $phpAvailable,'command'=>$command];
    }

    private function lazyCronStatus(): array
    {
        $installed = $this->wire('modules')->isInstalled('LazyCron');
        $info = $installed ? (array)$this->wire('modules')->getModuleInfo('LazyCron') : [];
        $version = max(0, (int)($info['version'] ?? 0));
        $versionLabel = $version > 0 ? intdiv($version,100) . '.' . intdiv($version % 100,10) . '.' . ($version % 10) : $this->_('Unknown version');
        $enabled = (int)$this->lazy_cron_fallback === 1;
        $imitation = $this->isImitationMode();
        return ['installed'=>$installed,'version'=>$versionLabel,'enabled'=>$enabled,'imitation'=>$imitation,
            'active'=>$installed && $enabled && !$imitation,'limit'=>max(1,min(500,(int)$this->max_batch))];
    }

    private function renderCrontabPanel(array $status): string
    {
        $sanitizer = $this->wire('sanitizer');
        $token = $this->wire('session')->CSRF->getToken();
        $options = '';
        foreach ([1,2,5,10,15,30] as $minutes) {
            $options .= '<option value="' . $minutes . '"' . ((int)$status['interval'] === $minutes ? ' selected' : '') . '>'
                . sprintf($this->_n('Every %d minute', 'Every %d minutes', $minutes), $minutes) . '</option>';
        }
        return '<section class="RelayWorkerPanel"><header><div><small>CRON</small><h2>' . $this->_('Crontab command builder') . '</h2><p>'
            . $this->_('Save the worker parameters, then copy the generated line into the server scheduler.') . '</p></div>'
            . $this->interfaceState($this->_('Command'),(bool)$status['ready'],$this->_('Ready'),$this->_('Needs attention')) . '</header>'
            . '<form method="post" class="RelayWorkerForm"><input type="hidden" name="interface_action" value="save_crontab">'
            . '<input type="hidden" name="' . $sanitizer->entities((string)$token['name']) . '" value="' . $sanitizer->entities((string)$token['value']) . '">'
            . '<label>' . $this->_('Interval') . '<select class="uk-select" name="cron_interval_minutes">' . $options . '</select></label>'
            . '<label>' . $this->_('Batch limit') . '<input class="uk-input" type="number" name="max_batch" min="1" max="500" value="' . (int)$status['limit'] . '"></label>'
            . '<label>' . $this->_('PHP binary') . '<input class="uk-input" type="text" name="cron_php_binary" maxlength="500" value="' . $sanitizer->entities((string)$this->cron_php_binary) . '" placeholder="' . $sanitizer->entities((string)$status['php_binary']) . '"></label>'
            . '<label class="RelayWorkerForm__wide">' . $this->_('Log path') . '<input class="uk-input" type="text" name="cron_log_path" maxlength="500" value="' . $sanitizer->entities((string)$this->cron_log_path) . '" required' . (!$status['logging_enabled'] ? ' readonly aria-describedby="RelayCronLogHelp"' : '') . '>'
            . (!$status['logging_enabled'] ? '<small id="RelayCronLogHelp">' . $this->_('Relay logging is disabled. Generated worker output will be discarded.') . '</small>' : '') . '</label>'
            . '<div class="RelayWorkerForm__actions"><button class="uk-button uk-button-primary" type="submit">' . $this->_('Save command settings') . '</button></div></form>'
            . '<div class="RelayCronCommand" data-copy-success="' . $sanitizer->entities($this->_('Copied')) . '" data-copy-failed="' . $sanitizer->entities($this->_('Copy failed')) . '"><div><small>' . $this->_('Generated crontab line') . '</small><strong>' . $this->_('Review before installing') . '</strong></div>'
            . '<pre><code data-relay-cron-command>' . $sanitizer->entities((string)$status['command']) . '</code></pre><button type="button" class="uk-button uk-button-default" data-relay-copy-command><i class="fa fa-copy" aria-hidden="true"></i> <span>' . $this->_('Copy line') . '</span></button></div></section>';
    }

    private function renderLazyCronPanel(array $status): string
    {
        $sanitizer = $this->wire('sanitizer');
        $token = $this->wire('session')->CSRF->getToken();
        return '<section class="RelayWorkerPanel"><header><div><small>ProcessWire</small><h2>' . $this->_('LazyCron configuration') . '</h2><p>'
            . $this->_('Enable or pause the every-minute fallback and set its bounded batch size.') . '</p></div>'
            . $this->interfaceState($this->_('Module'),(bool)$status['installed'],$this->_('Installed'),$this->_('Missing')) . '</header>'
            . '<form method="post" class="RelayWorkerForm RelayWorkerForm--lazy"><input type="hidden" name="interface_action" value="save_lazy_cron">'
            . '<input type="hidden" name="' . $sanitizer->entities((string)$token['name']) . '" value="' . $sanitizer->entities((string)$token['value']) . '">'
            . '<label class="RelayWorkerToggle"><input type="checkbox" name="lazy_cron_fallback" value="1"' . ($status['enabled']?' checked':'') . (!$status['installed']?' disabled':'') . '><span><strong>'
            . $this->_('Enable LazyCron fallback') . '</strong><small>' . $this->_('Runs after LazyCron::everyMinute only when ProcessWire receives traffic.') . '</small></span></label>'
            . '<label>' . $this->_('Maximum jobs per run') . '<input class="uk-input" type="number" name="max_batch" min="1" max="500" value="' . (int)$status['limit'] . '"></label>'
            . '<div class="RelayWorkerForm__actions"><button class="uk-button uk-button-primary" type="submit">' . $this->_('Save LazyCron settings') . '</button></div></form>'
            . ($status['imitation'] ? '<p class="RelayWorkerNotice"><i class="fa fa-flask" aria-hidden="true"></i> ' . $this->_('Imitation mode is active. All workers remain paused until it is disabled.') . '</p>' : '') . '</section>';
    }

    private function handleWorkerInterfaceAction(string $screen): void
    {
        $this->wire('session')->CSRF->validate();
        $action = (string)$this->wire('input')->post('interface_action');
        $config = array_merge(self::getDefaultConfig(), (array)$this->wire('modules')->getConfig('Relay'));
        $config['max_batch'] = max(1, min(500, (int)$this->wire('input')->post('max_batch')));
        if ($screen === 'crontab' && $action === 'save_crontab') {
            $interval = (int)$this->wire('input')->post('cron_interval_minutes');
            if (!in_array($interval,[1,2,5,10,15,30],true)) throw new WireException($this->_('Unsupported crontab interval.'));
            $config['cron_interval_minutes'] = $interval;
            $config['cron_php_binary'] = $this->validatedCronPath((string)$this->wire('input')->post('cron_php_binary'), true, true);
            $config['cron_log_path'] = $this->validatedCronPath((string)$this->wire('input')->post('cron_log_path'), false);
        } elseif ($screen === 'lazy-cron' && $action === 'save_lazy_cron') {
            $enabled = $this->wire('input')->post('lazy_cron_fallback') !== null;
            if ($enabled && !$this->wire('modules')->isInstalled('LazyCron')) throw new WireException($this->_('Install the ProcessWire LazyCron module before enabling the fallback.'));
            $config['lazy_cron_fallback'] = $enabled ? 1 : 0;
        } else {
            throw new WireException($this->_('Unsupported worker settings action.'));
        }
        $this->wire('modules')->saveConfig('Relay',$config);
        $this->wire('session')->redirect($this->processUrl() . ($screen === 'crontab' ? 'crontab/' : 'lazy-cron/'));
    }

    private function validatedCronPath(string $value,bool $allowEmpty,bool $requireAbsolute = false): string
    {
        $value = trim($value);
        if ($value === '' && $allowEmpty) return '';
        if ($value === '' || mb_strlen($value) > 500 || preg_match('/[\x00\r\n]/', $value) || !preg_match('/^[\pL\pN _\.\-:\/\\\\]+$/u', $value)) {
            throw new WireException($this->_('Cron paths may contain only ordinary path characters and must not contain line breaks.'));
        }
        if ($requireAbsolute && !str_starts_with($value, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\/]/', $value)) {
            throw new WireException($this->_('The cron PHP binary must use an absolute path.'));
        }
        return $value;
    }

    private function detectedCliPhpBinary(): string
    {
        $binary = (string)PHP_BINARY;
        if (!preg_match('/(?:php-cgi|php-fpm)(?:\.exe)?$/i', basename($binary))) return $binary;
        $candidate = dirname($binary) . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');
        return is_file($candidate) && is_executable($candidate) ? $candidate : '';
    }

    private function relayApiRoutes(): array
    {
        return [
            ['GET','session/','Session',$this->_('Permissions and mutation CSRF token.')],['GET','capabilities/','Session or Bearer',$this->_('Channels and stable capabilities.')],
            ['GET','counts/','Session or Bearer',$this->_('Job totals by status.')],['GET','jobs/?from=&to=&limit=100','Session or Bearer',$this->_('Bounded filtered job list.')],
            ['GET','job/?id={id}','Session or Bearer',$this->_('One redacted schedule job.')],['POST','schedule/','Session CSRF or Bearer',$this->_('Create an action or publication window.')],
            ['POST','reschedule/','Session CSRF or Bearer',$this->_('Move a pending action.')],['POST','cancel/','Session CSRF or Bearer',$this->_('Cancel a pending action.')],
            ['POST','run/','Session CSRF or Bearer + relay-run',$this->_('Run a bounded due batch.')],
        ];
    }

    private function relayCliCommands(): array
    {
        return [['read','capabilities',$this->_('Channel and capability manifest.')],['read','counts',$this->_('Status totals.')],['read','jobs --limit=100',$this->_('Bounded filtered jobs.')],['read','job --id=1',$this->_('One job.')],['write','schedule --stdin --execute',$this->_('Create action/window from JSON.')],['write','reschedule --id=1 --stdin --execute',$this->_('Move pending job.')],['write','cancel --id=1 --execute',$this->_('Cancel pending job.')],['write','run --limit=50 --execute',$this->_('Run due work.')]];
    }

    private function renderBearerPanel(): string
    {
        $options=''; foreach ($this->wire('users')->find('include=all, sort=name') as $candidate) {
            if (!$candidate->id || (!$candidate->isSuperuser() && (!$candidate->hasPermission('relay-api') || !$candidate->hasPermission('relay-manage')))) continue;
            $options .= '<option value="'.(int)$candidate->id.'"'.((int)$this->rest_bearer_user_id===(int)$candidate->id?' selected':'').'>'.$this->wire('sanitizer')->entities((string)$candidate->name).'</option>';
        }
        $out='<section class="RelayTokenPanel"><header><div><small>Bearer</small><h2>'.$this->_('Token credential').'</h2></div>'.$this->interfaceState($this->_('Token'),trim((string)$this->rest_bearer_token_hash)!=='').'</header><p>'.$this->_('Generate one scoped token for an eligible ProcessWire actor. Rotation invalidates the previous token immediately.').'</p>';
        if ($options==='') return $out.'<p>'.$this->_('No eligible actor exists. Grant relay-api and relay-manage first.').'</p></section>';
        $token=$this->wire('session')->CSRF->getToken();
        $csrf='<input type="hidden" name="'.$this->wire('sanitizer')->entities((string)$token['name']).'" value="'.$this->wire('sanitizer')->entities((string)$token['value']).'">';
        $out.='<div class="RelayTokenActions"><form method="post"><input type="hidden" name="interface_action" value="rotate_bearer">'.$csrf.'<label>'.$this->_('Token actor').'<select class="uk-select" name="bearer_user_id">'.$options.'</select></label><button class="uk-button uk-button-primary">'.(trim((string)$this->rest_bearer_token_hash)!==''?$this->_('Rotate token'):$this->_('Generate token')).'</button></form>';
        if (trim((string)$this->rest_bearer_token_hash)!=='') $out.='<form method="post"><input type="hidden" name="interface_action" value="revoke_bearer">'.$csrf.'<button class="uk-button uk-button-danger">'.$this->_('Revoke token').'</button></form>';
        return $out.'</div></section>';
    }

    private function handleBearerTokenAction(): void
    {
        $this->wire('session')->CSRF->validate(); $action=(string)$this->wire('input')->post('interface_action');
        $config=(array)$this->wire('modules')->getConfig('Relay');
        if ($action==='rotate_bearer') {
            $actor=$this->wire('users')->get((int)$this->wire('input')->post('bearer_user_id'));
            if (!$actor->id || (!$actor->isSuperuser() && (!$actor->hasPermission('relay-api') || !$actor->hasPermission('relay-manage')))) throw new WirePermissionException('Bearer actor is not eligible.');
            $token='schedule_'.self::REST_API_VERSION.'_'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
            $config['rest_bearer_token_hash']=hash('sha256',$token); $config['rest_bearer_user_id']=(int)$actor->id; $config['rest_bearer_token_created_at']=date('Y-m-d H:i:s');
            $this->wire('session')->set('relay_bearer_token_once',$token);
        } elseif ($action==='revoke_bearer') { $config['rest_bearer_token_hash']=''; $config['rest_bearer_user_id']=0; $config['rest_bearer_token_created_at']=''; }
        else throw new WireException('Unsupported interface action.');
        $this->wire('modules')->saveConfig('Relay',$config); $this->wire('session')->redirect($this->processUrl().'api/');
    }

    private function requireInterfaceAdmin(): void
    {
        if (!$this->canAdmin()) throw new WirePermissionException('You cannot manage Relay interfaces.');
        $this->enqueueAssets();
    }

    private function interfaceSettingsUrl(): string { return $this->wire('config')->urls->admin.'module/edit?name=Relay&collapse_info=1#Inputfield_relay_config_interfaces'; }

    private function workerSettingsUrl(): string { return $this->wire('config')->urls->admin.'module/edit?name=Relay&collapse_info=1#Inputfield_relay_config_worker'; }
}
