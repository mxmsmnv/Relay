<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * ProcessWire admin routes for planning and mutating publication jobs.
 */
trait RelayAdminActionsTrait
{
    public function ___execute(): string
    {
        if ($this->wire('input')->requestMethod('POST')) {
            return match ((string) $this->wire('input')->post('relay_operation')) {
                'save' => $this->executeSave(),
                'reschedule' => $this->executeReschedule(),
                'cancel' => $this->executeCancel(),
                'run' => $this->executeRun(),
                'reset-imitation' => $this->executeResetImitation(),
                'seed-imitation' => $this->executeSeedImitation(),
                'squad-suggest' => $this->executeSquadSuggest(),
                default => $this->jsonEndpoint(static function (): array {
                    throw new Wire404Exception('Unknown schedule operation.');
                }),
            };
        }
        $this->enqueueAssets();
        $requestedView = (string)$this->wire('sanitizer')->option((string)$this->wire('input')->get('view'), ['month','week','quarter','three-day','kanban','timeline']);
        if ($requestedView === '') {
            $requestedView = in_array((string)$this->default_view, ['month','week','quarter','three-day','kanban','timeline'], true)
                ? (string)$this->default_view
                : 'month';
        }
        $viewTitles = [
            'month'=>$this->_('Month calendar'),
            'week'=>$this->_('Week calendar'),
            'quarter'=>$this->_('Quarter calendar'),
            'three-day'=>$this->_('3-day calendar'),
            'kanban'=>$this->_('Kanban'),
            'timeline'=>$this->_('Timeline'),
        ];
        $this->configureAdminChrome($this->_('Publishing schedule'), $viewTitles[$requestedView]);
        return $this->renderCalendar();
    }

    public function ___executeSave(): string
    {
        return $this->jsonEndpoint(function (): array {
            $this->requireManagePermission();
            $page = $this->editablePage((int) $this->wire('input')->post('page_id'));
            $action = (string) $this->wire('sanitizer')->option(
                $this->wire('input')->post('relay_action'),
                ['publish', 'unpublish', 'window']
            );
            if ($action === '') {
                throw new WireException($this->_('Choose publish or unpublish.'));
            }

            $timezone = (string) $this->wire('input')->post('timezone');
            RelayClock::assertTimezone($timezone);
            $local = trim((string) $this->wire('input')->post('scheduled_at'));
            $scheduledAt = RelayClock::localToUtc($local, $timezone);
            $this->assertSchedulingHorizon($scheduledAt);

            $runAsId = (int) $this->wire('input')->post('run_as_user_id');
            $runAs = $this->resolveRunAsUser($runAsId);

            if ($action === 'window') {
                $untilLocal = trim((string) $this->wire('input')->post('scheduled_until'));
                $unpublishAt = RelayClock::localToUtc($untilLocal, $timezone);
                $this->assertSchedulingHorizon($unpublishAt);
                if ($unpublishAt <= $scheduledAt) {
                    throw new WireException($this->_('The unpublish time must be after the publish time.'));
                }
                foreach (['publish', 'unpublish'] as $windowAction) {
                    $this->assertCanScheduleAction($page, $windowAction, $this->wire('user'));
                    $this->assertCanScheduleAction($page, $windowAction, $runAs);
                }
                if ($this->isImitationMode()) {
                    $ids = $this->imitationScheduleWindow(
                        (int) $page->id,
                        $scheduledAt,
                        $unpublishAt,
                        $timezone,
                        (int) $this->wire('user')->id,
                        (int) $runAs->id,
                        (string) $this->wire('input')->post('note')
                    );
                    return ['ok' => true, 'imitation' => true, 'message' => $this->_('Demo publication window created. No real data was added.'), 'job_ids' => $ids];
                }
                $ids = $this->store()->scheduleWindow(
                    (int) $page->id,
                    $scheduledAt,
                    $unpublishAt,
                    $timezone,
                    (int) $this->wire('user')->id,
                    (int) $runAs->id,
                    (string) $this->wire('input')->post('note')
                );
                $this->writeRelayLog("Scheduled publication window #{$ids['publish']}/#{$ids['unpublish']} for page {$page->id} by user {$this->wire('user')->id} as user {$runAs->id}");
                $this->notifyOperationalEvent('scheduled', $this->store()->get((int)$ids['publish']) ?: []);
                $this->notifyOperationalEvent('scheduled', $this->store()->get((int)$ids['unpublish']) ?: []);
                return ['ok' => true, 'message' => $this->_('Publication window scheduled.'), 'job_ids' => $ids];
            }

            $this->assertCanScheduleAction($page, $action, $this->wire('user'));
            $this->assertCanScheduleAction($page, $action, $runAs);

            if ($this->isImitationMode()) {
                $id = $this->imitationSchedule(
                    (int) $page->id,
                    $action,
                    $scheduledAt,
                    $timezone,
                    (int) $this->wire('user')->id,
                    (int) $runAs->id,
                    (string) $this->wire('input')->post('note')
                );
                return ['ok' => true, 'imitation' => true, 'message' => $this->_('Demo action created. No real data was added.'), 'job_id' => $id];
            }

            $id = $this->store()->schedule(
                (int) $page->id,
                $action,
                $scheduledAt,
                $timezone,
                (int) $this->wire('user')->id,
                (int) $runAs->id,
                (string) $this->wire('input')->post('note')
            );

            $this->writeRelayLog("Scheduled job #$id: $action page {$page->id} by user {$this->wire('user')->id} as user {$runAs->id}");
            $this->notifyOperationalEvent('scheduled', $this->store()->get($id) ?: []);
            return ['ok' => true, 'message' => $this->_('Relay saved.'), 'job_id' => $id];
        });
    }

    public function ___executeReschedule(): string
    {
        return $this->jsonEndpoint(function (): array {
            $this->requireManagePermission();
            $jobId = (int) $this->wire('input')->post('job_id');
            $job = $this->isImitationMode() ? $this->imitationGet($jobId) : $this->store()->get($jobId);
            if (!$job || $job['status'] !== 'scheduled') {
                throw new WireException($this->_('Only pending jobs can be rescheduled.'));
            }
            $page = $this->editablePage((int) $job['page_id']);
            $timezone = (string) $this->wire('input')->post('timezone');
            RelayClock::assertTimezone($timezone);
            $scheduledAt = RelayClock::localToUtc(trim((string) $this->wire('input')->post('scheduled_at')), $timezone);
            $this->assertSchedulingHorizon($scheduledAt);
            $runAs = $this->resolveRunAsUser((int) $this->wire('input')->post('run_as_user_id'));
            $this->assertCanScheduleAction($page, (string) $job['action'], $this->wire('user'));
            $this->assertCanScheduleAction($page, (string) $job['action'], $runAs);
            if ($this->isImitationMode()) {
                if (!$this->imitationReschedule(
                    (int) $job['id'],
                    $scheduledAt,
                    $timezone,
                    (int) $runAs->id,
                    (string) $this->wire('input')->post('note')
                )) {
                    throw new WireException($this->_('The demo action changed before it could be updated. Reload and try again.'));
                }
                return ['ok' => true, 'imitation' => true, 'message' => $this->_('Demo action updated. No real data was changed.'), 'job_id' => (int) $job['id']];
            }
            if (!$this->store()->reschedule(
                (int) $job['id'],
                $scheduledAt,
                $timezone,
                (int) $runAs->id,
                (string) $this->wire('input')->post('note')
            )) {
                throw new WireException($this->_('The schedule changed before it could be updated. Reload and try again.'));
            }
            $this->writeRelayLog("Rescheduled job #{$job['id']} by user {$this->wire('user')->id}");
            $this->notifyOperationalEvent('rescheduled', $this->store()->get((int)$job['id']) ?: $job);
            return ['ok' => true, 'message' => $this->_('Relay updated.'), 'job_id' => (int) $job['id']];
        });
    }

    public function ___executeCancel(): string
    {
        return $this->jsonEndpoint(function (): array {
            $this->requireManagePermission();
            $jobId = (int) $this->wire('input')->post('job_id');
            $job = $this->isImitationMode() ? $this->imitationGet($jobId) : $this->store()->get($jobId);
            if (!$job) {
                throw new Wire404Exception($this->_('Relay job was not found.'));
            }
            $this->editablePage((int) $job['page_id']);
            if ($this->isImitationMode()) {
                if (!$this->imitationCancel((int) $job['id'])) {
                    throw new WireException($this->_('Only pending demo actions can be cancelled.'));
                }
                return ['ok' => true, 'imitation' => true, 'message' => $this->_('Demo action cancelled. No real data was changed.')];
            }
            if (!$this->store()->cancel((int) $job['id'])) {
                throw new WireException($this->_('Only pending jobs can be cancelled.'));
            }
            $this->writeRelayLog("Cancelled job #{$job['id']} by user {$this->wire('user')->id}");
            $this->notifyOperationalEvent('cancelled', $this->store()->get((int)$job['id']) ?: $job);
            return ['ok' => true, 'message' => $this->_('Relay cancelled.')];
        });
    }

    public function ___executeRun(): string
    {
        return $this->jsonEndpoint(function (): array {
            if (!$this->wire('user')->hasPermission('relay-run')) {
                throw new WirePermissionException($this->_('You do not have permission to run scheduled jobs.'));
            }
            $result = $this->runDue((int) $this->max_batch, 'admin:user:' . (int) $this->wire('user')->id);
            return ['ok' => true, 'message' => sprintf($this->_('Processed %d job(s).'), $result['processed']), 'result' => $result];
        });
    }

    public function ___executeResetImitation(): string
    {
        return $this->jsonEndpoint(function (): array {
            $this->requireManagePermission();
            if (!$this->isImitationMode()) {
                throw new WireException($this->_('Imitation mode is not enabled.'));
            }
            $this->wire('session')->remove(self::IMITATION_SESSION_KEY);
            $this->wire('session')->set(self::IMITATION_SEEDED_SESSION_KEY, self::IMITATION_SEED_VERSION);
            $this->pageListScheduleCache = [];
            return ['ok' => true, 'imitation' => true, 'message' => $this->_('Demo sandbox cleared.')];
        });
    }

    public function ___executeSeedImitation(): string
    {
        return $this->jsonEndpoint(function (): array {
            $this->requireManagePermission();
            if (!$this->isImitationMode()) {
                throw new WireException($this->_('Imitation mode is not enabled.'));
            }
            $this->wire('session')->remove(self::IMITATION_SESSION_KEY);
            $this->wire('session')->remove(self::IMITATION_SEEDED_SESSION_KEY);
            $this->seedImitationJobs();
            $this->pageListScheduleCache = [];
            $count = count($this->imitationJobs());
            return ['ok' => true, 'imitation' => true, 'message' => sprintf($this->_n('%d demo action loaded.', '%d demo actions loaded.', $count), $count), 'count' => $count];
        });
    }
}
