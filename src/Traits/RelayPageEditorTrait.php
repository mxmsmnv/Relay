<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * Page editor tab, page-list marker and page-scoped schedule rendering.
 */
trait RelayPageEditorTrait
{
    public function hookPageEditTab(HookEvent $event): void
    {
        if (!$this->wire('user')->hasPermission('relay-view')) {
            return;
        }

        $input = $this->wire('input');
        foreach (['field', 'fields', 'field_id', 'file', 'filename', 'InputfieldFileAjax', 'InputfieldImageAjax'] as $key) {
            if ($input->get($key) !== null && $input->get($key) !== '') {
                return;
            }
        }

        $page = $event->object->getPage();
        if (!$page || !$page->id || (string) $page->template === 'admin' || !$this->templateAllowed($page)) {
            return;
        }

        $this->enqueueAssets();

        $form = $event->return;
        $tab = $this->wire(new InputfieldWrapper());
        $tab->attr('id+name', 'RelayTab');
        $tab->attr('title', $this->_('Relay'));
        $tab->addClass('WireTab');

        $markup = $this->wire('modules')->get('InputfieldMarkup');
        $markup->name = 'relay_panel';
        $markup->label = $this->_('Publication schedule');
        $markup->icon = 'calendar';
        $markup->value = $this->renderPagePanel($page);
        $tab->add($markup);
        // Keep Relay ahead of global fields injected outside ProcessWire tabs.
        $form->prepend($tab);
    }

    public function hookPageListLabel(HookEvent $event): void
    {
        if ((int) $this->show_page_tree_markers !== 1 || !$this->wire('user')->hasPermission('relay-view')) {
            return;
        }
        $page = $event->arguments(0);
        $options = (array) $event->arguments(1);
        if (!$page instanceof Page || !$page->id || (string) $page->template === 'admin' || !empty($options['noTags']) || !$this->templateAllowed($page)) {
            return;
        }
        $pageId = (int) $page->id;
        if (!array_key_exists($pageId, $this->pageListScheduleCache)) {
            $this->pageListScheduleCache[$pageId] = $this->isImitationMode()
                ? $this->imitationNextScheduledForPage($pageId)
                : $this->store()->nextScheduledForPage($pageId);
        }
        $job = $this->pageListScheduleCache[$pageId];
        if (!$job) {
            return;
        }
        $timezone = $this->configuredTimezone();
        $when = RelayClock::utcToLocal((string) $job['scheduled_at'], $timezone, 'M j, Y · H:i');
        $label = sprintf($this->_('%1$s on %2$s'), $this->actionLabel((string) $job['action']), $when);
        $icon = $job['action'] === 'unpublish' ? 'arrow-down' : 'arrow-up';
        $event->return .= ' <span class="RelayPageListMarker is-action-' . $this->wire('sanitizer')->name((string) $job['action'])
            . '" title="' . $this->wire('sanitizer')->entities($label) . '" aria-label="' . $this->wire('sanitizer')->entities($label)
            . '"><i class="fa fa-' . $icon . '" aria-hidden="true"></i><i class="fa fa-clock-o" aria-hidden="true"></i></span>';
    }

    private function renderPagePanel(Page $page): string
    {
        $sanitizer = $this->wire('sanitizer');
        $timezone = $this->configuredTimezone();
        $jobs = $this->isImitationMode()
            ? $this->imitationForPage((int) $page->id)
            : $this->store()->forPage((int) $page->id);
        $token = $this->wire('session')->CSRF->getToken();
        $canManage = $this->wire('user')->hasPermission('relay-manage') && $page->editable();
        $squadStatus = $canManage && (int)$this->enable_squad_assistance === 1 ? $this->squadIntegrationStatus() : null;
        $endpoint = $this->processUrl();
        $configuredDefaultTime = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $this->default_time)
            ? (string) $this->default_time
            : '09:00';
        $fallbackStart = new \DateTimeImmutable('tomorrow ' . $configuredDefaultTime, new \DateTimeZone($timezone));
        $ruleContext = $this->pageSchedulingRuleContext($page, $fallbackStart);
        $defaultStart = $ruleContext['start'];
        $defaultTime = $defaultStart->format('Y-m-d\TH:i');
        $defaultUntil = $ruleContext['until']->format('Y-m-d\TH:i');
        $defaultAction = $ruleContext['action'];
        $timePresets = $this->configuredTimePresets();

        $html = '<div class="RelayPanel" data-endpoint="' . $sanitizer->entities($endpoint) . '" data-token-name="'
            . $sanitizer->entities($token['name']) . '" data-token-value="' . $sanitizer->entities($token['value'])
            . '" data-request-failed="' . $sanitizer->entities($this->_('Request failed.'))
            . '" data-saving-label="' . $sanitizer->entities($this->_('Saving…'))
            . '" data-squad-loading-label="' . $sanitizer->entities($this->_('Squad is preparing a draft…'))
            . '" data-confirm-cancel="' . $sanitizer->entities($this->_('Cancel this scheduled action?'))
            . '" data-confirm-clear-imitation="' . $sanitizer->entities($this->_('Clear all demo actions from this session?')) . '">';
        $html .= '<div class="RelayPanel__toolbar"><p class="RelayPanel__intro">' . $this->_('Plan publication changes without adding fields to the page template. Times are displayed in')
            . ' <span class="RelayPanel__timezone">' . $sanitizer->entities($timezone) . '</span>.</p><a class="ui-button ui-priority-secondary" href="'
            . $sanitizer->entities($endpoint . '?page_id=' . (int) $page->id) . '"><i class="fa fa-calendar" aria-hidden="true"></i> '
            . $this->_('Open editorial calendar') . '</a></div>';
        if ($this->isImitationMode()) {
            $html .= $this->renderImitationBanner();
        }
        $html .= $this->renderPageOutcome($page, $jobs, $timezone);

        if ($canManage) {
            $html .= '<div class="RelayComposer" data-action="' . $sanitizer->entities($defaultAction) . '">';
            $html .= '<div class="RelayComposer__header"><span><i class="fa fa-calendar-plus-o" aria-hidden="true"></i></span><div><h3>'
                . $this->_('Plan a publication change') . '</h3><small>'
                . $this->_('Choose what should happen to the page, when it should happen, and who should perform it.') . '</small></div></div>';
            $html .= $this->renderPageSchedulingRulePicker($ruleContext, $page);
            $html .= '<label>' . $this->_('Publication action') . '<select data-relay-action><option value="publish"' . ($defaultAction === 'publish' ? ' selected' : '') . '>'
                . $this->_('Publish page — make publicly visible') . '</option><option value="unpublish"' . ($defaultAction === 'unpublish' ? ' selected' : '') . '>'
                . $this->_('Unpublish page — remove from public view') . '</option><option value="window"' . ($defaultAction === 'window' ? ' selected' : '') . '>'
                . $this->_('Publication window — publish, then unpublish') . '</option></select></label>';
            $html .= '<label><span data-relay-start-label data-label-publish="' . $sanitizer->entities($this->_('Publish date and time'))
                . '" data-label-unpublish="' . $sanitizer->entities($this->_('Unpublish date and time')) . '">' . $this->_('Publish date and time')
                . '</span><input type="datetime-local" data-scheduled-at value="'
                . $sanitizer->entities($defaultTime) . '" required></label>';
            $html .= '<label class="RelayComposer__until"' . ($defaultAction !== 'window' ? ' hidden' : '') . '>' . $this->_('Unpublish date and time') . '<input type="datetime-local" data-scheduled-until value="'
                . $sanitizer->entities($defaultUntil) . '"></label>';
            $html .= '<label>' . $this->_('Editorial identity') . '<select data-run-as-user>';
            foreach ($this->availableActors() as $actor) {
                $selected = $actor->id === $this->wire('user')->id ? ' selected' : '';
                $html .= '<option value="' . (int) $actor->id . '"' . $selected . '>' . $sanitizer->entities($actor->get('title|name')) . '</option>';
            }
            $html .= '</select></label>';
            $html .= '<aside class="RelayComposer__actionGuide" aria-live="polite"><i class="fa fa-arrow-up" aria-hidden="true"></i><div>'
                . '<span data-relay-action-guide="publish">' . $this->_('The page will become publicly visible at the selected date and time.') . '</span>'
                . '<span data-relay-action-guide="unpublish" hidden>' . $this->_('The page will be removed from public view at the selected date and time.') . '</span>'
                . '<span data-relay-action-guide="window" hidden>' . $this->_('The page will be published at the start time and automatically unpublished at the end time.') . '</span>'
                . '</div></aside>';
            if ($timePresets) {
                $html .= '<div class="RelayComposer__presets" role="group" aria-label="'
                    . $sanitizer->entities($this->_('Quick publication times')) . '"><span><i class="fa fa-clock-o" aria-hidden="true"></i> '
                    . $this->_('Publication times') . '</span><div>';
                foreach ($timePresets as $preset) {
                    $html .= '<button type="button" class="RelayTimePreset" data-relay-time-preset="'
                        . $sanitizer->entities($preset['time']) . '" aria-pressed="false" aria-label="'
                        . $sanitizer->entities(sprintf($this->_('Set time to %s'), $preset['time'])) . '"><span>'
                        . $sanitizer->entities($preset['label']) . '</span><time datetime="' . $sanitizer->entities($preset['time']) . '">'
                        . $sanitizer->entities($preset['time']) . '</time></button>';
                }
                $html .= '</div></div>';
            }
            $html .= '<label class="RelayComposer__note">' . $this->_('Internal note') . '<input type="text" maxlength="500" data-relay-note value="' . $sanitizer->entities($ruleContext['note']) . '"></label>';
            $html .= '<div class="RelayComposer__actions"><button type="button" class="ui-button ui-widget ui-corner-all" data-relay-save data-label-create="'
                . $sanitizer->entities($this->_('Relay')) . '" data-label-update="' . $sanitizer->entities($this->_('Update schedule')) . '">'
                . '<i class="fa fa-calendar-plus-o"></i> <span data-relay-save-label>' . $this->_('Relay') . '</span></button>'
                . ($squadStatus && $squadStatus['ready'] ? '<button type="button" class="ui-button ui-priority-secondary RelaySquadButton" data-relay-squad-suggest><i class="fa fa-magic" aria-hidden="true"></i> ' . $this->_('Suggest with Squad') . '</button>' : '')
                . '<button type="button" class="ui-button ui-priority-secondary" data-relay-edit-cancel hidden>' . $this->_('Cancel editing') . '</button>'
                . '<span role="status" data-relay-message></span></div>'
                . ($squadStatus && $squadStatus['ready'] ? '<div class="RelaySquadProposal" data-relay-squad-proposal hidden><span><i class="fa fa-magic" aria-hidden="true"></i> ' . $this->_('Squad proposal') . '</span><p data-relay-squad-rationale></p><small>' . $this->_('Review every field before scheduling. Nothing has been saved.') . '</small></div>' : '');
            $html .= '<input type="hidden" data-page-id value="' . (int) $page->id . '"><input type="hidden" data-timezone value="'
                . $sanitizer->entities($timezone) . '"><input type="hidden" data-edit-job value=""></div>';
        }

        $html .= $this->renderJobTable($jobs, $timezone, $canManage);
        return $html . '</div>';
    }

    private function renderPageOutcome(Page $page, array $jobs, string $timezone): string
    {
        $scheduled = array_values(array_filter($jobs, static fn(array $job): bool => $job['status'] === 'scheduled'));
        usort($scheduled, static fn(array $a, array $b): int => [$a['scheduled_at'], $a['id']] <=> [$b['scheduled_at'], $b['id']]);
        $published = !$page->hasStatus(Page::statusUnpublished);
        $state = $published ? $this->_('Published') : $this->_('Unpublished');
        $html = '<section class="RelayPageOutcome" data-state="' . ($published ? 'published' : 'unpublished') . '"><div class="RelayPageOutcome__current">'
            . '<span class="RelayPageOutcome__dot" aria-hidden="true"></span><div><small>' . $this->_('Current state') . '</small><strong>' . $state
            . '</strong></div></div><div class="RelayPageOutcome__future"><small>' . $this->_('Future changes') . '</small>';
        if (!$scheduled) {
            return $html . '<strong>' . $this->_('No scheduled changes') . '</strong><span>'
                . $this->_('Manual page status remains in effect.') . '</span></div></section>';
        }
        $html .= '<ol>';
        foreach ($scheduled as $job) {
            $localWhen = (new \DateTimeImmutable((string)$job['scheduled_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($timezone));
            $when = $this->formatCalendarDate($localWhen, 'M j, Y') . ' · ' . $localWhen->format('H:i');
            $html .= '<li class="RelayPageOutcome__action is-' . $this->wire('sanitizer')->name((string) $job['action']) . '"><i class="fa fa-arrow-'
                . ($job['action'] === 'publish' ? 'up' : 'down') . '" aria-hidden="true"></i><span><strong>'
                . $this->actionLabel((string) $job['action']) . '</strong><time datetime="' . $this->wire('sanitizer')->entities((string) $job['scheduled_at'])
                . '">' . $this->wire('sanitizer')->entities($when) . '</time></span></li>';
        }
        return $html . '</ol></div></section>';
    }

    private function settingsIcon(): string
    {
        return '<svg aria-hidden="true" fill="none" stroke-width="1.5" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">'
            . '<path d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.894.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.398.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.272-.806.108-1.204-.165-.397-.506-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z" stroke-linecap="round" stroke-linejoin="round"></path>'
            . '<path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" stroke-linecap="round" stroke-linejoin="round"></path></svg>';
    }

    private function renderJobTable(array $jobs, string $timezone, bool $canManage): string
    {
        $count = count($jobs);
        $header = '<header class="RelayJobs__header"><div><h3>' . $this->_('Page schedule') . '</h3><small>'
            . $this->_('Past and future publication actions for this page.') . '</small></div><span>'
            . sprintf($this->_n('%d action', '%d actions', $count), $count) . '</span></header>';
        if (!$jobs) {
            return '<section class="RelayJobsSection">' . $header . '<p class="RelayEmpty">'
                . $this->_('Nothing has been scheduled for this page yet.') . '</p></section>';
        }
        $sanitizer = $this->wire('sanitizer');
        $labels = [
            'when' => $this->_('When'), 'action' => $this->_('Action'), 'status' => $this->_('Status'),
            'requested' => $this->_('Requested by'), 'actor' => $this->_('Run as'), 'actions' => $this->_('Actions'),
        ];
        $html = '<section class="RelayJobsSection">' . $header . '<div class="RelayJobs"><table><thead><tr><th>' . $labels['when'] . '</th><th>' . $labels['action']
            . '</th><th>' . $labels['status'] . '</th><th>' . $labels['requested'] . '</th><th>' . $labels['actor'] . '</th><th>' . $labels['actions'] . '</th></tr></thead><tbody>';
        foreach ($jobs as $job) {
            $requester = $this->wire('users')->get((int) $job['requested_by_user_id']);
            $actor = $this->wire('users')->get((int) $job['run_as_user_id']);
            $html .= '<tr><td data-label="' . $sanitizer->entities($labels['when']) . '">' . $sanitizer->entities(RelayClock::utcToLocal((string) $job['scheduled_at'], $timezone)) . '</td>';
            $html .= '<td data-label="' . $sanitizer->entities($labels['action']) . '">' . $this->actionLabel((string) $job['action']) . '</td><td data-label="' . $sanitizer->entities($labels['status']) . '"><span class="RelayBadge is-'
                . $sanitizer->name($job['status']) . '">' . $this->statusLabel((string) $job['status']) . '</span></td>';
            $html .= '<td data-label="' . $sanitizer->entities($labels['requested']) . '">' . $sanitizer->entities($requester->id ? $requester->get('title|name') : '#' . $job['requested_by_user_id']) . '</td>';
            $html .= '<td data-label="' . $sanitizer->entities($labels['actor']) . '">' . $sanitizer->entities($actor->id ? $actor->get('title|name') : '#' . $job['run_as_user_id']) . '</td><td data-label="' . $sanitizer->entities($labels['actions']) . '">';
            if ($canManage && $job['status'] === 'scheduled') {
                $editTime = RelayClock::utcToLocal((string) $job['scheduled_at'], $timezone, 'Y-m-d\TH:i');
                $html .= '<span class="RelayJobActions"><button type="button" class="ui-button ui-priority-secondary" data-relay-edit="' . (int) $job['id']
                    . '" data-action="' . $sanitizer->name((string) $job['action']) . '" data-time="' . $sanitizer->entities($editTime)
                    . '" data-run-as="' . (int) $job['run_as_user_id'] . '" data-note="' . $sanitizer->entities((string) $job['note']) . '">'
                    . '<i class="fa fa-pencil" aria-hidden="true"></i> ' . $this->_('Edit') . '</button>'
                    . '<button type="button" class="ui-button ui-priority-secondary" data-relay-cancel="' . (int) $job['id'] . '">' . $this->_('Cancel') . '</button></span>';
            }
            $html .= '</td></tr>';
        }
        return $html . '</tbody></table></div></section>';
    }
}
