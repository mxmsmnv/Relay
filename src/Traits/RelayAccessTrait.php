<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * Configuration normalization, page scope, editorial identities and authorization.
 */
trait RelayAccessTrait
{
/**
     * Normalize multiple-select values and delimited configuration strings.
     */
    private function configListValues(mixed $configured): array
    {
        $values = is_array($configured)
            ? $configured
            : (preg_split('/[\s,;]+/', trim((string)$configured), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $values = array_map(static fn($value): string => is_scalar($value) ? trim((string)$value) : '', $values);
        return array_values(array_unique(array_filter($values, static fn(string $value): bool => $value !== '')));
    }

/**
     * Return validated, display-ready page composer time presets.
     *
     * @return array<int, array{label:string,time:string}>
     */
    private function configuredTimePresets(mixed $configured = null): array
    {
        $configured ??= $this->time_presets;
        $lines = is_array($configured)
            ? $configured
            : (preg_split('/\R/u', trim((string)$configured), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $presets = [];
        $seen = [];
        foreach ($lines as $line) {
            if (!is_scalar($line)) continue;
            $parts = explode('|', trim((string)$line), 2);
            $time = trim(count($parts) === 2 ? $parts[1] : $parts[0]);
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time) || isset($seen[$time])) continue;
            $label = count($parts) === 2 ? trim(strip_tags($parts[0])) : $time;
            $label = trim((string)preg_replace('/\s+/u', ' ', $label));
            if ($label === '') $label = $time;
            $presets[] = ['label' => substr($label, 0, 60), 'time' => $time];
            $seen[$time] = true;
            if (count($presets) >= 12) break;
        }
        return $presets;
    }

    private function availableActors(): array
    {
        $current = $this->wire('user');
        if (!$current->hasPermission('relay-run-as')) {
            return [$current];
        }
        $roles = $this->configListValues($this->actor_roles);
        if (!$roles) {
            return [$current];
        }
        $selector = 'roles=' . implode('|', array_map([$this->wire('sanitizer'), 'selectorValue'], $roles))
            . ', status<' . Page::statusUnpublished . ', sort=name';
        $actors = [];
        foreach ($this->wire('users')->find($selector) as $actor) {
            if (!$actor->isGuest()) {
                $actors[(int) $actor->id] = $actor;
            }
        }
        $actors[(int) $current->id] = $current;
        uasort($actors, static fn(User $a, User $b): int => strcasecmp($a->name, $b->name));
        return array_values($actors);
    }

    private function resolveRunAsUser(int $id): User
    {
        $current = $this->wire('user');
        if (!$current->hasPermission('relay-run-as')) {
            return $current;
        }
        foreach ($this->availableActors() as $actor) {
            if ((int) $actor->id === $id) {
                return $actor;
            }
        }
        throw new WirePermissionException($this->_('That editorial identity is not available.'));
    }

    private function resolveApiRunAsUser(?User $requested): User
    {
        $current = $this->wire('user');
        if ($requested === null || (int) $requested->id === (int) $current->id) {
            return $current;
        }
        if (!$current->hasPermission('relay-run-as')) {
            throw new WirePermissionException($this->_('You cannot choose another editorial identity.'));
        }
        return $this->resolveRunAsUser((int) $requested->id);
    }

    private function assertCanScheduleAction(Page $page, string $action, User $user): void
    {
        $users = $this->wire('users');
        $previous = $this->wire('user');
        if ($previous->id !== $user->id) {
            $users->setCurrentUser($user);
        }
        try {
            if ($action === 'publish' && !$page->publishable()) {
                throw new WirePermissionException($this->_('The editorial identity cannot publish this page.'));
            }
            if ($action === 'unpublish' && (!$page->publishable() || $page->template->noUnpublish)) {
                throw new WirePermissionException($this->_('The editorial identity cannot unpublish this page.'));
            }
        } finally {
            if ($previous->id !== $user->id) {
                $users->setCurrentUser($previous);
            }
        }
    }

    private function editablePage(int $id): Page
    {
        $page = $this->wire('pages')->get($id);
        if (!$page->id || (string) $page->template === 'admin' || !$page->editable() || !$this->templateAllowed($page)) {
            throw new WirePermissionException($this->_('You cannot schedule this page.'));
        }
        return $page;
    }

    private function templateAllowed(Page $page): bool
    {
        $allowed = $this->configListValues($this->allowed_templates);
        return !$allowed || in_array((string) $page->template->name, $allowed, true);
    }

    private function configuredTimezone(): string
    {
        try {
            return RelayClock::assertTimezone((string) $this->timezone);
        } catch (\InvalidArgumentException $e) {
            return 'UTC';
        }
    }

    private function assertSchedulingHorizon(\DateTimeImmutable $scheduledAtUtc): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($scheduledAtUtc <= $now) {
            throw new WireException($this->_('Scheduled time must be in the future.'));
        }
        $years = max(1, min(20, (int) $this->max_future_years));
        if ($scheduledAtUtc > $now->modify('+' . $years . ' years')) {
            throw new WireException(sprintf($this->_n('Scheduled time must be within %d year.', 'Scheduled time must be within %d years.', $years), $years));
        }
    }
}
