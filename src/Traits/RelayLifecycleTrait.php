<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * ProcessWire install, upgrade, initialization and hook registration.
 */
trait RelayLifecycleTrait
{
    public function ___install(): void
    {
        parent::___install();
        $this->store()->install();
    }

    public function ___uninstall(): void
    {
        $this->store()->uninstall();
        parent::___uninstall();
    }

    public function ___upgrade($fromVersion, $toVersion): void
    {
        $this->store()->install();
    }

    public function init(): void
    {
        if (!$this->isImitationMode()) {
            $this->wire('session')->remove(self::IMITATION_SEEDED_SESSION_KEY);
        }
        if ((int)$this->enable_agent_api === 1 && (int)$this->enable_rest_api === 1) {
            $this->addHook('/relay-api/{version}/{resource}/', $this, 'handleRestRequest');
        }
        if ((int) $this->calendar_feed_enabled === 1 && trim((string) $this->calendar_feed_token_hash) !== '') {
            $this->addHook('/relay-calendar/{token}.ics', $this, 'handleCalendarFeed');
        }
        if (!$this->isImitationMode() && (int) $this->lazy_cron_fallback === 1 && $this->wire('modules')->isInstalled('LazyCron')) {
            $this->addHook('LazyCron::everyMinute', $this, 'hookLazyCron');
        }
    }

    public function ready(): void
    {
        if ((string) $this->wire('page')->template === 'admin') {
            $this->addHookAfter('ProcessPageEdit::buildForm', $this, 'hookPageEditTab');
            $this->addHookAfter('ProcessPageListRender::getPageLabel', $this, 'hookPageListLabel');
            $this->enqueueAssets();
        }
    }

    public function hookLazyCron(HookEvent $event): void
    {
        $this->runDue((int) $this->max_batch, 'lazycron:' . php_uname('n'));
    }

    public function handleRestRequest(HookEvent $event): string
    {
        require_once dirname(__DIR__) . '/RelayRestApi.php';
        return $this->wire(new RelayRestApi($this))->handle((string)$event->arguments('version'), (string)$event->arguments('resource'));
    }

    public function canAdmin(?User $user = null): bool
    {
        $user = $user ?: $this->wire('user');
        return $user->isSuperuser() || $user->hasPermission('relay-admin');
    }

    private function canConfigureModule(?User $user = null): bool
    {
        $user = $user ?: $this->wire('user');
        return $user->isSuperuser() || $user->hasPermission('module-admin');
    }
}
