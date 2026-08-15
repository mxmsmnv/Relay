<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * Permission-gated PHP operational facade backing methods and DTOs.
 */
trait RelayOperationalTrait
{
    public function api(?User $actor = null, string $channel = 'php_api'): RelayAgentApi
    {
        return new RelayAgentApi($this, $actor ?: $this->wire('user'), $channel);
    }

    public function capabilities(): array
    {
        $telegram = $this->telegramIntegrationStatus();
        $mail = $this->mailIntegrationStatus();
        $squad = $this->squadIntegrationStatus();
        $channels = [
            'php_api' => (int)$this->enable_agent_api === 1,
            'rest' => (int)$this->enable_agent_api === 1 && (int)$this->enable_rest_api === 1,
            'cli' => (int)$this->enable_interface_cli === 1,
            'email' => (bool)$mail['ready'],
            'telegram' => (bool)$telegram['ready'],
            'squad' => (bool)$squad['ready'],
            'bearer' => (int)$this->enable_agent_api === 1 && (int)$this->enable_rest_api === 1 && trim((string)$this->rest_bearer_token_hash) !== '' && (int)$this->rest_bearer_user_id > 0,
        ];
        $available = $channels['php_api'] || $channels['rest'] || $channels['cli'];
        return [
            'provider' => 'Relay', 'version' => '1.0.0', 'api_version' => self::REST_API_VERSION,
            'module_version' => self::VERSION, 'imitation' => $this->isImitationMode(), 'channels' => $channels,
            'capabilities' => [
                ['name' => 'relay.jobs.read', 'version' => '1.0.0', 'enabled' => $available],
                ['name' => 'relay.jobs.create', 'version' => '1.0.0', 'enabled' => $available],
                ['name' => 'relay.jobs.reschedule', 'version' => '1.0.0', 'enabled' => $available],
                ['name' => 'relay.jobs.cancel', 'version' => '1.0.0', 'enabled' => $available],
                ['name' => 'relay.worker.run', 'version' => '1.0.0', 'enabled' => $available],
                ['name' => 'relay.notifications.email', 'version' => '1.0.0', 'enabled' => $channels['email']],
                ['name' => 'relay.notifications.telegram', 'version' => '1.0.0', 'enabled' => $channels['telegram']],
                ['name' => 'relay.planning.squad', 'version' => '1.0.0', 'enabled' => $channels['squad']],
            ],
        ];
    }

    public function operationalCounts(User $actor): array
    {
        $this->assertInterfaceActor($actor);
        return $this->isImitationMode() ? $this->imitationCounts() : $this->store()->counts();
    }

    public function operationalJobs(User $actor, array $filters = []): array
    {
        $this->assertInterfaceActor($actor);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $from = $this->interfaceDate((string)($filters['from'] ?? ''), $now->modify('-30 days'));
        $to = $this->interfaceDate((string)($filters['to'] ?? ''), $now->modify('+1 year'));
        if ($to <= $from) throw new \InvalidArgumentException('The to date must be after from.');
        $limit = max(1, min(500, (int)($filters['limit'] ?? 100)));
        $pageId = max(0, (int)($filters['page_id'] ?? 0));
        $status = (string)$this->wire('sanitizer')->option((string)($filters['status'] ?? ''), ['scheduled','processing','completed','failed','cancelled','superseded']);
        $action = (string)$this->wire('sanitizer')->option((string)($filters['action'] ?? ''), ['publish','unpublish']);
        $jobs = $this->isImitationMode()
            ? $this->imitationBetween($from, $to, $limit, $pageId ?: null, $status ?: null, $action ?: null)
            : $this->store()->between($from, $to, $limit, $pageId ?: null, $status ?: null, $action ?: null);
        return array_map(fn(array $job): array => $this->operationalJobDto($job), $jobs);
    }

    public function operationalJob(User $actor, int $id): array
    {
        $this->assertInterfaceActor($actor);
        $job = $this->isImitationMode() ? $this->imitationGet($id) : $this->store()->get($id);
        if (!$job) throw new Wire404Exception('Relay job was not found.');
        return $this->operationalJobDto($job);
    }

    public function operationalSchedule(User $actor, array $data): array
    {
        $this->assertInterfaceActor($actor);
        return $this->withInterfaceActor($actor, function() use ($data): array {
            $page = $this->wire('pages')->get((int)($data['page_id'] ?? 0));
            $timezone = RelayClock::assertTimezone((string)($data['timezone'] ?? $this->configuredTimezone()));
            $when = new \DateTimeImmutable((string)($data['scheduled_at'] ?? ''), new \DateTimeZone($timezone));
            $runAs = !empty($data['run_as_user_id']) ? $this->wire('users')->get((int)$data['run_as_user_id']) : null;
            if (($data['action'] ?? '') === 'window') {
                $until = new \DateTimeImmutable((string)($data['scheduled_until'] ?? ''), new \DateTimeZone($timezone));
                return $this->schedulePublicationWindow($page, $when, $until, $runAs, (string)($data['note'] ?? ''));
            }
            return ['job_id' => $this->scheduleAction($page, (string)($data['action'] ?? ''), $when, $runAs, (string)($data['note'] ?? ''))];
        });
    }

    public function operationalReschedule(User $actor, int $id, array $data): array
    {
        $this->assertInterfaceActor($actor);
        return $this->withInterfaceActor($actor, function() use ($id, $data): array {
            $job = $this->isImitationMode() ? $this->imitationGet($id) : $this->store()->get($id);
            if (!$job || $job['status'] !== 'scheduled') throw new WireException('Only pending jobs can be rescheduled.');
            $page = $this->editablePage((int)$job['page_id']);
            $timezone = RelayClock::assertTimezone((string)($data['timezone'] ?? $job['timezone']));
            $scheduledAt = RelayClock::localToUtc((string)($data['scheduled_at'] ?? ''), $timezone);
            $this->assertSchedulingHorizon($scheduledAt);
            $runAs = $this->resolveRunAsUser((int)($data['run_as_user_id'] ?? $job['run_as_user_id']));
            $this->assertCanScheduleAction($page, (string)$job['action'], $actor);
            $this->assertCanScheduleAction($page, (string)$job['action'], $runAs);
            $ok = $this->isImitationMode()
                ? $this->imitationReschedule($id, $scheduledAt, $timezone, (int)$runAs->id, (string)($data['note'] ?? $job['note']))
                : $this->store()->reschedule($id, $scheduledAt, $timezone, (int)$runAs->id, (string)($data['note'] ?? $job['note']));
            if (!$ok) throw new WireException('The schedule changed before it could be updated.');
            if (!$this->isImitationMode()) $this->notifyOperationalEvent('rescheduled', $this->store()->get($id) ?: $job);
            return $this->operationalJob($actor, $id);
        });
    }

    public function operationalCancel(User $actor, int $id): array
    {
        $this->assertInterfaceActor($actor);
        return $this->withInterfaceActor($actor, function() use ($actor, $id): array {
            $job = $this->isImitationMode() ? $this->imitationGet($id) : $this->store()->get($id);
            if (!$job) throw new Wire404Exception('Relay job was not found.');
            $this->editablePage((int)$job['page_id']);
            $ok = $this->isImitationMode() ? $this->imitationCancel($id) : $this->store()->cancel($id);
            if (!$ok) throw new WireException('Only pending jobs can be cancelled.');
            if (!$this->isImitationMode()) $this->notifyOperationalEvent('cancelled', $this->store()->get($id) ?: $job);
            return $this->operationalJob($actor, $id);
        });
    }

    public function operationalRunDue(User $actor, ?int $limit = null): array
    {
        $this->assertInterfaceActor($actor);
        if (!$actor->isSuperuser() && !$actor->hasPermission('relay-run')) throw new WirePermissionException('Relay worker access denied.');
        return $this->withInterfaceActor($actor, fn(): array => $this->runDue($limit, 'interface:user:' . (int)$actor->id));
    }

    private function assertInterfaceActor(User $actor): void
    {
        if (!$actor->id || (!$actor->isSuperuser() && (!$actor->hasPermission('relay-api') || !$actor->hasPermission('relay-manage')))) {
            throw new WirePermissionException('Relay API access denied.');
        }
    }

    private function withInterfaceActor(User $actor, callable $callback): mixed
    {
        $users = $this->wire('users');
        $previous = $this->wire('user');
        if ((int)$previous->id !== (int)$actor->id) $users->setCurrentUser($actor);
        try { return $callback(); }
        finally { if ((int)$previous->id !== (int)$actor->id) $users->setCurrentUser($previous); }
    }

    private function interfaceDate(string $value, \DateTimeImmutable $default): \DateTimeImmutable
    {
        if (trim($value) === '') return $default;
        try { return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC')); }
        catch (\Throwable $e) { throw new \InvalidArgumentException('Invalid interface date.'); }
    }

    private function operationalJobDto(array $job): array
    {
        $hydrated = $this->hydrateCalendarJobs([$job], $this->configuredTimezone())[0];
        return [
            'id' => (int)$hydrated['id'], 'page_id' => (int)$hydrated['page_id'], 'page_title' => (string)$hydrated['_title'],
            'page_url' => (string)$hydrated['_url'], 'action' => (string)$hydrated['action'], 'status' => (string)$hydrated['status'],
            'scheduled_at' => (string)$hydrated['scheduled_at'] . 'Z', 'timezone' => (string)$hydrated['timezone'],
            'local_datetime' => (string)$hydrated['_local_datetime'], 'requested_by_user_id' => (int)$hydrated['requested_by_user_id'],
            'requester' => (string)$hydrated['_requester'], 'run_as_user_id' => (int)$hydrated['run_as_user_id'], 'run_as' => (string)$hydrated['_actor'],
            'attempts' => (int)$hydrated['attempts'], 'executor' => (string)$hydrated['executor'], 'note' => (string)$hydrated['note'],
            'last_error' => (string)($hydrated['last_error'] ?? ''), 'created_at' => (string)$hydrated['created_at'],
            'updated_at' => (string)$hydrated['updated_at'], 'completed_at' => (string)($hydrated['completed_at'] ?? ''),
            'imitation' => $this->isImitationMode(),
        ];
    }
}
