<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * Session-only demonstration jobs and imitation worker behavior.
 */
trait RelayImitationTrait
{
    private function isImitationMode(): bool
    {
        return (int) $this->imitation_mode === 1;
    }

    private function imitationJobs(): array
    {
        $jobs = $this->wire('session')->get(self::IMITATION_SESSION_KEY);
        if (!is_array($jobs)) $jobs = [];
        $jobs = array_values(array_filter($jobs, static fn($job): bool => is_array($job) && isset($job['id'], $job['page_id'], $job['action'], $job['scheduled_at'], $job['status'])));
        $seedVersion = (int)$this->wire('session')->get(self::IMITATION_SEEDED_SESSION_KEY);
        if ($this->isImitationMode() && $seedVersion < self::IMITATION_SEED_VERSION) {
            $this->seedImitationJobs();
            $jobs = $this->wire('session')->get(self::IMITATION_SESSION_KEY);
            if (!is_array($jobs)) $jobs = [];
        }
        return array_values(array_filter($jobs, static fn($job): bool => is_array($job) && isset($job['id'], $job['page_id'], $job['action'], $job['scheduled_at'], $job['status'])));
    }

/**
     * Populate a new imitation session without creating jobs or changing pages.
     */
    private function seedImitationJobs(): void
    {
        $session = $this->wire('session');
        $session->set(self::IMITATION_SEEDED_SESSION_KEY, self::IMITATION_SEED_VERSION);

        $demoPages = [];
        foreach ($this->wire('pages')->find('include=all, status<' . Page::statusTrash . ', sort=-modified, limit=500') as $page) {
            if (!$page->id || (int)$page->id === 1 || (string)$page->template === 'admin') continue;
            if (((int)$page->template->flags & Template::flagSystem) || !$page->editable() || !$this->templateAllowed($page)) continue;
            $demoPages[] = (int)$page->id;
            if (count($demoPages) >= 7) break;
        }
        if ($demoPages === []) return;

        $timezone = $this->configuredTimezone();
        $zone = new \DateTimeZone($timezone);
        $nowLocal = new \DateTimeImmutable('now', $zone);
        $today = $nowLocal->setTime(0, 0);
        $actorId = (int)$this->wire('user')->id;
        $createdAt = gmdate('Y-m-d H:i:s');
        $specs = [
            ['publish', 'completed', $today->modify('-18 days')->setTime(8, 30), $this->_('Demo: early campaign publication completed')],
            ['unpublish', 'completed', $today->modify('-15 days')->setTime(21, 0), $this->_('Demo: campaign closed on schedule')],
            ['publish', 'failed', $today->modify('-13 days')->setTime(10, 15), $this->_('Demo: publication failed after retries')],
            ['publish', 'cancelled', $today->modify('-11 days')->setTime(9, 0), $this->_('Demo: editorial launch was cancelled')],
            ['publish', 'completed', $today->modify('-9 days')->setTime(13, 45), $this->_('Demo: article published successfully')],
            ['unpublish', 'superseded', $today->modify('-7 days')->setTime(18, 0), $this->_('Demo: older unpublication plan was replaced')],
            ['unpublish', 'completed', $today->modify('-6 days')->setTime(20, 30), $this->_('Demo: expired publication removed')],
            ['unpublish', 'failed', $today->modify('-4 days')->setTime(17, 20), $this->_('Demo: failed action ready for review')],
            ['publish', 'completed', $today->modify('-3 days')->setTime(11, 10), $this->_('Demo: scheduled release completed')],
            ['publish', 'cancelled', $today->modify('-2 days')->setTime(15, 0), $this->_('Demo: duplicate launch cancelled')],
            ['publish', 'scheduled', $nowLocal->modify('-2 hours'), $this->_('Demo: due action for testing the worker')],
            ['publish', 'completed', $today->setTime(8, 0), $this->_('Demo: morning publication completed')],
            ['publish', 'scheduled', $today->setTime(9, 24), $this->_('Demo: primary publication slot')],
            ['unpublish', 'processing', $today->setTime(10, 30), $this->_('Demo: action currently being processed')],
            ['publish', 'failed', $today->setTime(12, 0), $this->_('Demo: midday failure awaiting review')],
            ['publish', 'scheduled', $today->setTime(14, 15), $this->_('Demo: afternoon editorial release')],
            ['unpublish', 'scheduled', $today->setTime(16, 45), $this->_('Demo: timed content expiry')],
            ['publish', 'cancelled', $today->setTime(19, 0), $this->_('Demo: evening launch cancelled')],
            ['publish', 'scheduled', $today->modify('+1 day')->setTime(8, 30), $this->_('Demo: morning product announcement')],
            ['publish', 'scheduled', $today->modify('+1 day')->setTime(10, 0), $this->_('Demo: knowledge-base update')],
            ['unpublish', 'scheduled', $today->modify('+1 day')->setTime(12, 15), $this->_('Demo: temporary page expiry')],
            ['publish', 'processing', $today->modify('+1 day')->setTime(14, 30), $this->_('Demo: queued editorial processing')],
            ['unpublish', 'superseded', $today->modify('+1 day')->setTime(17, 0), $this->_('Demo: replaced end-of-day plan')],
            ['publish', 'scheduled', $today->modify('+1 day')->setTime(20, 0), $this->_('Demo: evening feature release')],
            ['publish', 'scheduled', $today->modify('+2 days')->setTime(9, 0), $this->_('Demo: weekend publication')],
            ['unpublish', 'scheduled', $today->modify('+2 days')->setTime(11, 30), $this->_('Demo: short-lived notice expiry')],
            ['publish', 'scheduled', $today->modify('+2 days')->setTime(15, 0), $this->_('Demo: partner announcement')],
            ['publish', 'cancelled', $today->modify('+2 days')->setTime(18, 30), $this->_('Demo: cancelled weekend release')],
            ['publish', 'scheduled', $today->modify('+3 days')->setTime(8, 45), $this->_('Demo: weekly editorial opening')],
            ['unpublish', 'scheduled', $today->modify('+3 days')->setTime(13, 0), $this->_('Demo: planned unpublication')],
            ['publish', 'scheduled', $today->modify('+3 days')->setTime(17, 30), $this->_('Demo: late editorial release')],
            ['publish', 'scheduled', $today->modify('+5 days')->setTime(10, 30), $this->_('Demo: campaign landing page')],
            ['unpublish', 'scheduled', $today->modify('+7 days')->setTime(19, 0), $this->_('Demo: campaign closing action')],
            ['publish', 'scheduled', $today->modify('+10 days')->setTime(9, 30), $this->_('Demo: long-range publication plan')],
            ['publish', 'scheduled', $today->modify('+14 days')->setTime(12, 0), $this->_('Demo: fortnight editorial milestone')],
            ['unpublish', 'scheduled', $today->modify('+18 days')->setTime(18, 0), $this->_('Demo: future content retirement')],
            ['publish', 'scheduled', $today->modify('+24 days')->setTime(11, 0), $this->_('Demo: next-month preview release')],
            ['publish', 'scheduled', $today->modify('+31 days')->setTime(9, 0), $this->_('Demo: next-month opening slot')],
            ['unpublish', 'scheduled', $today->modify('+40 days')->setTime(20, 0), $this->_('Demo: quarterly archive plan')],
        ];
        $jobs = [];
        foreach ($specs as $index => [$action, $status, $scheduledLocal, $note]) {
            $scheduledAt = $scheduledLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $jobs[] = [
                'id' => -($index + 1),
                'page_id' => $demoPages[$index % count($demoPages)],
                'action' => $action,
                'scheduled_at' => $scheduledAt,
                'timezone' => $timezone,
                'status' => $status,
                'requested_by_user_id' => $actorId,
                'run_as_user_id' => $actorId,
                'executor' => $status === 'completed' ? 'imitation:demo-worker' : '',
                'attempts' => $status === 'failed' ? 3 : ($status === 'completed' ? 1 : 0),
                'lock_token' => $status === 'processing' ? 'imitation-demo' : null,
                'locked_at' => $status === 'processing' ? $createdAt : null,
                'completed_at' => $status === 'completed' ? $scheduledAt : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'note' => $note,
                'last_error' => $status === 'failed' ? $this->_('Demo failure: retry limit reached') : null,
            ];
        }
        $this->saveImitationJobs($jobs);
    }

    private function saveImitationJobs(array $jobs): void
    {
        $this->wire('session')->set(self::IMITATION_SESSION_KEY, array_values($jobs));
        $this->pageListScheduleCache = [];
    }

    private function imitationSchedule(
        int $pageId,
        string $action,
        \DateTimeImmutable $scheduledAtUtc,
        string $timezone,
        int $requestedBy,
        int $runAs,
        string $note = ''
    ): int {
        if (!in_array($action, ['publish', 'unpublish'], true)) {
            throw new \InvalidArgumentException('Unsupported schedule action.');
        }
        $jobs = $this->imitationJobs();
        $now = gmdate('Y-m-d H:i:s');
        $nextId = -1;
        foreach ($jobs as &$job) {
            $nextId = min($nextId, (int) $job['id'] - 1);
            if ((int) $job['page_id'] === $pageId && $job['action'] === $action && $job['status'] === 'scheduled') {
                $job['status'] = 'superseded';
                $job['updated_at'] = $now;
            }
        }
        unset($job);
        $jobs[] = [
            'id' => $nextId,
            'page_id' => $pageId,
            'action' => $action,
            'scheduled_at' => $scheduledAtUtc->format('Y-m-d H:i:s'),
            'timezone' => $timezone,
            'status' => 'scheduled',
            'requested_by_user_id' => $requestedBy,
            'run_as_user_id' => $runAs,
            'executor' => '',
            'attempts' => 0,
            'lock_token' => null,
            'locked_at' => null,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'note' => mb_substr(trim($note), 0, 500),
            'last_error' => null,
        ];
        $this->saveImitationJobs($jobs);
        return $nextId;
    }

    private function imitationScheduleWindow(
        int $pageId,
        \DateTimeImmutable $publishAtUtc,
        \DateTimeImmutable $unpublishAtUtc,
        string $timezone,
        int $requestedBy,
        int $runAs,
        string $note = ''
    ): array {
        return [
            'publish' => $this->imitationSchedule($pageId, 'publish', $publishAtUtc, $timezone, $requestedBy, $runAs, $note),
            'unpublish' => $this->imitationSchedule($pageId, 'unpublish', $unpublishAtUtc, $timezone, $requestedBy, $runAs, $note),
        ];
    }

    private function imitationGet(int $id): ?array
    {
        foreach ($this->imitationJobs() as $job) {
            if ((int) $job['id'] === $id) {
                return $job;
            }
        }
        return null;
    }

    private function imitationForPage(int $pageId, int $limit = 30): array
    {
        $jobs = array_values(array_filter($this->imitationJobs(), static fn(array $job): bool => (int) $job['page_id'] === $pageId));
        usort($jobs, static fn(array $a, array $b): int => [$b['scheduled_at'], $b['id']] <=> [$a['scheduled_at'], $a['id']]);
        return array_slice($jobs, 0, max(1, min(100, $limit)));
    }

    private function imitationNextScheduledForPage(int $pageId): ?array
    {
        $jobs = array_values(array_filter(
            $this->imitationJobs(),
            static fn(array $job): bool => (int) $job['page_id'] === $pageId && $job['status'] === 'scheduled'
        ));
        usort($jobs, static fn(array $a, array $b): int => [$a['scheduled_at'], $a['id']] <=> [$b['scheduled_at'], $b['id']]);
        return $jobs[0] ?? null;
    }

    private function imitationReschedule(int $id, \DateTimeImmutable $scheduledAtUtc, string $timezone, int $runAs, string $note): bool
    {
        $jobs = $this->imitationJobs();
        foreach ($jobs as &$job) {
            if ((int) $job['id'] !== $id || $job['status'] !== 'scheduled') {
                continue;
            }
            $job['scheduled_at'] = $scheduledAtUtc->format('Y-m-d H:i:s');
            $job['timezone'] = $timezone;
            $job['run_as_user_id'] = $runAs;
            $job['note'] = mb_substr(trim($note), 0, 500);
            $job['updated_at'] = gmdate('Y-m-d H:i:s');
            unset($job);
            $this->saveImitationJobs($jobs);
            return true;
        }
        unset($job);
        return false;
    }

    private function imitationCancel(int $id): bool
    {
        $jobs = $this->imitationJobs();
        foreach ($jobs as &$job) {
            if ((int) $job['id'] !== $id || $job['status'] !== 'scheduled') {
                continue;
            }
            $job['status'] = 'cancelled';
            $job['updated_at'] = gmdate('Y-m-d H:i:s');
            unset($job);
            $this->saveImitationJobs($jobs);
            return true;
        }
        unset($job);
        return false;
    }

    private function imitationBetween(
        \DateTimeImmutable $fromUtc,
        \DateTimeImmutable $toUtc,
        int $limit,
        ?int $pageId = null,
        ?string $status = null,
        ?string $action = null,
        ?string $templateName = null
    ): array {
        $from = $fromUtc->format('Y-m-d H:i:s');
        $to = $toUtc->format('Y-m-d H:i:s');
        $pageTemplates = [];
        $jobs = array_values(array_filter($this->imitationJobs(), function (array $job) use ($from, $to, $pageId, $status, $action, $templateName, &$pageTemplates): bool {
            $jobPageId = (int) $job['page_id'];
            if ($templateName !== null && $templateName !== '') {
                if (!array_key_exists($jobPageId, $pageTemplates)) {
                    $page = $this->wire('pages')->get($jobPageId);
                    $pageTemplates[$jobPageId] = $page->id ? (string) $page->template->name : '';
                    if ($page->id) $this->wire('pages')->uncache($page);
                }
                if ($pageTemplates[$jobPageId] !== $templateName) return false;
            }
            return $job['scheduled_at'] >= $from
                && $job['scheduled_at'] < $to
                && ($pageId === null || $jobPageId === $pageId)
                && ($status === null || $status === '' || $job['status'] === $status)
                && ($action === null || $action === '' || $job['action'] === $action);
        }));
        usort($jobs, static fn(array $a, array $b): int => [$a['scheduled_at'], $a['id']] <=> [$b['scheduled_at'], $b['id']]);
        return array_slice($jobs, 0, max(1, min(5000, $limit)));
    }

    private function imitationCounts(): array
    {
        $counts = [];
        foreach ($this->imitationJobs() as $job) {
            $status = (string) $job['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        return $counts;
    }

    private function imitationRunDue(int $limit, string $executor): array
    {
        $jobs = $this->imitationJobs();
        $now = gmdate('Y-m-d H:i:s');
        $processed = 0;
        foreach ($jobs as &$job) {
            if ($processed >= $limit || $job['status'] !== 'scheduled' || $job['scheduled_at'] > $now) {
                continue;
            }
            $job['status'] = 'completed';
            $job['attempts'] = (int) $job['attempts'] + 1;
            $job['executor'] = mb_substr($executor, 0, 190);
            $job['completed_at'] = $now;
            $job['updated_at'] = $now;
            $processed++;
        }
        unset($job);
        if ($processed > 0) {
            $this->saveImitationJobs($jobs);
        }
        return ['claimed' => $processed, 'completed' => $processed, 'failed' => 0, 'processed' => $processed, 'imitation' => true];
    }

    private function renderImitationBanner(): string
    {
        $count = count($this->imitationJobs());
        $empty = $count === 0;
        $canManage = $this->wire('user')->hasPermission('relay-manage');
        return '<aside class="RelayImitationBanner" role="status"><span class="RelayImitationBanner__icon"><i class="fa fa-flask" aria-hidden="true"></i></span>'
            . '<span><strong>' . ($empty ? $this->_('Imitation mode · Empty') : $this->_('Imitation mode')) . '</strong><small>'
            . ($empty
                ? $this->_('Load the dense demo dataset to test calendars, filters, drag-and-drop, popovers, and worker behavior.')
                : sprintf($this->_n('%d session-only demo action. No schedule jobs or page states are changed.', '%d session-only demo actions. No schedule jobs or page states are changed.', $count), $count))
            . '</small></span>'
            . ($canManage ? '<button type="button" class="ui-button ui-priority-secondary" '
                . ($empty ? 'data-relay-seed-imitation>' . $this->_('Load demo data') : 'data-relay-reset-imitation>' . $this->_('Clear demo data'))
                . '</button>' : '')
            . '</aside>';
    }
}
