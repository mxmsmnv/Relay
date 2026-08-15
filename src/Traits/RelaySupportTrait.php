<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * Shared admin chrome, assets, JSON responses, logging and store access.
 */
trait RelaySupportTrait
{
    private function writeRelayLog(string $message, string $channel = 'relay'): void
    {
        if ((int)$this->enable_logging !== 1) return;
        $this->wire('log')->save($channel, $message);
    }

    private function configureAdminChrome(string $headline, string $browserContext, array $trail = []): void
    {
        $sanitizer = $this->wire('sanitizer');
        $headline = trim((string)$sanitizer->text($headline)) ?: $this->_('Relay');
        $browserContext = trim((string)$sanitizer->text($browserContext)) ?: $headline;
        $this->headline($headline);
        $this->browserTitle($browserContext === $this->_('Relay')
            ? $browserContext
            : $browserContext . ' · ' . $this->_('Relay'));
        if (!$this->wire('breadcrumbs')) {
            return;
        }
        $this->breadcrumb($this->processUrl(), $this->_('Relay'));
        foreach ($trail as $item) {
            $url = (string)($item[0] ?? '');
            $label = trim((string)$sanitizer->text((string)($item[1] ?? '')));
            if ($url !== '' && $label !== '') {
                $this->breadcrumb($url, $label);
            }
        }
    }

    private function processUrl(): string
    {
        $moduleId = (int) $this->wire('modules')->getModuleID($this);
        if ($moduleId > 0) {
            $processPage = $this->wire('pages')->get("template=admin, process=$moduleId, include=all");
            if ($processPage->id) {
                return (string) $processPage->url;
            }
        }
        return $this->wire('config')->urls->admin . 'setup/relay/';
    }

    private function enqueueAssets(): void
    {
        $moduleUrl = (string) $this->wire('config')->urls($this);
        if ($moduleUrl === '') {
            $moduleUrl = $this->wire('config')->urls->siteModules . 'Relay/';
        }
        $this->wire('config')->styles->add($moduleUrl . 'assets/relay.css?v=' . self::VERSION);
        $this->wire('config')->scripts->add($moduleUrl . 'assets/relay.js?v=' . self::VERSION);
    }

    private function requireManagePermission(): void
    {
        if (!$this->wire('user')->hasPermission('relay-manage')) {
            throw new WirePermissionException($this->_('You do not have permission to manage schedules.'));
        }
    }

    private function jsonEndpoint(callable $callback): string
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!$this->wire('input')->requestMethod('POST')) {
                throw new Wire404Exception('POST required.');
            }
            $this->wire('session')->CSRF->validate();
            return json_encode($callback(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (WirePermissionException $e) {
            http_response_code(403);
            return json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code($e instanceof Wire404Exception ? 404 : 422);
            return json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    private function store(): RelayStore
    {
        return $this->storeInstance ??= new RelayStore($this);
    }

    private function actionLabel(string $action): string
    {
        return $action === 'unpublish' ? $this->_('Unpublish') : $this->_('Publish');
    }

    private function statusLabel(string $status): string
    {
        return [
            'scheduled' => $this->_('Scheduled'),
            'processing' => $this->_('Processing'),
            'completed' => $this->_('Completed'),
            'failed' => $this->_('Failed'),
            'cancelled' => $this->_('Cancelled'),
            'superseded' => $this->_('Superseded'),
        ][$status] ?? $status;
    }
}
