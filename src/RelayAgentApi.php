<?php

declare(strict_types=1);

namespace ProcessWire;

/** Permission-gated operational facade shared by PHP, REST, and local CLI. */
final class RelayAgentApi
{
    public function __construct(private Relay $relay, private User $actor, private string $channel = 'php_api')
    {
    }

    public function canRead(): bool
    {
        $enabled = match ($this->channel) {
            'cli' => (int)$this->relay->enable_interface_cli === 1,
            'rest' => (int)$this->relay->enable_agent_api === 1 && (int)$this->relay->enable_rest_api === 1,
            default => (int)$this->relay->enable_agent_api === 1,
        };
        return $enabled && ($this->actor->isSuperuser() || ($this->actor->hasPermission('relay-api') && $this->actor->hasPermission('relay-manage')));
    }

    public function canWrite(): bool { return $this->canRead(); }
    public function canAdmin(): bool { return $this->canRead() && $this->relay->canAdmin($this->actor); }

    public function capabilities(): array { $this->requireRead(); return $this->relay->capabilities(); }
    public function counts(): array { $this->requireRead(); return $this->relay->operationalCounts($this->actor); }
    public function jobs(array $filters = []): array { $this->requireRead(); return $this->relay->operationalJobs($this->actor, $filters); }
    public function job(int $id): array { $this->requireRead(); return $this->relay->operationalJob($this->actor, $id); }
    public function schedule(array $data): array { $this->requireWrite(); return $this->relay->operationalSchedule($this->actor, $data); }
    public function reschedule(int $id, array $data): array { $this->requireWrite(); return $this->relay->operationalReschedule($this->actor, $id, $data); }
    public function cancel(int $id): array { $this->requireWrite(); return $this->relay->operationalCancel($this->actor, $id); }
    public function runDue(?int $limit = null): array { $this->requireWrite(); return $this->relay->operationalRunDue($this->actor, $limit); }

    private function requireRead(): void
    {
        if (!$this->canRead()) throw new WirePermissionException('Relay API access denied.');
    }

    private function requireWrite(): void
    {
        if (!$this->canWrite()) throw new WirePermissionException('Relay API mutation access denied.');
    }
}
