<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * Scheduling boundary, publication windows and bounded worker execution.
 */
trait RelayWorkerTrait
{
    public function runDue(?int $limit = null, ?string $executor = null): array
    {
        $limit = max(1, min((int) $this->max_batch, $limit ?: (int) $this->max_batch));
        $executor = $executor ?: 'cli:' . php_uname('n') . ':' . getmypid();
        if ($this->isImitationMode()) {
            return $this->imitationRunDue($limit, $executor);
        }
        $result = ['claimed' => 0, 'completed' => 0, 'failed' => 0, 'processed' => 0];
        $jobs = $this->store()->claimDue($limit, (int) $this->stale_minutes);
        $result['claimed'] = count($jobs);

        foreach ($jobs as $job) {
            try {
                $changed = $this->executeJob($job);
                $this->store()->complete((int) $job['id'], (string) $job['lock_token'], $executor);
                $result['completed']++;
                $this->writeRelayLog("Completed job #{$job['id']} by $executor");
                $completedJob = $this->store()->get((int)$job['id']) ?: $job;
                if ($changed && (string)$job['action'] === 'publish') $this->notifyOperationalEvent('published', $completedJob);
                $this->notifyOperationalEvent('completed', $completedJob);
            } catch (\Throwable $e) {
                $this->store()->fail(
                    (int) $job['id'],
                    (string) $job['lock_token'],
                    $executor,
                    $e->getMessage(),
                    (int) $this->max_attempts
                );
                $result['failed']++;
                $this->writeRelayLog("Failed job #{$job['id']} by $executor: {$e->getMessage()}");
                $this->notifyOperationalEvent('failed', $this->store()->get((int)$job['id']) ?: $job);
            } finally {
                $result['processed']++;
                $this->wire('pages')->uncache((int) $job['page_id']);
            }
        }

        return $result;
    }

    public function scheduleAction(
        Page $page,
        string $action,
        \DateTimeImmutable $when,
        ?User $runAs = null,
        string $note = ''
    ): int {
        $this->requireManagePermission();
        $page = $this->editablePage((int) $page->id);
        if (!in_array($action, ['publish', 'unpublish'], true)) {
            throw new WireException($this->_('Choose publish or unpublish.'));
        }
        $actor = $this->resolveApiRunAsUser($runAs);
        $this->assertCanScheduleAction($page, $action, $this->wire('user'));
        $this->assertCanScheduleAction($page, $action, $actor);
        $timezone = RelayClock::assertTimezone($when->getTimezone()->getName());
        $whenUtc = $when->setTimezone(new \DateTimeZone('UTC'));
        $this->assertSchedulingHorizon($whenUtc);
        if ($this->isImitationMode()) {
            return $this->imitationSchedule(
                (int) $page->id,
                $action,
                $whenUtc,
                $timezone,
                (int) $this->wire('user')->id,
                (int) $actor->id,
                $note
            );
        }
        $id = $this->store()->schedule(
            (int) $page->id,
            $action,
            $whenUtc,
            $timezone,
            (int) $this->wire('user')->id,
            (int) $actor->id,
            $note
        );
        $this->writeRelayLog("Scheduled job #$id through API: $action page {$page->id} by user {$this->wire('user')->id} as user {$actor->id}");
        $this->notifyOperationalEvent('scheduled', $this->store()->get($id) ?: []);
        return $id;
    }

    public function schedulePublicationWindow(
        Page $page,
        \DateTimeImmutable $publishAt,
        \DateTimeImmutable $unpublishAt,
        ?User $runAs = null,
        string $note = ''
    ): array {
        $this->requireManagePermission();
        $page = $this->editablePage((int) $page->id);
        $actor = $this->resolveApiRunAsUser($runAs);
        foreach (['publish', 'unpublish'] as $action) {
            $this->assertCanScheduleAction($page, $action, $this->wire('user'));
            $this->assertCanScheduleAction($page, $action, $actor);
        }
        $timezone = RelayClock::assertTimezone($publishAt->getTimezone()->getName());
        if ($unpublishAt->getTimezone()->getName() !== $timezone) {
            throw new WireException($this->_('Publication window dates must use the same timezone.'));
        }
        $publishAtUtc = $publishAt->setTimezone(new \DateTimeZone('UTC'));
        $unpublishAtUtc = $unpublishAt->setTimezone(new \DateTimeZone('UTC'));
        $this->assertSchedulingHorizon($publishAtUtc);
        $this->assertSchedulingHorizon($unpublishAtUtc);
        if ($unpublishAtUtc <= $publishAtUtc) {
            throw new WireException($this->_('The unpublish time must be after the publish time.'));
        }
        if ($this->isImitationMode()) {
            return $this->imitationScheduleWindow(
                (int) $page->id,
                $publishAtUtc,
                $unpublishAtUtc,
                $timezone,
                (int) $this->wire('user')->id,
                (int) $actor->id,
                $note
            );
        }
        $ids = $this->store()->scheduleWindow(
            (int) $page->id,
            $publishAtUtc,
            $unpublishAtUtc,
            $timezone,
            (int) $this->wire('user')->id,
            (int) $actor->id,
            $note
        );
        $this->writeRelayLog("Scheduled publication window #{$ids['publish']}/#{$ids['unpublish']} through API for page {$page->id} by user {$this->wire('user')->id} as user {$actor->id}");
        $this->notifyOperationalEvent('scheduled', $this->store()->get((int)$ids['publish']) ?: []);
        $this->notifyOperationalEvent('scheduled', $this->store()->get((int)$ids['unpublish']) ?: []);
        return $ids;
    }

    private function executeJob(array $job): bool
    {
        $page = $this->wire('pages')->get((int) $job['page_id']);
        if (!$page->id || $page->isTrash()) {
            throw new WireException('Target page is missing or trashed.');
        }

        $actor = $this->wire('users')->get((int) $job['run_as_user_id']);
        if (!$actor->id || $actor->isGuest() || $actor->isUnpublished()) {
            throw new WireException('Editorial identity is missing or disabled.');
        }

        $users = $this->wire('users');
        $previous = $this->wire('user');
        $users->setCurrentUser($actor);

        try {
            $action = (string) $job['action'];
            $this->assertCanScheduleAction($page, $action, $actor);
            $page->of(false);
            if ($action === 'publish') {
                if (!$page->hasStatus(Page::statusUnpublished)) {
                    return false;
                }
                $page->removeStatus(Page::statusUnpublished);
            } else {
                if ($page->hasStatus(Page::statusUnpublished)) {
                    return false;
                }
                $page->addStatus(Page::statusUnpublished);
            }
            $this->wire('pages')->save($page);
            return true;
        } finally {
            $users->setCurrentUser($previous);
        }
    }
}
