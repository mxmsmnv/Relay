<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * Calendar ranges, view models and rendering for every planning view.
 */
trait RelayCalendarUiTrait
{
    private function renderCalendar(): string
    {
        $timezone = $this->configuredTimezone();
        $sanitizer = $this->wire('sanitizer');
        $views = [
            'month' => $this->_('Month'),
            'week' => $this->_('Week'),
            'quarter' => $this->_('Quarter'),
            'three-day' => $this->_('3 days'),
            'kanban' => $this->_('Kanban'),
            'timeline' => $this->_('Timeline'),
        ];
        $viewDescriptions = [
            'month' => $this->_('Scan the whole month, then select a date to focus on its three-day window.'),
            'week' => $this->_('Review seven days of publishing work in a focused editorial agenda.'),
            'quarter' => $this->_('Compare three months at a glance, then select any date to inspect it in detail.'),
            'three-day' => $this->_('Focus on the immediate publishing window without losing action status and ownership.'),
            'kanban' => $this->_('Review every action in the selected quarter, grouped by its current workflow status.'),
            'timeline' => $this->_('Compare pages across a two-week planning board and spot competing publication changes.'),
        ];
        $view = (string) $sanitizer->option((string) $this->wire('input')->get('view'), array_keys($views));
        if ($view === '') {
            $view = in_array((string) $this->default_view, array_keys($views), true) ? (string) $this->default_view : 'month';
        }

        $statusFilter = (string) $sanitizer->option(
            (string) $this->wire('input')->get('status'),
            ['scheduled', 'processing', 'completed', 'failed', 'cancelled', 'superseded']
        );
        $actionFilter = (string) $sanitizer->option((string) $this->wire('input')->get('action'), ['publish', 'unpublish']);
        $templateControlsEnabled = (int) $this->enable_template_controls === 1;
        $templateOptions = $templateControlsEnabled ? $this->calendarTemplateOptions() : [];
        $filterTemplateOptions = [];
        $templateFilter = $templateControlsEnabled ? (string) $sanitizer->name((string) $this->wire('input')->get('template')) : '';
        if ($templateFilter !== '' && !isset($templateOptions[$templateFilter])) {
            $templateFilter = '';
        }
        $templateFilterId = null;
        if ($templateFilter !== '') {
            $template = $this->wire('templates')->get($templateFilter);
            $templateFilterId = $template && $template->id ? (int) $template->id : null;
            if ($templateFilterId === null) $templateFilter = '';
        }
        $sortOrder = $templateControlsEnabled
            ? (string) $sanitizer->option((string) $this->wire('input')->get('sort'), ['date', 'template'])
            : 'date';
        if ($sortOrder === '') $sortOrder = 'date';
        $this->calendarFilters = array_filter([
            'status' => $statusFilter,
            'action' => $actionFilter,
            'template' => $templateFilter,
            'sort' => $sortOrder === 'template' ? 'template' : '',
        ]);

        $anchor = new \DateTimeImmutable('today', new \DateTimeZone($timezone));
        $dateInput = (string) $this->wire('input')->get('date');
        $monthInput = (string) $this->wire('input')->get('month');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateInput)) {
            $candidate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput, new \DateTimeZone($timezone));
            if ($candidate && $candidate->format('Y-m-d') === $dateInput) {
                $anchor = $candidate;
            }
        } elseif (preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
            $candidate = \DateTimeImmutable::createFromFormat('!Y-m-d', $monthInput . '-01', new \DateTimeZone($timezone));
            if ($candidate && $candidate->format('Y-m') === $monthInput) {
                $anchor = $candidate;
            }
        }

        $range = $this->calendarRange($view, $anchor);
        $pageFilterId = max(0, (int) $this->wire('input')->get('page_id'));
        $rangeStartUtc = $range['start']->setTimezone(new \DateTimeZone('UTC'));
        $rangeEndUtc = $range['end']->setTimezone(new \DateTimeZone('UTC'));
        $jobs = $this->isImitationMode()
            ? $this->imitationBetween(
                $rangeStartUtc,
                $rangeEndUtc,
                501,
                $pageFilterId > 0 ? $pageFilterId : null,
                $statusFilter !== '' ? $statusFilter : null,
                $actionFilter !== '' ? $actionFilter : null,
                $templateFilter !== '' ? $templateFilter : null
            )
            : $this->store()->between(
                $rangeStartUtc,
                $rangeEndUtc,
                501,
                $pageFilterId > 0 ? $pageFilterId : null,
                $statusFilter !== '' ? $statusFilter : null,
                $actionFilter !== '' ? $actionFilter : null,
                $templateFilterId
            );
        if ($templateControlsEnabled) {
            $availableTemplateNames = [];
            if ($this->isImitationMode()) {
                $candidateJobs = $this->imitationBetween(
                    $rangeStartUtc,
                    $rangeEndUtc,
                    5000,
                    $pageFilterId > 0 ? $pageFilterId : null,
                    $statusFilter !== '' ? $statusFilter : null,
                    $actionFilter !== '' ? $actionFilter : null
                );
                foreach ($candidateJobs as $candidateJob) {
                    $candidatePage = $this->wire('pages')->get((int)$candidateJob['page_id']);
                    if ($candidatePage->id) $availableTemplateNames[(string)$candidatePage->template->name] = true;
                    if ($candidatePage->id) $this->wire('pages')->uncache($candidatePage);
                }
            } else {
                foreach ($this->store()->templateIdsBetween(
                    $rangeStartUtc,
                    $rangeEndUtc,
                    $pageFilterId > 0 ? $pageFilterId : null,
                    $statusFilter !== '' ? $statusFilter : null,
                    $actionFilter !== '' ? $actionFilter : null
                ) as $availableTemplateId) {
                    $availableTemplate = $this->wire('templates')->get($availableTemplateId);
                    if ($availableTemplate && $availableTemplate->id) $availableTemplateNames[(string)$availableTemplate->name] = true;
                }
            }
            $filterTemplateOptions = $this->calendarTemplateFilterOptions(array_intersect_key($templateOptions, $availableTemplateNames));
            if ($templateFilter !== '' && isset($templateOptions[$templateFilter])) {
                $filterTemplateOptions[$templateFilter] = $templateOptions[$templateFilter];
                $filterTemplateOptions = $this->calendarTemplateFilterOptions($filterTemplateOptions);
            }
        }
        $truncated = count($jobs) > 500;
        if ($truncated) {
            $jobs = array_slice($jobs, 0, 500);
        }
        $jobs = $this->hydrateCalendarJobs($jobs, $timezone);
        if ($templateFilter !== '') {
            $jobs = array_values(array_filter(
                $jobs,
                static fn(array $job): bool => (string) ($job['_template_name'] ?? '') === $templateFilter
            ));
        }
        if ($sortOrder === 'template') $jobs = $this->sortCalendarJobsByTemplate($jobs);
        foreach ($jobs as &$job) $job['_show_template'] = $sortOrder === 'template';
        unset($job);
        $byDay = [];
        foreach ($jobs as $job) {
            $byDay[$job['_local_date']][] = $job;
        }

        $overallCounts = $this->isImitationMode() ? $this->imitationCounts() : $this->store()->counts();
        $viewCounts = array_fill_keys(['scheduled', 'processing', 'completed', 'failed', 'cancelled', 'superseded'], 0);
        foreach ($jobs as $job) {
            $status = (string) ($job['status'] ?? '');
            if (isset($viewCounts[$status])) $viewCounts[$status]++;
        }
        $viewCount = count($jobs);
        $settingsUrl = $this->wire('config')->urls->admin . 'module/edit?name=Relay&collapse_info=1';
        $failed = (int) ($overallCounts['failed'] ?? 0);
        $processing = (int) ($overallCounts['processing'] ?? 0);
        $scheduled = (int) ($overallCounts['scheduled'] ?? 0);
        if ($failed > 0) {
            $healthState = 'danger';
            $healthTitle = $this->_('Failed jobs need attention');
            $healthNote = sprintf($this->_n('%d failed job', '%d failed jobs', $failed), $failed);
        } elseif ($processing > 0) {
            $healthState = 'warning';
            $healthTitle = $this->_('Worker is processing jobs');
            $healthNote = sprintf($this->_n('%d active lease', '%d active leases', $processing), $processing);
        } elseif ($scheduled > 0) {
            $healthState = 'success';
            $healthTitle = $this->_('Publishing plan is active');
            $healthNote = sprintf($this->_n('%d upcoming action', '%d upcoming actions', $scheduled), $scheduled);
        } else {
            $healthState = 'neutral';
            $healthTitle = $this->_('Calendar is clear');
            $healthNote = $this->_('No publication actions are waiting.');
        }

        $token = $this->wire('session')->CSRF->getToken();
        $out = '<div class="pw-wrap pw-module-workspace RelayAdmin" data-endpoint="' . $sanitizer->entities($this->processUrl())
            . '" data-token-name="' . $sanitizer->entities($token['name']) . '" data-token-value="'
            . $sanitizer->entities($token['value']) . '" data-timezone="' . $sanitizer->entities($timezone)
            . '" data-drag-drop="' . ((int) $this->enable_drag_drop === 1 ? '1' : '0')
            . '" data-highlight-weekends="' . ((int) $this->highlight_weekends === 1 ? '1' : '0')
            . '" data-sort-order="' . $sanitizer->entities($sortOrder)
            . '" data-more-action-one="' . $sanitizer->entities($this->_('+{count} more'))
            . '" data-more-action-many="' . $sanitizer->entities($this->_('+{count} more'))
            . '" data-drag-start-label="' . $sanitizer->entities($this->_('Moving {title}. Drop it on another date.'))
            . '" data-drag-end-label="' . $sanitizer->entities($this->_('Event move cancelled.'))
            . '" data-request-failed="' . $sanitizer->entities($this->_('Request failed.'))
            . '" data-confirm-cancel="' . $sanitizer->entities($this->_('Cancel this scheduled action?'))
            . '" data-confirm-clear-imitation="' . $sanitizer->entities($this->_('Clear all demo actions from this session?')) . '">';
        if ($this->isImitationMode()) {
            $out .= '<div class="RelayImitationHost" data-endpoint="' . $sanitizer->entities($this->processUrl())
                . '" data-token-name="' . $sanitizer->entities($token['name']) . '" data-token-value="'
                . $sanitizer->entities($token['value']) . '" data-request-failed="' . $sanitizer->entities($this->_('Request failed.'))
                . '" data-confirm-clear-imitation="' . $sanitizer->entities($this->_('Clear all demo actions from this session?'))
                . '">' . $this->renderImitationBanner() . '</div>';
        }
        $out .= '<div class="RelayAdminNavigation uk-margin-medium-bottom uk-flex uk-flex-top"><div class="uk-width-expand">'
            . '<ul class="uk-subnav uk-subnav-pill RelayAdminNav" aria-label="' . $this->_('Relay views') . '">';
        foreach ($views as $key => $label) {
            $out .= '<li' . ($view === $key ? ' class="uk-active"' : '') . '><a href="' . $this->calendarUrl($key, $anchor, $pageFilterId) . '"'
                . ($view === $key ? ' aria-current="page"' : '') . '>' . $label . '</a></li>';
        }
        if ($this->canAdmin()) {
            $out .= '<li class="RelayAdminNav__rules"><a href="' . $sanitizer->entities($this->processUrl() . 'rules/') . '"><i class="fa fa-repeat" aria-hidden="true"></i> ' . $this->_('Rules') . '</a></li>';
            $out .= '<li class="RelayAdminNav__interfaces"><a href="' . $sanitizer->entities($this->processUrl() . 'interfaces/') . '"><i class="fa fa-exchange" aria-hidden="true"></i> ' . $this->_('Interfaces') . '</a></li>';
        }
        $out .= '</ul></div>';
        if ($this->canConfigureModule()) {
            $out .= '<div class="uk-width-auto"><a class="RelayAdminSettings uk-link-muted uk-display-inline-flex uk-flex-middle" href="'
                . $sanitizer->entities($settingsUrl) . '" title="' . $this->_('Relay settings') . '" aria-label="' . $this->_('Relay settings') . '">'
                . $this->settingsIcon() . '</a></div>';
        }
        $out .= '</div>';
        $out .= '<section class="RelayPageIntro" aria-label="' . $this->_('Publishing schedule') . '"><div class="RelayPageIntro__copy">'
            . '<p class="RelayPageIntro__eyebrow">' . $this->_('Relay') . '</p><p>'
            . $viewDescriptions[$view] . '</p></div>'
            . '<div class="RelayPageIntro__actions"><div class="RelayHealth" data-state="' . $healthState . '"><span class="RelayHealth__dot"></span>'
            . '<div><strong>' . $healthTitle . '</strong><small>' . $this->_('Overall schedule') . ' · ' . $healthNote . '</small></div></div></div></section>';
        $out .= '<nav class="RelayStatusStrip" aria-label="' . $this->_('Actions in current view by status') . '">';
        foreach (['scheduled', 'processing', 'completed', 'failed', 'cancelled', 'superseded'] as $status) {
            $out .= '<span data-state="' . $status . '"><span>' . $this->statusLabel($status) . '</span><strong>' . (int) ($viewCounts[$status] ?? 0) . '</strong></span>';
        }
        $monthJumpHidden = '<input type="hidden" name="view" value="' . $sanitizer->entities($view) . '">'
            . ($pageFilterId > 0 ? '<input type="hidden" name="page_id" value="' . $pageFilterId . '">' : '')
            . ($actionFilter !== '' ? '<input type="hidden" name="action" value="' . $sanitizer->entities($actionFilter) . '">' : '')
            . ($statusFilter !== '' ? '<input type="hidden" name="status" value="' . $sanitizer->entities($statusFilter) . '">' : '')
            . ($templateFilter !== '' ? '<input type="hidden" name="template" value="' . $sanitizer->entities($templateFilter) . '">' : '')
            . ($sortOrder === 'template' ? '<input type="hidden" name="sort" value="template">' : '');
        $monthJump = '<form class="RelayMonthJump" method="get">' . $monthJumpHidden
            . '<label><span class="uk-hidden">' . $this->_('Choose month and year') . '</span>'
            . '<span class="RelayMonthJump__icon" aria-hidden="true"><i class="fa fa-calendar"></i></span>'
            . '<input type="date" name="date" value="' . $anchor->format('Y-m-d') . '" aria-label="'
            . $this->_('Choose month and year') . '" data-relay-month-picker></label></form>';
        $out .= '</nav><section class="RelayCalendarPanel' . ($viewCount === 0 ? ' is-empty' : '') . '"><header class="RelayCalendarHeader"><div><p class="RelayCalendarHeader__eyebrow">'
            . $sanitizer->entities($range['eyebrow']) . '</p><div class="RelayCalendarHeader__title"><h2>' . $sanitizer->entities($range['title'])
            . '</h2><span>' . $sanitizer->entities($timezone) . '</span>' . $monthJump . '</div><p>'
            . sprintf($this->_n('%d action in this view', '%d actions in this view', $viewCount), $viewCount) . '</p></div>'
            . '<nav class="RelayCalendarNav" aria-label="' . $this->_('Calendar range') . '">'
            . '<a class="uk-button uk-button-default" href="' . $this->calendarUrl($view, $range['previous'], $pageFilterId) . '"><i class="fa fa-arrow-left" aria-hidden="true"></i><span>' . $this->_('Previous') . '</span></a>'
            . '<a class="uk-button uk-button-default" href="' . $this->calendarUrl($view, new \DateTimeImmutable('today', new \DateTimeZone($timezone)), $pageFilterId) . '">' . $this->_('Today') . '</a>'
            . '<a class="uk-button uk-button-default" href="' . $this->calendarUrl($view, $range['next'], $pageFilterId) . '"><span>' . $this->_('Next') . '</span><i class="fa fa-arrow-right" aria-hidden="true"></i></a>'
            . '</nav></header>';
        $out .= $this->renderViewContext($view);
        $out .= '<form class="RelayCalendarFilters" method="get"><input type="hidden" name="view" value="' . $sanitizer->entities($view)
            . '"><input type="hidden" name="date" value="' . $anchor->format('Y-m-d') . '">'
            . ($pageFilterId > 0 ? '<input type="hidden" name="page_id" value="' . $pageFilterId . '">' : '')
            . '<label><span>' . $this->_('Action') . '</span><select name="action" data-relay-auto-submit><option value="">' . $this->_('All actions') . '</option>';
        foreach (['publish' => $this->_('Publish'), 'unpublish' => $this->_('Unpublish')] as $filterValue => $filterLabel) {
            $out .= '<option value="' . $filterValue . '"' . ($actionFilter === $filterValue ? ' selected' : '') . '>' . $filterLabel . '</option>';
        }
        $out .= '</select></label><label><span>' . $this->_('Status') . '</span><select name="status" data-relay-auto-submit><option value="">'
            . $this->_('All statuses') . '</option>';
        foreach (['scheduled', 'processing', 'completed', 'failed', 'cancelled', 'superseded'] as $filterValue) {
            $out .= '<option value="' . $filterValue . '"' . ($statusFilter === $filterValue ? ' selected' : '') . '>' . $this->statusLabel($filterValue) . '</option>';
        }
        $out .= '</select></label>';
        if ($templateControlsEnabled) {
            $out .= '<label><span>' . $this->_('Template') . '</span><select name="template" data-relay-auto-submit><option value="">'
                . $this->_('All templates') . '</option>';
            foreach ($filterTemplateOptions as $templateName => $templateLabel) {
                $out .= '<option value="' . $sanitizer->entities($templateName) . '"' . ($templateFilter === $templateName ? ' selected' : '') . '>'
                    . $sanitizer->entities($templateLabel) . '</option>';
            }
            $out .= '</select></label><label><span>' . $this->_('Order') . '</span><select name="sort" data-relay-auto-submit>'
                . '<option value="date"' . ($sortOrder === 'date' ? ' selected' : '') . '>' . $this->_('Date and time') . '</option>'
                . '<option value="template"' . ($sortOrder === 'template' ? ' selected' : '') . '>' . $this->_('Template, then time') . '</option>'
                . '</select></label>';
        }
        $clearUrl = '?view=' . rawurlencode($view) . '&amp;date=' . $anchor->format('Y-m-d') . ($pageFilterId > 0 ? '&amp;page_id=' . $pageFilterId : '');
        $out .= '<button class="uk-button uk-button-default" type="submit">' . $this->_('Apply filters') . '</button>'
            . ($this->calendarFilters ? '<a href="' . $clearUrl . '">' . $this->_('Clear filters') . '</a>' : '') . '</form>';
        if ($sortOrder === 'template' && $filterTemplateOptions) {
            $out .= '<aside class="RelayTemplateOrder"><span><i class="fa fa-sort-alpha-asc" aria-hidden="true"></i> '
                . $this->_('Grouped by template; actions inside each template stay in date and time order.') . '</span><div>';
            foreach ($filterTemplateOptions as $templateName => $templateLabel) {
                $out .= '<span class="RelayTemplateChip" title="' . $sanitizer->entities($templateName) . '">' . $sanitizer->entities($templateLabel) . '</span>';
            }
            $out .= '</div></aside>';
        }
        if ($pageFilterId > 0) {
            $pageFilter = $this->wire('pages')->get($pageFilterId);
            if ($pageFilter->id && $pageFilter->viewable()) {
                $out .= '<div class="RelayCalendarFilter"><span><i class="fa fa-filter" aria-hidden="true"></i> <strong>' . $this->_('Page') . ':</strong> '
                    . $sanitizer->entities($pageFilter->get('title|name')) . '</span><a href="' . $this->calendarUrl($view, $anchor) . '">'
                    . $this->_('Clear filter') . '</a></div>';
            }
        }
        if ($truncated) {
            $out .= '<div class="RelayCalendarNotice"><i class="fa fa-info-circle" aria-hidden="true"></i> '
                . $this->_('This view is limited to the first 500 actions. Narrow the date range or filter by page.') . '</div>';
        }
        if ($viewCount === 0) {
            $out .= '<div class="RelayCalendarEmpty"><i class="fa fa-calendar-check-o" aria-hidden="true"></i><div><strong>'
                . $this->_('No actions in this period') . '</strong><span>' . $this->_('Relay an action from the Relay tab on any editable page.')
                . '</span></div><a class="uk-button uk-button-default" href="' . $sanitizer->entities((string)$this->wire('config')->urls->admin . 'page/')
                . '"><i class="fa fa-sitemap" aria-hidden="true"></i> ' . $this->_('Browse pages') . '</a></div>';
        }
        $out .= match ($view) {
            'week' => $this->renderAgendaView($range['start'], 7, $byDay),
            'three-day' => $this->renderAgendaView($range['start'], 3, $byDay),
            'quarter' => $this->renderQuarterView($range['start'], $byDay, $pageFilterId),
            'kanban' => $this->renderKanbanView($jobs),
            'timeline' => $this->renderTimelineView($range['start'], $jobs, 14),
            default => $this->renderMonthView($anchor, $range['start'], $range['end'], $byDay, $pageFilterId),
        };
        $out .= '</section>' . $this->renderCalendarPopover() . '<span class="RelayVisuallyHidden" data-relay-drag-status aria-live="polite"></span><div class="RelayToast" role="status" aria-live="polite" hidden>'
            . '<span data-relay-toast-message></span><button type="button" data-relay-toast-undo hidden>' . $this->_('Undo')
            . '</button><button type="button" class="RelayToast__close" data-relay-toast-close aria-label="' . $this->_('Dismiss notification')
            . '"><i class="fa fa-times" aria-hidden="true"></i></button></div></div>';
        return $out;
    }

    private function calendarRange(string $view, \DateTimeImmutable $anchor): array
    {
        $anchor = $anchor->setTime(0, 0);
        if (in_array($view, ['quarter', 'kanban'], true)) {
            $month = intdiv((int) $anchor->format('n') - 1, 3) * 3 + 1;
            $start = $anchor->setDate((int) $anchor->format('Y'), $month, 1);
            $end = $start->modify('+3 months');
            $quarter = intdiv($month - 1, 3) + 1;
            return [
                'start' => $start,
                'end' => $end,
                'previous' => $start->modify('-3 months'),
                'next' => $start->modify('+3 months'),
                'title' => 'Q' . $quarter . ' · ' . $this->formatCalendarDate($start, 'M') . '–' . $this->formatCalendarDate($end->modify('-1 day'), 'M Y'),
                'eyebrow' => $view === 'kanban' ? $this->_('Workflow board') : $this->_('Quarter calendar'),
            ];
        }
        if ($view === 'week') {
            $start = $this->calendarWeekStart($anchor);
            $end = $start->modify('+7 days');
            return [
                'start' => $start,
                'end' => $end,
                'previous' => $start->modify('-7 days'),
                'next' => $start->modify('+7 days'),
                'title' => $this->formatDateRange($start, $end->modify('-1 day')),
                'eyebrow' => $this->_('Week calendar'),
            ];
        }
        if ($view === 'three-day') {
            $start = $anchor;
            $end = $start->modify('+3 days');
            return [
                'start' => $start,
                'end' => $end,
                'previous' => $start->modify('-3 days'),
                'next' => $start->modify('+3 days'),
                'title' => $this->formatDateRange($start, $end->modify('-1 day')),
                'eyebrow' => $this->_('Three-day calendar'),
            ];
        }
        if ($view === 'timeline') {
            $start = $this->calendarWeekStart($anchor);
            $end = $start->modify('+14 days');
            return [
                'start' => $start,
                'end' => $end,
                'previous' => $start->modify('-14 days'),
                'next' => $start->modify('+14 days'),
                'title' => $this->formatDateRange($start, $end->modify('-1 day')),
                'eyebrow' => $this->_('Page timeline'),
            ];
        }

        $month = $anchor->modify('first day of this month');
        $start = $this->calendarWeekStart($month);
        $end = $this->calendarWeekStart($month->modify('last day of this month'))->modify('+7 days');
        return [
            'start' => $start,
            'end' => $end,
            'previous' => $month->modify('-1 month'),
            'next' => $month->modify('+1 month'),
            'title' => $this->formatCalendarDate($month, 'F Y'),
            'eyebrow' => $this->_('Month calendar'),
        ];
    }

    private function formatCalendarDate(\DateTimeImmutable $date, string $format): string
    {
        // Preserve the editorial calendar's wall date while letting ProcessWire translate
        // month and weekday names in the current admin language.
        $wallDate = new \DateTimeImmutable($date->format('Y-m-d H:i:s'), new \DateTimeZone(date_default_timezone_get()));
        $formatted = $this->wire('datetime')->date($format, $wallDate->getTimestamp());
        return is_string($formatted) && $formatted !== '' ? $formatted : $date->format($format);
    }

    private function calendarWeekStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $firstIsoDay = (string) $this->week_starts_on === 'sunday' ? 7 : 1;
        $offset = ((int) $date->format('N') - $firstIsoDay + 7) % 7;
        return $offset > 0 ? $date->modify('-' . $offset . ' days') : $date;
    }

    private function calendarWeekdayLabels(): array
    {
        $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        if ((string) $this->week_starts_on === 'sunday') {
            array_unshift($labels, array_pop($labels));
        }
        return $labels;
    }

    private function calendarWeekendClass(\DateTimeImmutable $date): string
    {
        return (int) $this->highlight_weekends === 1 && (int) $date->format('N') >= 6 ? ' is-weekend' : '';
    }

    private function formatDateRange(\DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        if ($start->format('Y-m') === $end->format('Y-m')) {
            return $this->formatCalendarDate($start, 'M j') . '–' . $this->formatCalendarDate($end, 'j, Y');
        }
        if ($start->format('Y') === $end->format('Y')) {
            return $this->formatCalendarDate($start, 'M j') . '–' . $this->formatCalendarDate($end, 'M j, Y');
        }
        return $this->formatCalendarDate($start, 'M j, Y') . '–' . $this->formatCalendarDate($end, 'M j, Y');
    }

    private function calendarUrl(string $view, \DateTimeImmutable $date, int $pageId = 0): string
    {
        $url = '?view=' . rawurlencode($view) . '&amp;date=' . $date->format('Y-m-d');
        if ($pageId > 0) {
            $url .= '&amp;page_id=' . $pageId;
        }
        foreach ($this->calendarFilters as $name => $value) {
            $url .= '&amp;' . rawurlencode($name) . '=' . rawurlencode($value);
        }
        return $url;
    }

/** @return array<string,string> */
    private function calendarTemplateOptions(): array
    {
        $configured = $this->configListValues($this->allowed_templates);
        $allowed = $configured !== [] ? array_fill_keys($configured, true) : null;
        $options = [];
        foreach ($this->wire('templates') as $template) {
            $name = (string) $template->name;
            if ($name === 'admin' || ((int) $template->flags & Template::flagSystem)) continue;
            if ($allowed !== null && !isset($allowed[$name])) continue;
            $label = trim((string) $template->getLabel());
            $options[$name] = $label !== '' && $label !== $name ? $label . ' (' . $name . ')' : $name;
        }
        uasort($options, static fn(string $left, string $right): int => strnatcasecmp($left, $right));
        return $options;
    }

/** @param array<string,string> $templateOptions @return array<string,string> */
    private function calendarTemplateFilterOptions(array $templateOptions): array
    {
        uasort($templateOptions, static fn(string $left, string $right): int => strnatcasecmp($left, $right));
        return $templateOptions;
    }

    private function hydrateCalendarJobs(array $jobs, string $timezone): array
    {
        $pages = [];
        $users = [];
        $canManageSchedules = $this->wire('user')->hasPermission('relay-manage');
        foreach ($jobs as &$job) {
            $pageId = (int) $job['page_id'];
            if (!isset($pages[$pageId])) {
                $page = $this->wire('pages')->get($pageId);
                $canSee = $page->id && ($page->viewable() || $page->editable());
                $templateName = $page->id ? (string) $page->template->name : '';
                $templateLabel = $page->id ? trim((string) $page->template->getLabel()) : '';
                $pages[$pageId] = [
                    'title' => $canSee ? (string) $page->get('title|name') : $this->_('Restricted page'),
                    'public_url' => $canSee ? (string) $page->httpUrl : '',
                    'edit_url' => $canSee && $page->editable() ? (string) $page->editUrl : '',
                    'template_name' => $canSee ? $templateName : '',
                    'template_label' => $canSee ? ($templateLabel !== '' ? $templateLabel : $templateName) : '',
                    'editable' => $page->id && $page->editable() && $this->templateAllowed($page),
                ];
                if ($page->id) {
                    $this->wire('pages')->uncache($page);
                }
            }
            $job['_title'] = $pages[$pageId]['title'];
            $job['_public_url'] = $pages[$pageId]['public_url'];
            $job['_url'] = $pages[$pageId]['edit_url'];
            $job['_template_name'] = $pages[$pageId]['template_name'];
            $job['_template_label'] = $pages[$pageId]['template_label'];
            $job['_can_manage'] = $canManageSchedules && $pages[$pageId]['editable'] && $job['status'] === 'scheduled';
            $job['_can_view_note'] = $canManageSchedules && $pages[$pageId]['editable'];
            foreach (['requested_by_user_id' => '_requester', 'run_as_user_id' => '_actor'] as $idKey => $targetKey) {
                $userId = (int) $job[$idKey];
                if (!isset($users[$userId])) {
                    $user = $this->wire('users')->get($userId);
                    $users[$userId] = $user->id ? (string) $user->get('title|name') : '#' . $userId;
                }
                $job[$targetKey] = $users[$userId];
            }
            $job['_local_date'] = RelayClock::utcToLocal((string) $job['scheduled_at'], $timezone, 'Y-m-d');
            $job['_local_time'] = RelayClock::utcToLocal((string) $job['scheduled_at'], $timezone, 'H:i');
            $localDate = (new \DateTimeImmutable((string)$job['scheduled_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($timezone));
            $job['_local_datetime'] = $this->formatCalendarDate($localDate, 'M j, Y') . ' · ' . $localDate->format('H:i');
        }
        unset($job);
        return $jobs;
    }

    private function renderMonthView(
        \DateTimeImmutable $anchor,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $byDay,
        int $pageFilterId
    ): string {
        $out = '<div class="RelayCalendar RelayView RelayView--month" data-week-start="'
            . ((string) $this->week_starts_on === 'sunday' ? 'sunday' : 'monday') . '"><div class="RelayCalendar__weekdays">';
        foreach ($this->calendarWeekdayLabels() as $weekday) {
            $out .= '<strong' . (in_array($weekday, ['Sat', 'Sun'], true) && (int) $this->highlight_weekends === 1 ? ' class="is-weekend"' : '') . '>'
                . $this->_($weekday) . '</strong>';
        }
        $out .= '</div><div class="RelayCalendar__grid">';
        $today = new \DateTimeImmutable('today', $anchor->getTimezone());
        $selectedMonth = $anchor->format('Y-m');
        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $dayJobs = $byDay[$key] ?? [];
            $classes = $day->format('Y-m') !== $selectedMonth ? ' is-outside' : '';
            $classes .= $key === $today->format('Y-m-d') ? ' is-today' : '';
            $classes .= $dayJobs ? ' has-events' : '';
            $classes .= $this->calendarWeekendClass($day);
            $drilldownLabel = sprintf($this->_('Open %s in the three-day view'), $this->formatCalendarDate($day, 'M j, Y'));
            $dayUrl = $this->calendarUrl('three-day', $day, $pageFilterId);
            $out .= '<section class="RelayDay' . $classes . '" data-relay-drop-date="' . $key . '" data-day-url="'
                . $this->wire('sanitizer')->entities($dayUrl) . '"><div class="RelayDay__header"><time datetime="' . $key . '"><a class="RelayDay__drilldown" href="'
                . $dayUrl . '" aria-label="' . $this->wire('sanitizer')->entities($drilldownLabel)
                . '"><span class="RelayDay__desktopDate">' . $day->format('j') . '</span><span class="RelayDay__mobileDate">'
                . $this->wire('sanitizer')->entities($this->formatCalendarDate($day, 'D, M j')) . '</span></a></time>'
                . '<a class="RelayDay__open" href="' . $dayUrl . '" aria-label="' . $this->wire('sanitizer')->entities($drilldownLabel) . '"'
                . ($dayJobs ? '' : ' hidden') . '><i class="fa fa-calendar-o" aria-hidden="true"></i></a></div><div class="RelayDay__events">';
            foreach ($dayJobs as $job) {
                $out .= $this->renderCalendarEvent($job);
            }
            $out .= '</div>';
            $hiddenCount = max(0, count($dayJobs) - 3);
            $out .= '<a class="RelayDay__more" href="' . $dayUrl . '" aria-label="' . $this->wire('sanitizer')->entities($drilldownLabel) . '"'
                . ($hiddenCount > 0 ? '' : ' hidden') . '><strong data-relay-more-count>'
                . sprintf($this->_('+%d more'), $hiddenCount)
                . '</strong><span aria-hidden="true"><i class="fa fa-calendar-o"></i></span></a>';
            $out .= '</section>';
        }
        return $out . '</div></div>';
    }

    private function renderAgendaView(\DateTimeImmutable $start, int $days, array $byDay): string
    {
        $today = (new \DateTimeImmutable('today', $start->getTimezone()))->format('Y-m-d');
        $hasJobs = false;
        for ($offset = 0; $offset < $days; $offset++) {
            if (!empty($byDay[$start->modify('+' . $offset . ' days')->format('Y-m-d')])) {
                $hasJobs = true;
                break;
            }
        }
        $out = '<div class="RelayAgendaGrid RelayView RelayView--agenda' . ($hasJobs ? '' : ' is-empty') . '" data-days="' . $days . '">';
        for ($offset = 0; $offset < $days; $offset++) {
            $day = $start->modify('+' . $offset . ' days');
            $key = $day->format('Y-m-d');
            $dayJobs = $byDay[$key] ?? [];
            $out .= '<section class="RelayAgendaDay' . ($key === $today ? ' is-today' : '') . $this->calendarWeekendClass($day)
                . '" data-relay-drop-date="' . $key . '"><header><p>' . $this->formatCalendarDate($day, 'D')
                . '</p><time datetime="' . $key . '">' . $this->formatCalendarDate($day, 'M j') . '</time><span>'
                . sprintf($this->_n('%d action', '%d actions', count($dayJobs)), count($dayJobs)) . '</span></header><div class="RelayAgendaDay__body">';
            if (!$dayJobs && $hasJobs) {
                $out .= '<p class="RelayAgendaEmpty">' . $this->_('No actions') . '</p>';
            } else {
                foreach ($dayJobs as $job) {
                    $out .= $this->renderCalendarEvent($job);
                }
            }
            $out .= '</div></section>';
        }
        return $out . '</div>';
    }

    private function renderQuarterView(\DateTimeImmutable $start, array $byDay, int $pageFilterId): string
    {
        $out = '<div class="RelayQuarterGrid RelayView RelayView--quarter">';
        for ($monthOffset = 0; $monthOffset < 3; $monthOffset++) {
            $month = $start->modify('+' . $monthOffset . ' months');
            $gridStart = $this->calendarWeekStart($month);
            $gridEnd = $this->calendarWeekStart($month->modify('last day of this month'))->modify('+7 days');
            $out .= '<section class="RelayQuarterMonth"><header><h3>' . $this->formatCalendarDate($month, 'F') . '</h3><span>' . $month->format('Y') . '</span></header>'
                . '<div class="RelayQuarterWeekdays">';
            foreach ($this->calendarWeekdayLabels() as $weekday) {
                $weekdayLabel = $this->_($weekday);
                $out .= '<span' . (in_array($weekday, ['Sat', 'Sun'], true) && (int) $this->highlight_weekends === 1 ? ' class="is-weekend"' : '')
                    . ' aria-label="' . $this->wire('sanitizer')->entities($weekdayLabel) . '">'
                    . $this->wire('sanitizer')->entities($weekdayLabel) . '</span>';
            }
            $out .= '</div><div class="RelayQuarterDays">';
            for ($day = $gridStart; $day < $gridEnd; $day = $day->modify('+1 day')) {
                $key = $day->format('Y-m-d');
                $dayJobs = $byDay[$key] ?? [];
                $title = implode(' · ', array_map(
                    fn(array $job): string => $job['_local_time'] . ' ' . $this->actionLabel((string) $job['action']) . ' — ' . $job['_title'],
                    $dayJobs
                ));
                $drilldownLabel = sprintf($this->_('Open %s in the three-day view'), $this->formatCalendarDate($day, 'M j, Y'));
                $out .= '<div class="RelayQuarterDay' . ($day->format('Y-m') !== $month->format('Y-m') ? ' is-outside' : '')
                    . ($dayJobs ? ' has-events' : '') . $this->calendarWeekendClass($day) . '" data-relay-drop-date="' . $key . '"'
                    . ($title !== '' ? ' title="' . $this->wire('sanitizer')->entities($title) . '"' : '') . '>'
                    . '<a class="RelayQuarterDay__drilldown" href="' . $this->calendarUrl('three-day', $day, $pageFilterId) . '" aria-label="'
                    . $this->wire('sanitizer')->entities($drilldownLabel) . '"><time datetime="' . $key . '">' . $day->format('j') . '</time>';
                if ($dayJobs) {
                    $out .= '<span>' . count($dayJobs) . '</span>';
                }
                $out .= '</a>';
                if ($dayJobs) {
                    $out .= '<div class="RelayQuarterDay__events">';
                    foreach (array_slice($dayJobs, 0, 3) as $job) {
                        $eventLabel = $job['_local_time'] . ' ' . $this->actionLabel((string) $job['action']) . ' — ' . $job['_title'];
                        $out .= '<button type="button" class="RelayQuarterEvent is-action-' . $this->wire('sanitizer')->name((string) $job['action']) . '"'
                            . $this->calendarJobDataAttributes($job) . ' aria-label="' . $this->wire('sanitizer')->entities($eventLabel) . '"><i class="fa fa-arrow-'
                            . ($job['action'] === 'publish' ? 'up' : 'down') . '" aria-hidden="true"></i></button>';
                    }
                    $out .= '</div>';
                }
                $out .= '</div>';
            }
            $out .= '</div></section>';
        }
        return $out . '</div>';
    }

    private function renderKanbanView(array $jobs): string
    {
        $columns = [
            'scheduled' => $this->_('Scheduled'),
            'processing' => $this->_('Processing'),
            'completed' => $this->_('Completed'),
            'failed' => $this->_('Failed'),
            'cancelled' => $this->_('Cancelled'),
            'superseded' => $this->_('Superseded'),
        ];
        $grouped = array_fill_keys(array_keys($columns), []);
        foreach ($jobs as $job) {
            $status = (string) $job['status'];
            if (isset($grouped[$status])) {
                $grouped[$status][] = $job;
            }
        }
        $hasJobs = !empty($jobs);
        $out = '<nav class="RelayMobileViewJump RelayKanbanJump" aria-label="' . $this->_('Choose Kanban column') . '">';
        foreach ($columns as $status => $label) {
            $out .= '<button type="button" data-relay-kanban-jump="' . $status . '"><span>' . $label . '</span><strong>'
                . count($grouped[$status]) . '</strong></button>';
        }
        $out .= '</nav><div class="RelayKanban RelayView RelayView--kanban' . ($hasJobs ? '' : ' is-empty') . '">';
        foreach ($columns as $status => $label) {
            $out .= '<section class="RelayKanbanColumn" data-state="' . $status . '" data-kanban-column="' . $status . '"><header><h3>' . $label . '</h3><span>'
                . count($grouped[$status]) . '</span></header><div class="RelayKanbanColumn__body">';
            if (!$grouped[$status] && $hasJobs) {
                $out .= '<p class="RelayKanbanEmpty">' . $this->_('No actions') . '</p>';
            }
            foreach ($grouped[$status] as $job) {
                $out .= '<article class="RelayKanbanCard" tabindex="0" role="button"' . $this->calendarJobDataAttributes($job, false)
                    . '><div class="RelayKanbanCard__meta"><time>'
                    . $this->wire('sanitizer')->entities($job['_local_datetime']) . '</time></div>'
                    . '<strong class="RelayKanbanCard__title">' . $this->wire('sanitizer')->entities($job['_title']) . '</strong>'
                    . (!empty($job['_show_template']) ? '<span class="RelayTemplateChip">' . $this->wire('sanitizer')->entities((string)$job['_template_label']) . '</span>' : '')
                    . '<p><i class="fa fa-arrow-'
                    . ($job['action'] === 'publish' ? 'up' : 'down') . '" aria-hidden="true"></i> '
                    . $this->actionLabel((string) $job['action']) . '</p></article>';
            }
            $out .= '</div></section>';
        }
        return $out . '</div>';
    }

    private function renderTimelineView(\DateTimeImmutable $start, array $jobs, int $days): string
    {
        $rows = [];
        foreach ($jobs as $job) {
            $rows[(int) $job['page_id']][] = $job;
        }
        uasort($rows, static fn(array $a, array $b): int => strcasecmp($a[0]['_title'], $b[0]['_title']));
        $out = '<nav class="RelayMobileViewJump RelayTimelineJump" aria-label="' . $this->_('Choose timeline date') . '">';
        for ($offset = 0; $offset < $days; $offset++) {
            $day = $start->modify('+' . $offset . ' days');
            $out .= '<button type="button" data-relay-timeline-jump="' . $day->format('Y-m-d') . '" aria-label="'
                . $this->wire('sanitizer')->entities(sprintf($this->_('Open %s on the timeline'), $this->formatCalendarDate($day, 'M j, Y')))
                . '">' . $this->wire('sanitizer')->entities($this->formatCalendarDate($day, 'D j')) . '</button>';
        }
        $out .= '</nav><div class="RelayTimelineWrap RelayView RelayView--timeline"><div class="RelayTimeline" style="--relay-timeline-days:'
            . $days . '"><div class="RelayTimeline__corner">' . $this->_('Page') . '</div>';
        for ($offset = 0; $offset < $days; $offset++) {
            $day = $start->modify('+' . $offset . ' days');
            $out .= '<div class="RelayTimeline__day' . $this->calendarWeekendClass($day) . '" data-timeline-date="' . $day->format('Y-m-d') . '"><span>' . $this->formatCalendarDate($day, 'D')
                . '</span><strong>' . $day->format('j') . '</strong></div>';
        }
        foreach ($rows as $pageJobs) {
            $first = $pageJobs[0];
            $timelineMeta = !empty($first['_show_template']) && (string)$first['_template_label'] !== ''
                ? (string)$first['_template_label']
                : '#' . (int)$first['page_id'];
            $out .= '<div class="RelayTimeline__page">' . $this->renderCalendarJobTitle($first, 'RelayTimeline__pageTitle') . '<small>'
                . $this->wire('sanitizer')->entities($timelineMeta) . '</small></div>';
            $byDate = [];
            foreach ($pageJobs as $job) {
                $byDate[$job['_local_date']][] = $job;
            }
            for ($offset = 0; $offset < $days; $offset++) {
                $cellDay = $start->modify('+' . $offset . ' days');
                $key = $cellDay->format('Y-m-d');
                $out .= '<div class="RelayTimeline__cell' . $this->calendarWeekendClass($cellDay) . '" data-relay-drop-date="'
                    . $key . '" data-page-id="' . (int) $first['page_id'] . '">';
                foreach ($byDate[$key] ?? [] as $job) {
                    $label = $job['_local_time'] . ' ' . $this->actionLabel((string) $job['action']) . ' — ' . $job['_title'] . ' · ' . $this->statusLabel((string) $job['status']);
                    $action = (string) $job['action'];
                    $out .= '<button type="button" class="RelayTimeline__marker is-' . $this->wire('sanitizer')->name((string) $job['status'])
                        . ' is-action-' . $this->wire('sanitizer')->name($action) . '"' . $this->calendarJobDataAttributes($job)
                        . ' title="' . $this->wire('sanitizer')->entities($label) . '" aria-label="' . $this->wire('sanitizer')->entities($label)
                        . '"><i class="fa fa-arrow-' . ($action === 'publish' ? 'up' : 'down') . '" aria-hidden="true"></i><span>'
                        . $this->wire('sanitizer')->entities($job['_local_time']) . '</span></button>';
                }
                $out .= '</div>';
            }
        }
        return $out . '</div></div>';
    }

    private function renderViewContext(string $view): string
    {
        $hints = [
            'month' => $this->_('Select a date number for its agenda, or drag a pending action to another day.'),
            'week' => $this->_('Open an action for details, or drag a pending action between days.'),
            'quarter' => $this->_('Open a day for its agenda, or use compact pending events for details and drag-and-drop.'),
            'three-day' => $this->_('Open actions for details or drag pending work while preserving its local time.'),
            'kanban' => $this->_('Cards are grouped by status; open any card for full details and actions.'),
            'timeline' => $this->_('Rows are pages and columns are days; pending markers can move within their page row.'),
        ];
        return '<aside class="RelayViewContext" aria-label="' . $this->_('View guide') . '"><span><i class="fa fa-info-circle" aria-hidden="true"></i> '
            . $hints[$view] . '</span><span class="RelayActionLegend"><span><i class="fa fa-arrow-up" aria-hidden="true"></i> '
            . $this->_('Publish') . '</span><span><i class="fa fa-arrow-down" aria-hidden="true"></i> ' . $this->_('Unpublish') . '</span></span>'
            . '<span class="RelayTouchHint"><i class="fa fa-hand-pointer-o" aria-hidden="true"></i> '
            . $this->_('On a touch screen, open an action and use Date and time to move it precisely.') . '</span></aside>';
    }

    private function renderCalendarEvent(array $job): string
    {
        $dragHandle = $this->calendarJobCanDrag($job)
            ? '<span class="RelayEvent__drag" aria-hidden="true"><i class="fa fa-arrows" aria-hidden="true"></i></span>'
            : '';
        $template = !empty($job['_show_template']) && (string)$job['_template_label'] !== ''
            ? '<small class="RelayEvent__template">' . $this->wire('sanitizer')->entities((string)$job['_template_label']) . '</small>'
            : '';
        return '<button type="button" class="RelayEvent is-' . $this->wire('sanitizer')->name((string) $job['status']) . '"'
            . $this->calendarJobDataAttributes($job) . '><time>'
            . $this->wire('sanitizer')->entities($job['_local_time']) . '</time><span>' . $this->actionLabel((string) $job['action']) . ' · '
            . $this->wire('sanitizer')->entities($job['_title']) . $template . '</span>' . $dragHandle . '</button>';
    }

    private function sortCalendarJobsByTemplate(array $jobs): array
    {
        usort($jobs, static function (array $left, array $right): int {
            $templateComparison = strnatcasecmp(
                (string) ($left['_template_label'] ?? $left['_template_name'] ?? ''),
                (string) ($right['_template_label'] ?? $right['_template_name'] ?? '')
            );
            if ($templateComparison !== 0) return $templateComparison;
            $dateComparison = strcmp((string) ($left['scheduled_at'] ?? ''), (string) ($right['scheduled_at'] ?? ''));
            return $dateComparison !== 0 ? $dateComparison : ((int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0));
        });
        return $jobs;
    }

    private function calendarJobCanDrag(array $job): bool
    {
        return !empty($job['_can_manage']) && (int)$this->enable_drag_drop === 1;
    }

    private function calendarJobDataAttributes(array $job, bool $allowDrag = true): string
    {
        $sanitizer = $this->wire('sanitizer');
        $canManage = !empty($job['_can_manage']);
        $attributes = [
            'data-relay-job' => '1',
            'data-job-id' => (string) (int) $job['id'],
            'data-page-id' => (string) (int) $job['page_id'],
            'data-page-title' => (string) $job['_title'],
            'data-page-url' => (string) $job['_url'],
            'data-public-url' => (string) $job['_public_url'],
            'data-template-name' => (string) $job['_template_name'],
            'data-template-label' => (string) $job['_template_label'],
            'data-action' => (string) $job['action'],
            'data-action-label' => $this->actionLabel((string) $job['action']),
            'data-status' => (string) $job['status'],
            'data-status-label' => $this->statusLabel((string) $job['status']),
            'data-datetime-local' => (string) $job['_local_date'] . 'T' . (string) $job['_local_time'],
            'data-datetime-label' => (string) $job['_local_datetime'],
            'data-run-as' => (string) (int) $job['run_as_user_id'],
            'data-actor' => (string) $job['_actor'],
            'data-requester' => (string) $job['_requester'],
            'data-note' => !empty($job['_can_view_note']) ? (string) $job['note'] : '',
            'data-note-visible' => !empty($job['_can_view_note']) ? '1' : '0',
            'data-can-manage' => $canManage ? '1' : '0',
            'aria-haspopup' => 'dialog',
        ];
        if ($allowDrag && $this->calendarJobCanDrag($job)) {
            $attributes['draggable'] = 'true';
            $attributes['data-relay-draggable'] = '1';
            $attributes['aria-description'] = $this->_('Drag this pending action to another date, or open it for exact rescheduling.');
        }
        $html = '';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . $sanitizer->entities($value) . '"';
        }
        return $html;
    }

    private function renderCalendarPopover(): string
    {
        return '<div class="RelayPopover" id="RelayEventPopover" popover="auto" role="dialog" aria-labelledby="RelayPopoverTitle"'
            . ' data-unknown-template="' . $this->wire('sanitizer')->entities($this->_('Unknown template')) . '"'
            . ' data-empty-note="' . $this->wire('sanitizer')->entities($this->_('No note added.')) . '"'
            . ' data-copy-success="' . $this->wire('sanitizer')->entities($this->_('Publication URL copied.')) . '"'
            . ' data-copy-failed="' . $this->wire('sanitizer')->entities($this->_('Could not copy the publication URL.')) . '">'
            . '<header><div><span class="RelayPopover__eyebrow">' . $this->_('Publication details') . '</span><h3 id="RelayPopoverTitle" data-popover-title></h3></div>'
            . '<button type="button" class="RelayPopover__close" data-popover-close aria-label="' . $this->_('Close details')
            . '"><i class="fa fa-times" aria-hidden="true"></i></button></header>'
            . '<div class="RelayPopover__summary"><div><small>' . $this->_('Status') . '</small><span class="RelayBadge" data-popover-status></span></div>'
            . '<div><small>' . $this->_('Date') . '</small><time data-popover-date></time></div><div><small>' . $this->_('Action') . '</small>'
            . '<strong data-popover-action></strong></div></div>'
            . '<div class="RelayPopover__body"><div class="RelayPopover__details">'
            . '<section class="RelayPopover__section" aria-labelledby="RelayPopoverPage"><h4 id="RelayPopoverPage">' . $this->_('Publication') . '</h4>'
            . '<dl><div><dt>' . $this->_('Template') . '</dt>'
            . '<dd><span data-popover-template-detail></span> <code data-popover-template-name-detail></code></dd></div><div class="RelayPopover__urlRow"><dt>'
            . $this->_('URL') . '</dt><dd><a data-popover-public-url href="#" target="_blank" rel="noopener" aria-label="' . $this->_('Open publication URL') . '"></a><button type="button" class="RelayPopover__copy"'
            . ' data-popover-copy-url aria-label="' . $this->_('Copy publication URL') . '"><i class="fa fa-copy" aria-hidden="true"></i></button>'
            . '<span data-popover-no-url>' . $this->_('Unavailable') . '</span></dd></div></dl></section>'
            . '<section class="RelayPopover__section" aria-labelledby="RelayPopoverJob"><h4 id="RelayPopoverJob">' . $this->_('Relay action') . '</h4>'
            . '<dl><div><dt>' . $this->_('Job') . '</dt><dd>#<span data-popover-job-id></span> · ' . $this->_('Page') . ' #<span data-popover-page-id></span></dd></div><div><dt>'
            . $this->_('Requested by') . '</dt><dd data-popover-requester></dd></div><div><dt>'
            . $this->_('Run as') . '</dt><dd data-popover-actor></dd></div></dl></section>'
            . '</div><section class="RelayPopover__note" data-popover-note-row><h4>' . $this->_('Internal note') . '</h4><p data-popover-note></p></section></div>'
            . '<div class="RelayPopover__reschedule" data-popover-manage hidden><label>' . $this->_('Date and time')
            . '<input type="datetime-local" data-popover-datetime></label><button type="button" class="ui-button" data-popover-reschedule>'
            . '<i class="fa fa-arrows" aria-hidden="true"></i> ' . $this->_('Reschedule') . '</button></div>'
            . '<footer><a class="ui-button ui-priority-secondary" data-popover-view href="#" target="_blank" rel="noopener"><i class="fa fa-external-link" aria-hidden="true"></i> '
            . $this->_('View publication') . '</a><a class="ui-button ui-priority-secondary" data-popover-page href="#"><i class="fa fa-pencil" aria-hidden="true"></i> '
            . $this->_('Edit page') . '</a><button type="button" class="ui-button ui-priority-secondary RelayPopover__cancel" data-popover-cancel hidden>'
            . $this->_('Cancel action') . '</button></footer></div>';
    }

    private function renderCalendarJobTitle(array $job, string $class): string
    {
        $tag = $job['_url'] !== '' ? 'a' : 'span';
        $href = $tag === 'a' ? ' href="' . $this->wire('sanitizer')->entities($job['_url']) . '"' : '';
        return '<' . $tag . ' class="' . $class . '"' . $href . '>' . $this->wire('sanitizer')->entities($job['_title']) . '</' . $tag . '>';
    }
}
