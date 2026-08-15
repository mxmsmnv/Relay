<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * ProcessWire module configuration form and configuration overview.
 */
trait RelayConfigTrait
{
    public function getModuleConfigInputfields(array $data): InputfieldWrapper
    {
        $data = array_merge(self::getDefaultConfig(), $data);
        $fields = $this->wire(new InputfieldWrapper());

        $moduleUrl = (string) $this->wire('config')->urls($this);
        if ($moduleUrl === '') {
            $moduleUrl = $this->wire('config')->urls->siteModules . 'Relay/';
        }
        $this->wire('config')->styles->add($moduleUrl . 'assets/relay-config.css?v=' . self::VERSION);
        $this->wire('config')->scripts->add($moduleUrl . 'assets/relay-config.js?v=' . self::VERSION);

        $overview = $this->wire('modules')->get('InputfieldMarkup');
        $overview->name = 'relay_config_overview';
        $overview->label = $this->_('Relay settings');
        $overview->icon = 'calendar';
        $overview->addClass('RelayConfigOverview', 'wrapClass');
        $overview->value = $this->renderConfigOverview($data);
        $fields->add($overview);

        $planning = $this->wire('modules')->get('InputfieldFieldset');
        $planning->name = 'relay_config_planning';
        $planning->label = $this->_('Editorial planning');
        $planning->icon = 'calendar';
        $planning->description = $this->_('Defaults editors see when they plan and review publication changes.');
        $planning->collapsed = Inputfield::collapsedNo;
        $planning->addClass('RelayConfigSection RelayConfigSection--expanded', 'wrapClass');
        $fields->add($planning);

        $presets = $this->wire('modules')->get('InputfieldFieldset');
        $presets->name = 'relay_config_presets';
        $presets->label = $this->_('Publication times');
        $presets->icon = 'clock-o';
        $presets->description = $this->_('Quick time choices for one-time publication scheduling. Recurrence is configured in Rules.');
        $presets->collapsed = Inputfield::collapsedYes;
        $presets->addClass('RelayConfigSection', 'wrapClass');
        $fields->add($presets);

        $scope = $this->wire('modules')->get('InputfieldFieldset');
        $scope->name = 'relay_config_scope';
        $scope->label = $this->_('Scope and identities');
        $scope->icon = 'users';
        $scope->description = $this->_('Choose which pages and editorial identities Relay may operate on.');
        $scope->collapsed = Inputfield::collapsedYes;
        $scope->addClass('RelayConfigSection', 'wrapClass');
        $fields->add($scope);

        $worker = $this->wire('modules')->get('InputfieldFieldset');
        $worker->name = 'relay_config_worker';
        $worker->label = $this->_('Worker and resilience');
        $worker->icon = 'cogs';
        $worker->description = $this->_('Bound execution, retry failed work, and configure the optional traffic-driven fallback.');
        $worker->collapsed = Inputfield::collapsedYes;
        $worker->addClass('RelayConfigSection', 'wrapClass');
        $fields->add($worker);

        $timezone = $this->wire('modules')->get('InputfieldSelect');
        $timezone->name = 'timezone';
        $timezone->label = $this->_('Editorial timezone');
        $timezone->description = $this->_('Dates are entered in this timezone and stored as UTC. Existing jobs retain their timezone snapshot.');
        foreach (\DateTimeZone::listIdentifiers() as $zone) {
            $timezone->addOption($zone);
        }
        $timezone->value = $data['timezone'];
        $timezone->required = true;
        $timezone->columnWidth = 40;
        $planning->add($timezone);

        $defaultView = $this->wire('modules')->get('InputfieldSelect');
        $defaultView->name = 'default_view';
        $defaultView->label = $this->_('Default planning view');
        $defaultView->description = $this->_('The first view editors see when opening the global publishing schedule.');
        foreach (['month' => $this->_('Month'), 'week' => $this->_('Week'), 'quarter' => $this->_('Quarter'), 'three-day' => $this->_('3 days'), 'kanban' => $this->_('Kanban'), 'timeline' => $this->_('Timeline')] as $value => $label) {
            $defaultView->addOption($value, $label);
        }
        $defaultView->value = $data['default_view'];
        $defaultView->columnWidth = 20;
        $planning->add($defaultView);

        $weekStartsOn = $this->wire('modules')->get('InputfieldSelect');
        $weekStartsOn->name = 'week_starts_on';
        $weekStartsOn->label = $this->_('First day of the week');
        $weekStartsOn->description = $this->_('Controls the weekday order and range boundaries in month, week, quarter, and timeline views.');
        $weekStartsOn->addOption('monday', $this->_('Monday'));
        $weekStartsOn->addOption('sunday', $this->_('Sunday'));
        $weekStartsOn->value = in_array((string) $data['week_starts_on'], ['monday', 'sunday'], true)
            ? (string) $data['week_starts_on']
            : 'monday';
        $weekStartsOn->required = true;
        $weekStartsOn->columnWidth = 20;
        $planning->add($weekStartsOn);

        $defaultTime = $this->wire('modules')->get('InputfieldText');
        $defaultTime->name = 'default_time';
        $defaultTime->label = $this->_('Default scheduling time');
        $defaultTime->description = $this->_('Used when the page composer opens. Enter a 24-hour time such as 09:00.');
        $defaultTime->value = $data['default_time'];
        $defaultTime->required = true;
        $defaultTime->attr('pattern', '^(?:[01]\\d|2[0-3]):[0-5]\\d$');
        $defaultTime->columnWidth = 20;
        $planning->add($defaultTime);

        $timePresets = $this->wire('modules')->get('InputfieldTextarea');
        $timePresets->name = 'time_presets';
        $timePresets->label = $this->_('Publication times');
        $timePresets->description = $this->_('Add one preset per line in the form Label|HH:MM. Relay ignores invalid and duplicate times.');
        $timePresets->notes = $this->_('Presets change only the time and keep the date already selected by the editor. Leave empty to hide them.');
        $timePresets->rows = 7;
        $timePresets->maxlength = 1000;
        $timePresets->value = (string)$data['time_presets'];
        $timePresets->columnWidth = 100;
        $presets->add($timePresets);

        $futureYears = $this->wire('modules')->get('InputfieldInteger');
        $futureYears->name = 'max_future_years';
        $futureYears->label = $this->_('Maximum planning horizon (years)');
        $futureYears->description = $this->_('Keep plans within a deliberate time range and reject accidental far-future dates. Choose 1–20 years.');
        $futureYears->min = 1;
        $futureYears->max = 20;
        $futureYears->value = (int) $data['max_future_years'];
        $futureYears->columnWidth = 20;
        $planning->add($futureYears);

        $weekendHighlight = $this->wire('modules')->get('InputfieldCheckbox');
        $weekendHighlight->name = 'highlight_weekends';
        $weekendHighlight->label = $this->_('Highlight Saturdays and Sundays');
        $weekendHighlight->description = $this->_('Adds a subtle weekend background in date-based calendar views. Disable it for a uniform grid.');
        $weekendHighlight->checked = !empty($data['highlight_weekends']);
        $weekendHighlight->columnWidth = 20;
        $planning->add($weekendHighlight);

        $templateControls = $this->wire('modules')->get('InputfieldCheckbox');
        $templateControls->name = 'enable_template_controls';
        $templateControls->label = $this->_('Enable template filtering and sorting');
        $templateControls->description = $this->_('Shows Template and Order controls in the calendar. Disable it to keep the filter bar focused on actions and statuses only.');
        $templateControls->checked = !empty($data['enable_template_controls']);
        $templateControls->columnWidth = 20;
        $planning->add($templateControls);

        $treeMarkers = $this->wire('modules')->get('InputfieldCheckbox');
        $treeMarkers->name = 'show_page_tree_markers';
        $treeMarkers->label = $this->_('Show upcoming actions in the page tree');
        $treeMarkers->description = $this->_('Adds a compact action/clock marker and local-time tooltip for users with relay-view.');
        $treeMarkers->checked = !empty($data['show_page_tree_markers']);
        $treeMarkers->columnWidth = 20;
        $planning->add($treeMarkers);

        $dragDrop = $this->wire('modules')->get('InputfieldCheckbox');
        $dragDrop->name = 'enable_drag_drop';
        $dragDrop->label = $this->_('Enable drag-and-drop rescheduling');
        $dragDrop->description = $this->_('Editors with relay-manage can move pending actions between calendar dates. The original local time is preserved and permissions are checked again on drop.');
        $dragDrop->checked = !empty($data['enable_drag_drop']);
        $dragDrop->columnWidth = 20;
        $planning->add($dragDrop);

        $selectedTemplates = $this->configListValues($data['allowed_templates']);
        $templateOptions = [];
        foreach ($this->wire('templates') as $template) {
            if ((string)$template->name === 'admin' || ((int)$template->flags & Template::flagSystem)) continue;
            $name = (string)$template->name;
            $label = trim((string)$template->getLabel());
            $templateOptions[$name] = $label !== '' && $label !== $name ? $label . ' (' . $name . ')' : $name;
        }
        foreach ($selectedTemplates as $name) {
            if (!isset($templateOptions[$name])) $templateOptions[$name] = $name . ' (' . $this->_('unavailable') . ')';
        }
        $templates = $this->wire('modules')->get('InputfieldAsmSelect');
        $templates->name = 'allowed_templates';
        $templates->label = $this->_('Allowed templates');
        $templates->description = $this->_('Choose one or more page templates. Leave empty to allow every editable non-admin page.');
        $templates->notes = $this->_('System and admin templates are excluded from the choices.');
        $templates->addOptions($templateOptions);
        $templates->value = $selectedTemplates;
        $templates->set('sortable', false);
        $templates->columnWidth = 60;
        $scope->add($templates);

        $selectedRoles = $this->configListValues($data['actor_roles']);
        $roleOptions = [];
        foreach ($this->wire('roles') as $role) {
            $name = (string)$role->name;
            if ($name === 'guest') continue;
            $label = trim((string)$role->get('title|name'));
            $roleOptions[$name] = $label !== '' && $label !== $name ? $label . ' (' . $name . ')' : $name;
        }
        foreach ($selectedRoles as $name) {
            if (!isset($roleOptions[$name])) $roleOptions[$name] = $name . ' (' . $this->_('unavailable') . ')';
        }
        $roles = $this->wire('modules')->get('InputfieldAsmSelect');
        $roles->name = 'actor_roles';
        $roles->label = $this->_('Editorial identity roles');
        $roles->description = $this->_('Choose the roles whose users may be selected by someone with relay-run-as permission.');
        $roles->addOptions($roleOptions);
        $roles->value = $selectedRoles;
        $roles->set('sortable', false);
        $roles->columnWidth = 40;
        $scope->add($roles);

        foreach ([
            'max_batch' => [$this->_('Maximum jobs per worker run'), 1, 500],
            'max_attempts' => [$this->_('Maximum attempts'), 1, 20],
            'stale_minutes' => [$this->_('Stale worker lease (minutes)'), 1, 1440],
        ] as $name => [$label, $min, $max]) {
            $field = $this->wire('modules')->get('InputfieldInteger');
            $field->name = $name;
            $field->label = $label;
            $field->min = $min;
            $field->max = $max;
            $field->value = (int) $data[$name];
            $field->columnWidth = $name === 'max_batch' ? 34 : 33;
            $worker->add($field);
        }

        $fallback = $this->wire('modules')->get('InputfieldCheckbox');
        $fallback->name = 'lazy_cron_fallback';
        $fallback->label = $this->_('Enable LazyCron fallback');
        $fallback->description = $this->_('Not exact: it runs only when the site receives traffic. Use the CLI worker every minute for production.');
        $fallback->checked = !empty($data['lazy_cron_fallback']);
        $fallback->columnWidth = 50;
        $worker->add($fallback);

        $logging = $this->wire('modules')->get('InputfieldCheckbox');
        $logging->name = 'enable_logging';
        $logging->label = $this->_('Enable Relay logging');
        $logging->description = $this->_('Writes audit, integration, and performance events to ProcessWire logs and keeps the generated crontab worker output file.');
        $logging->notes = $this->_('Disable this only when another observability system captures Relay activity. Existing log files are not deleted.');
        $logging->checked = !empty($data['enable_logging']);
        $logging->columnWidth = 50;
        $worker->add($logging);

        $cronInterval = $this->wire('modules')->get('InputfieldSelect');
        $cronInterval->name = 'cron_interval_minutes';
        $cronInterval->label = $this->_('Crontab interval');
        $cronInterval->description = $this->_('Used to generate the crontab line in Interfaces. One minute is recommended for publication timing.');
        foreach ([1, 2, 5, 10, 15, 30] as $minutes) {
            $cronInterval->addOption((string)$minutes, sprintf($this->_n('Every %d minute', 'Every %d minutes', $minutes), $minutes));
        }
        $cronInterval->value = (int)$data['cron_interval_minutes'];
        $cronInterval->columnWidth = 25;
        $worker->add($cronInterval);

        $cronPhp = $this->wire('modules')->get('InputfieldText');
        $cronPhp->name = 'cron_php_binary';
        $cronPhp->label = $this->_('Crontab PHP binary');
        $cronPhp->description = $this->_('Optional absolute CLI PHP path. Leave empty to use the binary detected by Relay.');
        $cronPhp->value = (string)$data['cron_php_binary'];
        $cronPhp->columnWidth = 25;
        $worker->add($cronPhp);

        $cronLog = $this->wire('modules')->get('InputfieldText');
        $cronLog->name = 'cron_log_path';
        $cronLog->label = $this->_('Crontab log path');
        $cronLog->description = $this->_('Absolute path or a path relative to the ProcessWire root. Relay only generates the command; it never writes the system crontab.');
        $cronLog->value = (string)$data['cron_log_path'];
        $cronLog->columnWidth = 50;
        $worker->add($cronLog);

        $imitation = $this->wire('modules')->get('InputfieldCheckbox');
        $imitation->name = 'imitation_mode';
        $imitation->label = $this->_('Enable imitation mode');
        $imitation->description = $this->_('A session-only demo sandbox. Create, reschedule, cancel, filter, and run test actions without writing schedule jobs or changing pages. Real jobs are hidden and workers are paused while enabled.');
        $imitation->notes = $this->_('Disable this option to return to the real schedule. Imitation data can be cleared from the Relay workspace.');
        $imitation->checked = !empty($data['imitation_mode']);
        $imitation->columnWidth = 100;
        $worker->add($imitation);

        $interfaces = $this->wire('modules')->get('InputfieldFieldset');
        $interfaces->name = 'relay_config_interfaces';
        $interfaces->label = $this->_('Operational interfaces');
        $interfaces->icon = 'exchange';
        $interfaces->description = $this->_('Expose Relay only through explicit, independently enabled API, CLI, email, and Telegram channels.');
        $interfaces->collapsed = Inputfield::collapsedYes;
        $interfaces->addClass('RelayConfigSection', 'wrapClass');
        $fields->add($interfaces);

        $telegramStatus = $this->telegramIntegrationStatus();
        $telegramIntegration = $this->wire('modules')->get('InputfieldMarkup');
        $telegramIntegration->label = $this->_('TeleWire integration');
        $telegramIntegration->value = $telegramStatus['integration_compatible']
            ? '<p><strong>' . $this->_('Connected') . '</strong> — ' . $this->_('Relay uses TeleWire 1.0.2+ as its transport while keeping separate credentials, recipients, and events.') . '</p>'
            : '<p><strong>' . $this->_('Unavailable') . '</strong> — ' . $this->_('Install or update TeleWire 1.0.2 before enabling Telegram delivery.') . '</p>';
        $interfaces->add($telegramIntegration);

        foreach ([
            'enable_agent_api' => [$this->_('Enable permission-gated PHP API'), $this->_('Requires an authenticated actor with relay-api and relay-manage. This setting alone creates no HTTP route.')],
            'enable_rest_api' => [$this->_('Enable versioned JSON REST API'), $this->_('Adds /relay-api/v1/ routes with session/CSRF or an explicitly rotated Bearer credential. CORS remains disabled.')],
            'enable_interface_cli' => [$this->_('Enable local JSON interface CLI'), $this->_('Enables bin/relay-interface. Mutations require --execute. The production worker bin/relay.php remains independent.')],
            'mail_notifications_enabled' => [$this->_('Enable operational email notifications'), $this->_('Sends only selected Relay events through the chosen WireMail provider.')],
            'telegram_notifications_enabled' => [$this->_('Enable Telegram administrator notifications'), $this->_('Uses TeleWire with Relay-owned credentials. Delivery failures never block scheduling or worker execution.')],
        ] as $name => [$label, $description]) {
            $field = $this->wire('modules')->get('InputfieldCheckbox');
            $field->name = $name;
            $field->label = $label;
            $field->description = $description;
            $field->checked = !empty($data[$name]);
            if ($name === 'enable_rest_api') $field->showIf = 'enable_agent_api=1';
            $field->columnWidth = match ($name) {
                'enable_agent_api', 'enable_rest_api' => 50,
                'enable_interface_cli' => 34,
                default => 33,
            };
            $interfaces->add($field);
        }

        $mailProvider = $this->wire('modules')->get('InputfieldSelect');
        $mailProvider->name = 'mail_module';
        $mailProvider->label = $this->_('WireMail provider');
        $mailProvider->addOption('', $this->_('Site default'));
        foreach ($this->mailProviderOptions() as $name => $label) $mailProvider->addOption($name, $label);
        $mailProvider->value = (string)$data['mail_module'];
        $mailProvider->showIf = 'mail_notifications_enabled=1';
        $mailProvider->columnWidth = 35;
        $interfaces->add($mailProvider);

        foreach (['mail_recipients' => $this->_('Email recipients'), 'mail_from_email' => $this->_('Sender email'), 'mail_from_name' => $this->_('Sender name')] as $name => $label) {
            $field = $this->wire('modules')->get($name === 'mail_from_email' ? 'InputfieldEmail' : 'InputfieldText');
            $field->name = $name;
            $field->label = $label;
            $field->description = $name === 'mail_recipients' ? $this->_('Comma-separated administrator addresses.') : '';
            $field->value = (string)$data[$name];
            $field->showIf = 'mail_notifications_enabled=1';
            $field->columnWidth = $name === 'mail_recipients' ? 65 : 50;
            $interfaces->add($field);
        }

        $token = $this->wire('modules')->get('InputfieldText');
        $token->name = 'telegram_bot_token'; $token->label = $this->_('Telegram bot token');
        $token->description = $this->_('Create a bot with @BotFather. TeleWire module settings are not used; Relay owns this credential and a private runtime override takes precedence.');
        $token->notes = $telegramStatus['credential_source'] === 'runtime'
            ? $this->_('A runtime credential is active; the saved token is not used.')
            : $this->_('Stored in ProcessWire module configuration. Never commit this value to source control.');
        $token->attr('type', 'password'); $token->attr('autocomplete', 'new-password');
        $token->value = (string)$data['telegram_bot_token']; $token->showIf = 'telegram_notifications_enabled=1';
        $token->columnWidth = 50;
        $interfaces->add($token);
        $chatIds = $this->wire('modules')->get('InputfieldTextarea');
        $chatIds->name = 'telegram_chat_ids'; $chatIds->label = $this->_('Telegram administrator chat IDs');
        $chatIds->description = $this->_('One numeric chat ID or @channel username per line.');
        $chatIds->value = (string)$data['telegram_chat_ids']; $chatIds->showIf = 'telegram_notifications_enabled=1';
        $chatIds->columnWidth = 50;
        $interfaces->add($chatIds);

        foreach (['mail_notification_events' => 'mail_notifications_enabled', 'telegram_notification_events' => 'telegram_notifications_enabled'] as $name => $dependency) {
            $events = $this->wire('modules')->get('InputfieldAsmSelect');
            $events->name = $name; $events->label = $this->_('Notify administrators when');
            $events->addOptions(['published' => $this->_('A page is successfully published'), 'scheduled' => $this->_('An action is scheduled'), 'rescheduled' => $this->_('An action is rescheduled'), 'cancelled' => $this->_('An action is cancelled'), 'completed' => $this->_('Any action completes'), 'failed' => $this->_('An action fails')]);
            $events->value = $this->notificationEvents($data[$name]);
            $events->set('sortable', false);
            $events->showIf = $dependency . '=1';
            $events->columnWidth = $name === 'telegram_notification_events' ? 70 : 100;
            $interfaces->add($events);
        }

        $timeout = $this->wire('modules')->get('InputfieldInteger');
        $timeout->name = 'telegram_timeout_seconds'; $timeout->label = $this->_('Telegram delivery timeout');
        $timeout->min = 3; $timeout->max = 30; $timeout->value = max(3, min(30, (int)$data['telegram_timeout_seconds']));
        $timeout->showIf = 'telegram_notifications_enabled=1'; $timeout->columnWidth = 30; $interfaces->add($timeout);

        $telegramPrivacy = $this->wire('modules')->get('InputfieldMarkup');
        $telegramPrivacy->label = $this->_('Telegram payload');
        $telegramPrivacy->value = '<p>' . $this->_('Telegram receives only the publication event, job ID, action, page title, scheduled time, workflow status, and an authenticated admin link. Notes, page content, credentials, and unpublished field values are excluded.') . '</p>';
        $interfaces->add($telegramPrivacy);

        $squadStatus = $this->squadIntegrationStatus();
        $squadIntegration = $this->wire('modules')->get('InputfieldMarkup');
        $squadIntegration->label = $this->_('Squad integration');
        $squadIntegration->value = $squadStatus['provider_ready']
            ? '<p><strong>' . $this->_('Ready') . '</strong> — ' . sprintf($this->_('Squad provider %s is available for staff-requested planning proposals.'), $this->wire('sanitizer')->entities((string)$squadStatus['provider'])) . '</p>'
            : '<p><strong>' . $this->_('Unavailable') . '</strong> — ' . $this->_('Install Squad and configure an active provider key before enabling planning assistance.') . '</p>';
        $interfaces->add($squadIntegration);

        $squadEnabled = $this->wire('modules')->get('InputfieldCheckbox');
        $squadEnabled->name = 'enable_squad_assistance';
        $squadEnabled->label = $this->_('Enable Squad planning proposals');
        $squadEnabled->description = $this->_('Editors can explicitly request a draft publication time and note. Squad never creates, changes, or executes a schedule job.');
        $squadEnabled->checked = !empty($data['enable_squad_assistance']);
        $interfaces->add($squadEnabled);

        $squadModel = $this->wire('modules')->get('InputfieldSelect');
        $squadModel->name = 'squad_provider_model';
        $squadModel->label = $this->_('Squad provider and model');
        $squadModel->description = $this->_('Credentials remain encrypted in Squad. Leave blank to use its active default provider and model.');
        $squadModel->addOption('', $this->_('Use Squad default'));
        foreach ($this->squadModelOptions() as $value => $label) $squadModel->addOption($value, $label);
        $squadModel->value = (string)$data['squad_provider_model'];
        $squadModel->showIf = 'enable_squad_assistance=1';
        $squadModel->columnWidth = 45;
        $interfaces->add($squadModel);

        $selectedSquadFields = array_slice(array_values(array_unique(array_filter(array_map(
            fn($name): string => (string)$this->wire('sanitizer')->fieldName((string)$name),
            $this->configListValues($data['squad_context_fields'])
        )))), 0, 8);
        if (!$selectedSquadFields) $selectedSquadFields = ['title'];
        $squadFieldOptions = [];
        foreach ($this->wire('fields') as $field) {
            $type = $field->type;
            if (!($type instanceof FieldtypeText || $type instanceof FieldtypeInteger || $type instanceof FieldtypeFloat || $type instanceof FieldtypeDatetime || $type instanceof FieldtypeCheckbox)) continue;
            $name = (string)$field->name;
            $label = trim((string)$field->getLabel());
            $squadFieldOptions[$name] = $label !== '' && $label !== $name ? $label . ' (' . $name . ')' : $name;
        }
        foreach ($selectedSquadFields as $name) {
            if (!isset($squadFieldOptions[$name])) $squadFieldOptions[$name] = $name . ' (' . $this->_('unavailable') . ')';
        }
        $squadFields = $this->wire('modules')->get('InputfieldAsmSelect');
        $squadFields->name = 'squad_context_fields';
        $squadFields->label = $this->_('Page fields shared with Squad');
        $squadFields->description = $this->_('Choose up to eight scalar fields. Only these bounded values are included after an editor requests a proposal. Use title alone for the smallest disclosure.');
        $squadFields->notes = $this->_('Drag selected fields into priority order; only fields present on the requested page are sent.');
        $squadFields->addOptions($squadFieldOptions);
        $squadFields->value = $selectedSquadFields;
        $squadFields->set('sortable', true);
        $squadFields->showIf = 'enable_squad_assistance=1';
        $squadFields->columnWidth = 55;
        $interfaces->add($squadFields);

        $squadPrompt = $this->wire('modules')->get('InputfieldTextarea');
        $squadPrompt->name = 'squad_system_prompt';
        $squadPrompt->label = $this->_('Squad planning instructions');
        $squadPrompt->description = $this->_('Permanent safety and planning instructions. Page values are supplied separately as untrusted context.');
        $squadPrompt->rows = 6;
        $squadPrompt->maxlength = 8000;
        $squadPrompt->value = (string)$data['squad_system_prompt'];
        $squadPrompt->showIf = 'enable_squad_assistance=1';
        $squadPrompt->columnWidth = 70;
        $interfaces->add($squadPrompt);

        $squadTimeout = $this->wire('modules')->get('InputfieldInteger');
        $squadTimeout->name = 'squad_timeout_seconds';
        $squadTimeout->label = $this->_('Squad request timeout');
        $squadTimeout->min = 5; $squadTimeout->max = 20;
        $squadTimeout->value = max(5, min(20, (int)$data['squad_timeout_seconds']));
        $squadTimeout->showIf = 'enable_squad_assistance=1';
        $squadTimeout->columnWidth = 30;
        $interfaces->add($squadTimeout);

        return $fields;
    }

    private function renderConfigOverview(array $data): string
    {
        $sanitizer = $this->wire('sanitizer');
        $viewLabels = [
            'month' => $this->_('Month'),
            'week' => $this->_('Week'),
            'quarter' => $this->_('Quarter'),
            'three-day' => $this->_('3 days'),
            'kanban' => $this->_('Kanban'),
            'timeline' => $this->_('Timeline'),
        ];
        $imitationEnabled = !empty($data['imitation_mode']);
        $pending = 0;
        $summaryCount = 0;
        try {
            if ($imitationEnabled) {
                $counts = $this->imitationCounts();
                $pending = (int) ($counts['scheduled'] ?? 0) + (int) ($counts['processing'] ?? 0);
                $summaryCount = array_sum(array_map('intval', $counts));
            } else {
                $counts = $this->store()->counts();
                $pending = (int) ($counts['scheduled'] ?? 0) + (int) ($counts['processing'] ?? 0);
                $summaryCount = $pending;
            }
        } catch (\Throwable $e) {
            // Storage may not exist yet while ProcessWire is preparing installation fields.
        }
        $templates = $this->configListValues($data['allowed_templates']);
        $scope = $templates
            ? sprintf($this->_n('%d template', '%d templates', count($templates)), count($templates))
            : $this->_('All editable templates');
        $fallbackEnabled = !empty($data['lazy_cron_fallback']);
        $states = [
            [$this->_('Editorial timezone'), (string) $data['timezone'], true],
            [$this->_('Default workspace'), ($viewLabels[(string) $data['default_view']] ?? $this->_('Month')) . ' · '
                . ((string) ($data['week_starts_on'] ?? 'monday') === 'sunday' ? $this->_('Sunday first') : $this->_('Monday first')), true],
            [$imitationEnabled ? $this->_('Demo actions') : $this->_('Pending actions'), (string) $summaryCount, $summaryCount > 0],
            [$this->_('Execution'), $imitationEnabled ? $this->_('Imitation sandbox') : ($fallbackEnabled ? $this->_('CLI + LazyCron') : $this->_('CLI worker')), $imitationEnabled || $fallbackEnabled],
        ];
        $sections = [
            'planning' => $this->_('Planning'),
            'presets' => $this->_('Publication times'),
            'scope' => $this->_('Scope'),
            'worker' => $this->_('Worker'),
            'interfaces' => $this->_('Interfaces'),
        ];

        $html = '<div class="RelayConfigIntro" data-imitation="' . ($imitationEnabled ? '1' : '0') . '"><p>'
            . $sanitizer->entities($this->_('Configure editorial defaults, module boundaries, and worker safety. Planning tools remain in the Relay workspace.'))
            . '</p>'
            . ($imitationEnabled ? '<div class="RelayConfigImitation"><i class="fa fa-flask" aria-hidden="true"></i><span><strong>'
                . $sanitizer->entities($this->_('Imitation mode is active')) . '</strong>'
                . $sanitizer->entities($this->_('No real schedule jobs or page states will be changed.')) . '</span></div>' : '')
            . '<div class="RelayConfigStatusGrid">';
        foreach ($states as [$label, $value, $active]) {
            $html .= '<div class="RelayConfigStatus" data-state="' . ($active ? 'active' : 'neutral')
                . '"><span class="RelayConfigStatus__dot" aria-hidden="true"></span><span><small>' . $sanitizer->entities($label)
                . '</small><strong>' . $sanitizer->entities($value) . '</strong></span></div>';
        }
        $html .= '</div><div class="RelayConfigMeta"><span><i class="fa fa-filter" aria-hidden="true"></i> '
            . $sanitizer->entities($scope) . '</span><span><i class="fa fa-clock-o" aria-hidden="true"></i> '
            . sprintf($this->_('Default time %s'), $sanitizer->entities((string) $data['default_time'])) . '</span></div>'
            . '<nav class="RelayConfigNav" aria-label="' . $sanitizer->entities($this->_('Settings sections')) . '">';
        foreach ($sections as $key => $label) {
            $html .= '<a href="#Inputfield_relay_config_' . $key . '">' . $sanitizer->entities($label) . '</a>';
        }
        return $html . '</nav></div>';
    }
}
