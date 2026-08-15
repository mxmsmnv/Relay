<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * Template-linked recurring editorial slot rules and their admin workspace.
 */
trait RelaySchedulingRulesTrait
{
    private const MAX_SCHEDULING_RULES = 50;

    private const MAX_RULE_OCCURRENCES = 730;

    public function ___executeRules(): string
    {
        $this->requireInterfaceAdmin();
        if ($this->wire('input')->requestMethod('POST')) {
            $this->handleSchedulingRuleAction();
        }

        $this->enqueueAssets();
        $this->configureAdminChrome($this->_('Scheduling rules'), $this->_('Rules'));
        $rules = $this->schedulingRules();
        $presets = $this->store()->presets();
        $editId = (string)$this->wire('sanitizer')->name((string)$this->wire('input')->get('edit'));
        $editing = null;
        foreach ($rules as $rule) {
            if ($rule['id'] === $editId) {
                $editing = $rule;
                break;
            }
        }

        $active = count(array_filter($rules, static fn(array $rule): bool => $rule['enabled']));
        $templates = count(array_unique(array_column($rules, 'template')));
        $out = $this->interfaceNav('rules')
            . $this->interfaceIntro(
                $this->_('Template scheduling rules'),
                $this->_('Define recurring editorial slots once, then let matching page templates start with the next suitable date and action.'),
                [
                    $this->_('Rules') => [count($rules) > 0, (string)count($rules), '0'],
                    $this->_('Active') => [$active > 0, (string)$active, '0'],
                    $this->_('Templates') => [$templates > 0, (string)$templates, '0'],
                    $this->_('Quick presets') => [count($presets) > 0, (string)count($presets), '0'],
                ]
            );
        $out .= $this->renderSchedulingRulesWorkspace($rules, $editing, $presets);
        $out .= $this->interfaceSafety(
            $this->_('Editorial boundary'),
            $this->_('Rules propose the next slot; editors still create the job'),
            $this->_('A matching rule fills the page planner with an editable action, date, time, window, and note. It never publishes a page or creates repeated jobs automatically.')
        );
        return $this->interfaceWrap($out);
    }

    /** @return list<array<string,mixed>> */
    private function schedulingRules(mixed $configured = null): array
    {
        $configured ??= $this->scheduling_rules;
        if (is_string($configured)) {
            try {
                $configured = json_decode($configured !== '' ? $configured : '[]', true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }
        if (!is_array($configured)) return [];

        $rules = [];
        $ids = [];
        foreach ($configured as $candidate) {
            if (!is_array($candidate)) continue;
            $rule = $this->normalizeSchedulingRule($candidate);
            if ($rule === null || isset($ids[$rule['id']])) continue;
            $ids[$rule['id']] = true;
            $rules[] = $rule;
            if (count($rules) >= self::MAX_SCHEDULING_RULES) break;
        }
        usort($rules, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
        return $rules;
    }

    /** @return array<string,mixed>|null */
    private function normalizeSchedulingRule(array $input): ?array
    {
        $id = preg_match('/^[a-f0-9]{12}$/D', (string)($input['id'] ?? ''))
            ? (string)$input['id']
            : substr(hash('sha256', random_bytes(16)), 0, 12);
        $name = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)($input['name'] ?? ''))));
        $template = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($input['template'] ?? '')) ?? '';
        if ($name === '' || $template === '') return null;

        $startDate = $this->validRuleDate((string)($input['start_date'] ?? '')) ?: gmdate('Y-m-d', strtotime('+1 day'));
        $startTime = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', (string)($input['start_time'] ?? ''))
            ? (string)$input['start_time']
            : '09:00';
        $frequency = in_array((string)($input['frequency'] ?? ''), ['minute', 'day', 'week', 'month', 'year'], true)
            ? (string)$input['frequency']
            : 'week';
        $weekdays = array_values(array_unique(array_filter(array_map('intval', (array)($input['weekdays'] ?? [])), static fn(int $day): bool => $day >= 1 && $day <= 7)));
        sort($weekdays);
        if ($frequency === 'week' && !$weekdays) {
            $weekdays = [(int)(new \DateTimeImmutable($startDate))->format('N')];
        }
        $ends = in_array((string)($input['ends'] ?? ''), ['never', 'on', 'after'], true)
            ? (string)$input['ends']
            : 'never';
        $until = $this->validRuleDate((string)($input['until_date'] ?? '')) ?: '';
        if ($ends === 'on' && ($until === '' || $until < $startDate)) $until = $startDate;

        return [
            'id' => $id,
            'name' => mb_substr($name, 0, 80),
            'enabled' => !empty($input['enabled']),
            'template' => $template,
            'action' => in_array((string)($input['action'] ?? ''), ['publish', 'unpublish', 'window'], true) ? (string)$input['action'] : 'publish',
            'start_date' => $startDate,
            'start_time' => $startTime,
            'frequency' => $frequency,
            'interval' => max(1, min($this->schedulingRuleIntervalMaximum($frequency), (int)($input['interval'] ?? 1))),
            'weekdays' => $weekdays,
            'ends' => $ends,
            'until_date' => $until,
            'occurrences' => max(1, min(self::MAX_RULE_OCCURRENCES, (int)($input['occurrences'] ?? 12))),
            'window_minutes' => max(60, min(525600, (int)($input['window_minutes'] ?? 10080))),
            'note' => mb_substr(trim(strip_tags((string)($input['note'] ?? ''))), 0, 500),
        ];
    }

    private function validRuleDate(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function schedulingRuleIntervalMaximum(string $frequency): int
    {
        return $frequency === 'minute' ? 10080 : 99;
    }

    private function handleSchedulingRuleAction(): never
    {
        $this->wire('session')->CSRF->validate();
        $presetAction = (string)$this->wire('input')->post('preset_action');
        if ($presetAction !== '') {
            if ($presetAction === 'delete') {
                $this->store()->deletePreset((int)$this->wire('input')->post('preset_id'));
                $this->message($this->_('Quick preset deleted.'));
                $this->wire('session')->redirect($this->processUrl() . 'rules/');
            }
            if ($presetAction !== 'save') throw new WireException($this->_('Unsupported quick preset action.'));
            $weekdays = $this->wire('input')->post('weekdays');
            $preset = $this->normalizeSchedulingPreset([
                'name'=>(string)$this->wire('input')->post('rule_name'),
                'template'=>(string)$this->wire('input')->post('template'),
                'action'=>(string)$this->wire('input')->post('action'),
                'start_date'=>(string)$this->wire('input')->post('start_date'),
                'start_time'=>(string)$this->wire('input')->post('start_time'),
                'frequency'=>(string)$this->wire('input')->post('frequency'),
                'interval'=>(int)$this->wire('input')->post('interval'),
                'weekdays'=>is_array($weekdays) ? $weekdays : [],
                'ends'=>(string)$this->wire('input')->post('ends'),
                'until_date'=>(string)$this->wire('input')->post('until_date'),
                'occurrences'=>(int)$this->wire('input')->post('occurrences'),
                'window_minutes'=>max(1, (int)$this->wire('input')->post('window_hours')) * 60,
                'note'=>(string)$this->wire('input')->post('note'),
            ]);
            if ($preset === null) throw new WireException($this->_('Give the quick preset a name.'));
            $templateOptions = $this->calendarTemplateOptions();
            if ($preset['template'] !== '' && !isset($templateOptions[$preset['template']])) throw new WireException($this->_('Choose an available page template.'));
            $this->store()->savePreset($preset, (int)$this->wire('user')->id);
            $this->message($this->_('Quick preset saved. A preset with the same name is updated.'));
            $this->wire('session')->redirect($this->processUrl() . 'rules/');
        }
        $action = (string)$this->wire('input')->post('rule_action');
        $rules = $this->schedulingRules();
        $id = (string)$this->wire('sanitizer')->name((string)$this->wire('input')->post('rule_id'));

        if ($action === 'delete') {
            $rules = array_values(array_filter($rules, static fn(array $rule): bool => $rule['id'] !== $id));
            $this->saveSchedulingRules($rules);
            $this->message($this->_('Scheduling rule deleted.'));
            $this->wire('session')->redirect($this->processUrl() . 'rules/');
        }
        if ($action !== 'save') throw new WireException($this->_('Unsupported scheduling rule action.'));

        $weekdays = $this->wire('input')->post('weekdays');
        $rule = $this->normalizeSchedulingRule([
            'id' => $id,
            'name' => (string)$this->wire('input')->post('rule_name'),
            'enabled' => $this->wire('input')->post('enabled') !== null,
            'template' => (string)$this->wire('input')->post('template'),
            'action' => (string)$this->wire('input')->post('action'),
            'start_date' => (string)$this->wire('input')->post('start_date'),
            'start_time' => (string)$this->wire('input')->post('start_time'),
            'frequency' => (string)$this->wire('input')->post('frequency'),
            'interval' => (int)$this->wire('input')->post('interval'),
            'weekdays' => is_array($weekdays) ? $weekdays : [],
            'ends' => (string)$this->wire('input')->post('ends'),
            'until_date' => (string)$this->wire('input')->post('until_date'),
            'occurrences' => (int)$this->wire('input')->post('occurrences'),
            'window_minutes' => max(1, (int)$this->wire('input')->post('window_hours')) * 60,
            'note' => (string)$this->wire('input')->post('note'),
        ]);
        if ($rule === null) throw new WireException($this->_('Rule name and page template are required.'));
        $templateOptions = $this->calendarTemplateOptions();
        if (!isset($templateOptions[$rule['template']])) throw new WireException($this->_('Choose an available page template.'));

        $updated = false;
        foreach ($rules as $index => $existing) {
            if ($existing['id'] !== $rule['id']) continue;
            $rules[$index] = $rule;
            $updated = true;
            break;
        }
        if (!$updated) {
            if (count($rules) >= self::MAX_SCHEDULING_RULES) throw new WireException($this->_('Relay supports up to 50 scheduling rules.'));
            $rules[] = $rule;
        }
        $this->saveSchedulingRules($rules);
        $this->message($updated ? $this->_('Scheduling rule updated.') : $this->_('Scheduling rule created.'));
        $this->wire('session')->redirect($this->processUrl() . 'rules/');
    }

    /** @return array<string,mixed>|null */
    private function normalizeSchedulingPreset(array $input): ?array
    {
        $name = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)($input['name'] ?? ''))));
        if ($name === '') return null;
        $template = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($input['template'] ?? '')) ?? '';
        $frequency = in_array((string)($input['frequency'] ?? ''), ['minute','day','week','month','year'], true) ? (string)$input['frequency'] : 'week';
        $weekdays = array_values(array_unique(array_filter(array_map('intval', (array)($input['weekdays'] ?? [])), static fn(int $day): bool => $day >= 1 && $day <= 7)));
        sort($weekdays);
        if ($frequency === 'week' && !$weekdays) $weekdays = [1];
        $startDate = $this->validRuleDate((string)($input['start_date'] ?? '')) ?: gmdate('Y-m-d', strtotime('+1 day'));
        $untilDate = $this->validRuleDate((string)($input['until_date'] ?? ''));
        $untilDays = isset($input['until_days']) ? (int)$input['until_days'] : 90;
        if ($untilDate !== '' && $untilDate >= $startDate) {
            $untilDays = (int)(new \DateTimeImmutable($startDate))->diff(new \DateTimeImmutable($untilDate))->format('%a');
        }
        return [
            'name'=>mb_substr($name, 0, 80),
            'template'=>$template,
            'action'=>in_array((string)($input['action'] ?? ''), ['publish','unpublish','window'], true) ? (string)$input['action'] : 'publish',
            'start_time'=>preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', (string)($input['start_time'] ?? '')) ? (string)$input['start_time'] : '09:00',
            'frequency'=>$frequency,
            'interval'=>max(1, min($this->schedulingRuleIntervalMaximum($frequency), (int)($input['interval'] ?? 1))),
            'weekdays'=>$weekdays,
            'ends'=>in_array((string)($input['ends'] ?? ''), ['never','on','after'], true) ? (string)$input['ends'] : 'never',
            'until_days'=>max(0, min(3650, $untilDays)),
            'occurrences'=>max(1, min(self::MAX_RULE_OCCURRENCES, (int)($input['occurrences'] ?? 12))),
            'window_minutes'=>max(60, min(525600, (int)($input['window_minutes'] ?? 10080))),
            'note'=>mb_substr(trim(strip_tags((string)($input['note'] ?? ''))), 0, 500),
        ];
    }

    /** @param list<array<string,mixed>> $rules */
    private function saveSchedulingRules(array $rules): void
    {
        $config = array_merge(self::getDefaultConfig(), (array)$this->wire('modules')->getConfig('Relay'));
        $encoded = json_encode(array_values($rules), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $config['scheduling_rules'] = $encoded;
        $this->wire('modules')->saveConfig('Relay', $config);
        $this->set('scheduling_rules', $encoded);
    }

    /** @return list<array<string,mixed>> */
    private function matchingSchedulingRules(Page $page, ?\DateTimeImmutable $now = null): array
    {
        $timezone = new \DateTimeZone($this->configuredTimezone());
        $now = ($now ?: new \DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $usedSlots = $this->schedulingRuleUsedSlots($page, $now);
        $matches = [];
        foreach ($this->schedulingRules() as $rule) {
            if (!$rule['enabled'] || $rule['template'] !== (string)$page->template->name) continue;
            $cursor = $now;
            $next = null;
            for ($attempt = 0; $attempt < self::MAX_RULE_OCCURRENCES; $attempt++) {
                $candidate = $this->nextSchedulingRuleOccurrence($rule, $cursor, $timezone);
                if (!$candidate) break;
                $slotKey = $candidate->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i');
                if (!isset($usedSlots[$slotKey])) {
                    $next = $candidate;
                    break;
                }
                $cursor = $candidate;
            }
            if (!$next) continue;
            $rule['_next'] = $next;
            $rule['_summary'] = $this->schedulingRuleSummary($rule);
            $matches[] = $rule;
        }
        usort($matches, static fn(array $left, array $right): int => [$left['_next']->getTimestamp(), $left['name']] <=> [$right['_next']->getTimestamp(), $right['name']]);
        return $matches;
    }

    /** @return array<string,true> */
    private function schedulingRuleUsedSlots(Page $page, \DateTimeImmutable $now): array
    {
        $fromUtc = $now->setTimezone(new \DateTimeZone('UTC'));
        $toUtc = $now->modify('+' . max(1, min(20, (int)$this->max_future_years)) . ' years')->setTimezone(new \DateTimeZone('UTC'));
        $jobs = [];
        foreach (['scheduled', 'processing'] as $status) {
            $matches = $this->isImitationMode()
                ? $this->imitationBetween($fromUtc, $toUtc, 5000, null, $status, null, (string)$page->template->name)
                : $this->store()->between($fromUtc, $toUtc, 5000, null, $status, null, (int)$page->template->id);
            array_push($jobs, ...$matches);
        }
        $slots = [];
        foreach ($jobs as $job) {
            $slots[substr((string)$job['scheduled_at'], 0, 16)] = true;
        }
        return $slots;
    }

    private function nextSchedulingRuleOccurrence(array $rule, \DateTimeImmutable $now, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $rule = $this->normalizeSchedulingRule($rule);
        if ($rule === null || !$rule['enabled']) return null;
        $startDay = new \DateTimeImmutable($rule['start_date'] . ' 00:00:00', $timezone);
        $limitDay = $now->setTime(23, 59, 59)->modify('+' . max(1, min(20, (int)$this->max_future_years)) . ' years');
        $untilDay = $rule['ends'] === 'on' && $rule['until_date'] !== ''
            ? new \DateTimeImmutable($rule['until_date'] . ' 23:59:59', $timezone)
            : $limitDay;
        if ($untilDay < $limitDay) $limitDay = $untilDay;

        if ($rule['frequency'] === 'minute') {
            $start = new \DateTimeImmutable($rule['start_date'] . ' ' . $rule['start_time'] . ':00', $timezone);
            $step = $rule['interval'] * 60;
            $index = $now < $start ? 0 : intdiv(max(0, $now->getTimestamp() - $start->getTimestamp()), $step) + 1;
            if ($rule['ends'] === 'after' && $index >= $rule['occurrences']) return null;
            $candidate = (new \DateTimeImmutable('@' . ($start->getTimestamp() + ($index * $step))))->setTimezone($timezone);
            return $candidate <= $limitDay ? $candidate : null;
        }

        $occurrence = 0;

        for ($day = $startDay; $day <= $limitDay; $day = $day->modify('+1 day')) {
            if (!$this->schedulingRuleMatchesDay($rule, $startDay, $day)) continue;
            $occurrence++;
            if ($rule['ends'] === 'after' && $occurrence > $rule['occurrences']) return null;
            $candidate = new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $rule['start_time'] . ':00', $timezone);
            if ($candidate > $now) return $candidate;
        }
        return null;
    }

    private function schedulingRuleMatchesDay(array $rule, \DateTimeImmutable $start, \DateTimeImmutable $day): bool
    {
        $days = (int)$start->diff($day)->format('%a');
        return match ($rule['frequency']) {
            'day' => $days % $rule['interval'] === 0,
            'week' => intdiv($days, 7) % $rule['interval'] === 0 && in_array((int)$day->format('N'), $rule['weekdays'], true),
            'month' => (((int)$day->format('Y') - (int)$start->format('Y')) * 12 + (int)$day->format('n') - (int)$start->format('n')) % $rule['interval'] === 0
                && $day->format('j') === $start->format('j'),
            'year' => ((int)$day->format('Y') - (int)$start->format('Y')) % $rule['interval'] === 0
                && $day->format('m-d') === $start->format('m-d'),
            default => false,
        };
    }

    /** @return array{rules:list<array<string,mixed>>,selected:?array<string,mixed>,action:string,start:\DateTimeImmutable,until:\DateTimeImmutable,note:string} */
    private function pageSchedulingRuleContext(Page $page, \DateTimeImmutable $fallback): array
    {
        $rules = $this->matchingSchedulingRules($page);
        $selected = $rules[0] ?? null;
        if (!$selected) {
            return ['rules' => [], 'selected' => null, 'action' => 'publish', 'start' => $fallback, 'until' => $fallback->modify('+7 days'), 'note' => ''];
        }
        $start = $selected['_next'];
        return [
            'rules' => $rules,
            'selected' => $selected,
            'action' => $selected['action'],
            'start' => $start,
            'until' => $start->modify('+' . $selected['window_minutes'] . ' minutes'),
            'note' => $selected['note'],
        ];
    }

    private function renderPageSchedulingRulePicker(array $context, Page $page): string
    {
        if (!$context['rules']) return '';
        $sanitizer = $this->wire('sanitizer');
        $templateLabel = trim((string)$page->template->getLabel()) ?: (string)$page->template->name;
        $options = '<option value="">' . $this->_('Custom schedule') . '</option>';
        foreach ($context['rules'] as $index => $rule) {
            $start = $rule['_next'];
            $until = $start->modify('+' . $rule['window_minutes'] . ' minutes');
            $options .= '<option value="' . $sanitizer->entities($rule['id']) . '"'
                . ($index === 0 ? ' selected' : '')
                . ' data-action="' . $sanitizer->entities($rule['action']) . '"'
                . ' data-start="' . $sanitizer->entities($start->format('Y-m-d\TH:i')) . '"'
                . ' data-until="' . $sanitizer->entities($until->format('Y-m-d\TH:i')) . '"'
                . ' data-note="' . $sanitizer->entities($rule['note']) . '"'
                . ' data-summary="' . $sanitizer->entities($rule['_summary']) . '">'
                . $sanitizer->entities($rule['name']) . ' · ' . $sanitizer->entities($start->format('M j, H:i')) . '</option>';
        }
        $selected = $context['selected'];
        return '<aside class="RelayComposer__rule" data-relay-rule-context><span><i class="fa fa-repeat" aria-hidden="true"></i></span><label>'
            . $this->_('Scheduling rule') . '<select data-relay-rule-select>' . $options . '</select></label><div><small>'
            . sprintf($this->_('Automatically matched to template %s'), '<code>' . $sanitizer->entities($templateLabel) . '</code>')
            . '</small><strong data-relay-rule-name>' . $sanitizer->entities($selected['name']) . '</strong><p data-relay-rule-summary>'
            . $sanitizer->entities($selected['_summary']) . '</p></div></aside>';
    }

    private function schedulingRuleSummary(array $rule): string
    {
        $unit = $this->schedulingRuleUnit((string)$rule['frequency'], (int)$rule['interval']);
        $summary = sprintf(
            $rule['frequency'] === 'minute' ? $this->_('Every %1$d %2$s from %3$s') : $this->_('Every %1$d %2$s at %3$s'),
            (int)$rule['interval'],
            $unit,
            (string)$rule['start_time']
        );
        if ($rule['frequency'] === 'week' && $rule['weekdays']) {
            $labels = [1 => $this->_('Mon'), 2 => $this->_('Tue'), 3 => $this->_('Wed'), 4 => $this->_('Thu'), 5 => $this->_('Fri'), 6 => $this->_('Sat'), 7 => $this->_('Sun')];
            $summary .= ' · ' . implode(', ', array_map(static fn(int $day): string => $labels[$day], $rule['weekdays']));
        }
        return $summary;
    }

    private function schedulingRuleUnit(string $frequency, int $count): string
    {
        return match ($frequency) {
            'minute' => $this->_n('minute', 'minutes', $count),
            'day' => $this->_n('day', 'days', $count),
            'week' => $this->_n('week', 'weeks', $count),
            'month' => $this->_n('month', 'months', $count),
            'year' => $this->_n('year', 'years', $count),
            default => $this->_n('week', 'weeks', $count),
        };
    }

    /** @param list<array<string,mixed>> $rules */
    private function renderSchedulingRulesWorkspace(array $rules, ?array $editing, array $presets): string
    {
        $sanitizer = $this->wire('sanitizer');
        $token = $this->wire('session')->CSRF->getToken();
        $csrf = '<input type="hidden" name="' . $sanitizer->entities((string)$token['name']) . '" value="' . $sanitizer->entities((string)$token['value']) . '">';
        $base = $this->processUrl() . 'rules/';
        $templateOptions = $this->calendarTemplateOptions();
        $default = $editing ?: [
            'id' => '', 'name' => '', 'enabled' => true, 'template' => '', 'action' => 'publish',
            'start_date' => gmdate('Y-m-d', strtotime('+1 day')), 'start_time' => (string)$this->default_time,
            'frequency' => 'week', 'interval' => 1, 'weekdays' => [(int)gmdate('N', strtotime('+1 day'))],
            'ends' => 'never', 'until_date' => gmdate('Y-m-d', strtotime('+3 months')), 'occurrences' => 12,
            'window_minutes' => 10080, 'note' => '',
        ];

        $out = $this->renderQuickPresets($presets, $csrf)
            . '<div class="RelayRulesLayout"><section class="RelayRulesList"><header><div><small>' . $this->_('Rule library') . '</small><h2>'
            . $this->_('Recurring editorial slots') . '</h2><p>' . $this->_('Rules are matched by page template and ordered by their next available occurrence.')
            . '</p></div><a class="uk-button uk-button-default" href="' . $sanitizer->entities($base) . '"><i class="fa fa-plus" aria-hidden="true"></i> '
            . $this->_('New rule') . '</a></header>';
        if (!$rules) {
            $out .= '<div class="RelayRulesEmpty"><i class="fa fa-repeat" aria-hidden="true"></i><h3>' . $this->_('No scheduling rules yet')
                . '</h3><p>' . $this->_('Create a rule to give one page template a reliable next publication slot.') . '</p></div>';
        } else {
            $out .= '<div class="RelayRuleCards">';
            foreach ($rules as $rule) {
                $templateLabel = $templateOptions[$rule['template']] ?? $rule['template'];
                $next = $rule['enabled'] ? $this->nextSchedulingRuleOccurrence($rule, new \DateTimeImmutable('now', new \DateTimeZone($this->configuredTimezone())), new \DateTimeZone($this->configuredTimezone())) : null;
                $out .= '<article class="RelayRuleCard" data-state="' . ($rule['enabled'] ? 'enabled' : 'disabled') . '"><header><span><i class="fa fa-repeat" aria-hidden="true"></i></span><div><small>'
                    . $sanitizer->entities($templateLabel) . '</small><h3>' . $sanitizer->entities($rule['name']) . '</h3></div><span class="RelayRuleCard__state">'
                    . ($rule['enabled'] ? $this->_('Active') : $this->_('Paused')) . '</span></header><p>' . $sanitizer->entities($this->schedulingRuleSummary($rule))
                    . '</p><dl><div><dt>' . $this->_('Action') . '</dt><dd>' . $sanitizer->entities($this->actionLabel($rule['action'] === 'window' ? 'publish' : $rule['action']))
                    . ($rule['action'] === 'window' ? ' + ' . $this->_('Unpublish') : '') . '</dd></div><div><dt>' . $this->_('Next slot') . '</dt><dd>'
                    . ($next ? $sanitizer->entities($this->formatCalendarDate($next, 'M j, Y') . ' · ' . $next->format('H:i')) : $this->_('No future slot'))
                    . '</dd></div></dl><footer><a class="uk-button uk-button-default" href="' . $sanitizer->entities($base . '?edit=' . $rule['id'])
                    . '"><i class="fa fa-pencil" aria-hidden="true"></i> ' . $this->_('Edit') . '</a><form method="post" data-relay-rule-delete data-confirm="'
                    . $sanitizer->entities($this->_('Delete this scheduling rule?')) . '">' . $csrf . '<input type="hidden" name="rule_action" value="delete"><input type="hidden" name="rule_id" value="'
                    . $sanitizer->entities($rule['id']) . '"><button class="uk-button uk-button-danger" type="submit"><i class="fa fa-trash" aria-hidden="true"></i> '
                    . $this->_('Delete') . '</button></form></footer></article>';
            }
            $out .= '</div>';
        }
        $out .= '</section>' . $this->renderSchedulingRuleForm($default, $templateOptions, $csrf) . '</div>';
        return $out;
    }

    /** @param list<array<string,mixed>> $presets */
    private function renderQuickPresets(array $presets, string $csrf): string
    {
        $sanitizer = $this->wire('sanitizer');
        $builtInLabels = [
            'Every 15 minutes'=>$this->_('Every 15 minutes'),
            'Every 30 minutes'=>$this->_('Every 30 minutes'),
            'Every 69 minutes'=>$this->_('Every 69 minutes'),
            'Every 4 days'=>$this->_('Every 4 days'),
            'Every week'=>$this->_('Every week'),
            'Every month'=>$this->_('Every month'),
        ];
        $out = '<section class="RelayQuickPresets"><header><div><small>' . $this->_('Quick presets') . '</small><h2>' . $this->_('Set recurrence in one click')
            . '</h2><p>' . $this->_('Choose a common interval, or enter any custom number and unit in the rule editor.') . '</p></div><span>'
            . sprintf($this->_n('%d preset', '%d presets', count($presets)), count($presets)) . '</span></header>';
        if (!$presets) return $out . '<p class="RelayQuickPresets__empty">' . $this->_('Save the current rule form as your first quick preset.') . '</p></section>';
        $out .= '<div class="RelayQuickPresetGrid">';
        foreach ($presets as $preset) {
            $payload = [
                'frequency'=>(string)$preset['frequency'],
                'interval'=>(int)$preset['interval'],
            ];
            $label = $builtInLabels[(string)$preset['name']] ?? (string)$preset['name'];
            $out .= '<article class="RelayQuickPreset"><button type="button" data-relay-rule-preset data-preset="'
                . $sanitizer->entities(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                . '" aria-pressed="false"><span><i class="fa fa-bolt" aria-hidden="true"></i></span><span><strong>' . $sanitizer->entities($label)
                . '</strong><small>' . (int)$preset['interval'] . ' · ' . $sanitizer->entities($this->schedulingRuleUnit((string)$preset['frequency'], (int)$preset['interval']))
                . '</small></span><span class="RelayQuickPreset__apply">' . $this->_('Apply') . '</span></button><form method="post" data-relay-preset-delete data-confirm="'
                . $sanitizer->entities($this->_('Delete this quick preset?')) . '">' . $csrf . '<input type="hidden" name="preset_action" value="delete"><input type="hidden" name="preset_id" value="'
                . (int)$preset['id'] . '"><button type="submit" aria-label="' . $sanitizer->entities(sprintf($this->_('Delete preset %s'), $label))
                . '" title="' . $sanitizer->entities($this->_('Delete preset')) . '"><i class="fa fa-times" aria-hidden="true"></i></button></form></article>';
        }
        return $out . '</div></section>';
    }

    /** @param array<string,string> $templateOptions */
    private function renderSchedulingRuleForm(array $rule, array $templateOptions, string $csrf): string
    {
        $sanitizer = $this->wire('sanitizer');
        $option = static fn(string $value, string $current): string => $value === $current ? ' selected' : '';
        $templates = '<option value="">' . $this->_('Choose template') . '</option>';
        foreach ($templateOptions as $value => $label) {
            $value = (string)$value;
            $templates .= '<option value="' . $sanitizer->entities($value) . '"' . $option($value, (string)$rule['template']) . '>' . $sanitizer->entities($label) . '</option>';
        }
        $weekdayLabels = [1 => $this->_('Mon'), 2 => $this->_('Tue'), 3 => $this->_('Wed'), 4 => $this->_('Thu'), 5 => $this->_('Fri'), 6 => $this->_('Sat'), 7 => $this->_('Sun')];
        $weekdays = '';
        foreach ($weekdayLabels as $day => $label) {
            $weekdays .= '<label><input class="uk-checkbox" type="checkbox" name="weekdays[]" value="' . $day . '"'
                . (in_array($day, (array)$rule['weekdays'], true) ? ' checked' : '') . '><span>' . $label . '</span></label>';
        }
        $editing = (string)$rule['id'] !== '';
        return '<section class="RelayRuleEditor"><header><span><i class="fa fa-' . ($editing ? 'pencil' : 'plus') . '" aria-hidden="true"></i></span><div><small>'
            . ($editing ? $this->_('Edit rule') : $this->_('New rule')) . '</small><h2>' . ($editing ? $sanitizer->entities((string)$rule['name']) : $this->_('Create scheduling rule'))
            . '</h2><p>' . $this->_('Choose the template, slot cadence, default action, and when the rule should stop offering dates.') . '</p></div></header><form method="post" class="RelayRuleForm" data-relay-rule-form>'
            . $csrf . '<input type="hidden" name="rule_action" value="save"><input type="hidden" name="rule_id" value="' . $sanitizer->entities((string)$rule['id']) . '">'
            . '<label class="RelayRuleForm__wide"><span>' . $this->_('Rule name') . '</span><input class="uk-input" type="text" name="rule_name" maxlength="80" value="' . $sanitizer->entities((string)$rule['name']) . '" required></label>'
            . '<label><span>' . $this->_('Page template') . '</span><select class="uk-select" name="template" required>' . $templates . '</select></label>'
            . '<label><span>' . $this->_('Default action') . '</span><select class="uk-select" name="action" data-relay-rule-action><option value="publish"' . $option('publish', (string)$rule['action']) . '>' . $this->_('Publish') . '</option><option value="unpublish"' . $option('unpublish', (string)$rule['action']) . '>' . $this->_('Unpublish') . '</option><option value="window"' . $option('window', (string)$rule['action']) . '>' . $this->_('Publication window') . '</option></select></label>'
            . '<label><span>' . $this->_('Starts on') . '</span><input class="uk-input" type="date" name="start_date" value="' . $sanitizer->entities((string)$rule['start_date']) . '" required></label>'
            . '<label><span>' . $this->_('Slot time') . '</span><input class="uk-input" type="time" name="start_time" value="' . $sanitizer->entities((string)$rule['start_time']) . '" required></label>'
            . '<div class="RelayRuleForm__repeat RelayRuleForm__wide"><span>' . $this->_('Repeats every') . '</span><div><input class="uk-input" type="number" name="interval" min="1" max="' . $this->schedulingRuleIntervalMaximum((string)$rule['frequency']) . '" value="' . (int)$rule['interval'] . '" required><select class="uk-select" name="frequency" data-relay-rule-frequency><option value="minute"' . $option('minute', (string)$rule['frequency']) . '>' . $this->_('Minutes') . '</option><option value="day"' . $option('day', (string)$rule['frequency']) . '>' . $this->_('Day') . '</option><option value="week"' . $option('week', (string)$rule['frequency']) . '>' . $this->_('Week') . '</option><option value="month"' . $option('month', (string)$rule['frequency']) . '>' . $this->_('Month') . '</option><option value="year"' . $option('year', (string)$rule['frequency']) . '>' . $this->_('Year') . '</option></select></div></div>'
            . '<fieldset class="RelayRuleWeekdays RelayRuleForm__wide" data-relay-rule-weekdays><legend>' . $this->_('Repeat on') . '</legend><div>' . $weekdays . '</div></fieldset>'
            . '<label><span>' . $this->_('Ends') . '</span><select class="uk-select" name="ends" data-relay-rule-ends><option value="never"' . $option('never', (string)$rule['ends']) . '>' . $this->_('Never') . '</option><option value="on"' . $option('on', (string)$rule['ends']) . '>' . $this->_('On date') . '</option><option value="after"' . $option('after', (string)$rule['ends']) . '>' . $this->_('After occurrences') . '</option></select></label>'
            . '<label data-relay-rule-until><span>' . $this->_('End date') . '</span><input class="uk-input" type="date" name="until_date" value="' . $sanitizer->entities((string)$rule['until_date']) . '"></label>'
            . '<label data-relay-rule-count><span>' . $this->_('Occurrences') . '</span><input class="uk-input" type="number" name="occurrences" min="1" max="' . self::MAX_RULE_OCCURRENCES . '" value="' . (int)$rule['occurrences'] . '"></label>'
            . '<label data-relay-rule-window><span>' . $this->_('Window duration (hours)') . '</span><input class="uk-input" type="number" name="window_hours" min="1" max="8760" value="' . max(1, (int)round((int)$rule['window_minutes'] / 60)) . '"></label>'
            . '<label class="RelayRuleForm__wide"><span>' . $this->_('Default internal note') . '</span><input class="uk-input" type="text" name="note" maxlength="500" value="' . $sanitizer->entities((string)$rule['note']) . '"></label>'
            . '<label class="RelayRuleToggle RelayRuleForm__wide"><input class="uk-checkbox" type="checkbox" name="enabled" value="1"' . (!empty($rule['enabled']) ? ' checked' : '') . '><span><strong>' . $this->_('Rule enabled') . '</strong><small>' . $this->_('Matching page templates receive this rule in the publication planner.') . '</small></span></label>'
            . '<div class="RelayRuleForm__actions RelayRuleForm__wide"><button class="uk-button uk-button-primary" type="submit"><i class="fa fa-check" aria-hidden="true"></i> ' . ($editing ? $this->_('Save rule') : $this->_('Create rule')) . '</button><button class="uk-button uk-button-default" type="submit" name="preset_action" value="save"><i class="fa fa-bolt" aria-hidden="true"></i> ' . $this->_('Save cadence as preset') . '</button>'
            . ($editing ? '<a class="uk-button uk-button-default" href="' . $sanitizer->entities($this->processUrl() . 'rules/') . '">' . $this->_('Cancel editing') . '</a>' : '') . '</div></form></section>';
    }
}
