<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * Bounded job import/export, preview validation and transfer workspace.
 */
trait RelayTransferTrait
{
/** Export a bounded portable Relay job document. */
    public function exportJobs(User $actor, string $scope = 'scheduled'): array
    {
        $this->assertTransferActor($actor);
        if (!in_array($scope, ['scheduled', 'all'], true)) {
            throw new \InvalidArgumentException($this->_('Export scope must be scheduled or all.'));
        }
        return $this->withInterfaceActor($actor, function () use ($scope): array {
            $from = new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC'));
            $years = max(1, min(20, (int)$this->max_future_years));
            $to = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . ($years + 1) . ' years');
            $status = $scope === 'scheduled' ? 'scheduled' : null;
            $source = $this->isImitationMode()
                ? $this->imitationBetween($from, $to, self::TRANSFER_LIMIT + 1, null, $status)
                : $this->store()->between($from, $to, self::TRANSFER_LIMIT + 1, null, $status);
            $truncated = count($source) > self::TRANSFER_LIMIT;
            $jobs = [];
            foreach (array_slice($source, 0, self::TRANSFER_LIMIT) as $job) {
                $page = $this->wire('pages')->get((int)$job['page_id']);
                $runAs = $this->wire('users')->get((int)$job['run_as_user_id']);
                $jobs[] = [
                    'source_id' => (int)$job['id'],
                    'page' => [
                        'id' => (int)$job['page_id'],
                        'path' => $page->id ? (string)$page->path : '',
                        'title' => $page->id ? (string)$page->get('title|name') : '',
                    ],
                    'action' => (string)$job['action'],
                    'status' => (string)$job['status'],
                    'scheduled_at' => (new \DateTimeImmutable((string)$job['scheduled_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                    'timezone' => (string)$job['timezone'],
                    'run_as' => [
                        'id' => (int)$job['run_as_user_id'],
                        'name' => $runAs->id ? (string)$runAs->name : '',
                    ],
                    'note' => (string)$job['note'],
                ];
            }
            return [
                'schema' => self::TRANSFER_SCHEMA,
                'version' => self::TRANSFER_VERSION,
                'exported_at' => gmdate('c'),
                'relay_version' => self::VERSION,
                'imitation' => $this->isImitationMode(),
                'scope' => $scope,
                'truncated' => $truncated,
                'jobs' => $jobs,
            ];
        });
    }

/** Preview or execute a bounded portable Relay job import. */
    public function importJobs(User $actor, string $json, bool $execute = false): array
    {
        $this->assertTransferActor($actor);
        if (strlen($json) > self::TRANSFER_MAX_BYTES) throw new WireException($this->_('Relay import files are limited to 1 MiB.'));
        return $this->withInterfaceActor($actor, function () use ($actor, $json, $execute): array {
            try {
                $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new WireException($this->_('The import file is not valid JSON.'));
            }
            if (!is_array($document) || ($document['schema'] ?? '') !== self::TRANSFER_SCHEMA || (int)($document['version'] ?? 0) !== self::TRANSFER_VERSION) {
                throw new WireException($this->_('The file is not a supported Relay jobs export.'));
            }
            $items = $document['jobs'] ?? null;
            if (!is_array($items)) throw new WireException($this->_('The Relay jobs list is missing.'));
            if (count($items) > self::TRANSFER_LIMIT) throw new WireException($this->_('A Relay import can contain at most 500 jobs.'));

            $rows = [];
            $normalized = [];
            $errors = 0;
            $warnings = 0;
            $skipped = 0;
            $seen = [];
            foreach (array_values($items) as $index => $item) {
                $number = $index + 1;
                $row = ['number'=>$number, 'state'=>'ready', 'page'=>'', 'action'=>'', 'date'=>'', 'message'=>''];
                if (!is_array($item)) {
                    $row['state'] = 'error'; $row['message'] = $this->_('Job must be a JSON object.'); $errors++; $rows[] = $row; continue;
                }
                $status = (string)($item['status'] ?? 'scheduled');
                if ($status !== 'scheduled') {
                    $row['state'] = 'skipped'; $row['message'] = $this->_('Only scheduled jobs can be imported.'); $skipped++; $rows[] = $row; continue;
                }
                $action = (string)($item['action'] ?? '');
                $row['action'] = $action;
                if (!in_array($action, ['publish', 'unpublish'], true)) {
                    $row['state'] = 'error'; $row['message'] = $this->_('Action must be publish or unpublish.'); $errors++; $rows[] = $row; continue;
                }
                $pageData = is_array($item['page'] ?? null) ? $item['page'] : [];
                $path = trim((string)($pageData['path'] ?? ''));
                $pageId = max(0, (int)($pageData['id'] ?? 0));
                $page = $pageId ? $this->wire('pages')->get($pageId) : $this->wire('pages')->newNullPage();
                if (!$page->id || ($path !== '' && (string)$page->path !== $path)) {
                    $page = $path !== '' && str_starts_with($path, '/') && mb_strlen($path) <= 2048
                        ? $this->wire('pages')->get($path)
                        : $this->wire('pages')->newNullPage();
                }
                $row['page'] = $page->id ? (string)$page->get('title|name') : ($path !== '' ? $path : '#' . $pageId);
                if (!$page->id || (string)$page->template === 'admin' || !$page->editable() || !$this->templateAllowed($page)) {
                    $row['state'] = 'error'; $row['message'] = $this->_('Page is missing, outside the allowed templates, or not editable.'); $errors++; $rows[] = $row; continue;
                }
                $timezone = trim((string)($item['timezone'] ?? $this->configuredTimezone()));
                $scheduledValue = trim((string)($item['scheduled_at'] ?? ''));
                try {
                    RelayClock::assertTimezone($timezone);
                    $whenUtc = (new \DateTimeImmutable($scheduledValue))->setTimezone(new \DateTimeZone('UTC'));
                    $this->assertSchedulingHorizon($whenUtc);
                } catch (\Throwable) {
                    $row['state'] = 'error'; $row['message'] = $this->_('Date or timezone is invalid, past, or outside the planning horizon.'); $errors++; $rows[] = $row; continue;
                }
                $row['date'] = $whenUtc->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d H:i') . ' ' . $timezone;
                $runAsData = is_array($item['run_as'] ?? null) ? $item['run_as'] : [];
                $runAsName = trim((string)($runAsData['name'] ?? ''));
                $runAs = $runAsName !== '' ? $this->wire('users')->get('name=' . $this->wire('sanitizer')->selectorValue($runAsName)) : $actor;
                if (!$runAs->id) {
                    $runAs = $actor;
                    $row['state'] = 'warning';
                    $row['message'] = $this->_('Editorial identity was not found; the importing user will be used.');
                    $warnings++;
                }
                try {
                    $runAs = $this->resolveApiRunAsUser($runAs);
                    $this->assertCanScheduleAction($page, $action, $actor);
                    $this->assertCanScheduleAction($page, $action, $runAs);
                } catch (\Throwable) {
                    $row['state'] = 'error'; $row['message'] = $this->_('The page or editorial identity cannot perform this action.'); $errors++; $rows[] = $row; continue;
                }
                $key = (int)$page->id . ':' . $action;
                if (isset($seen[$key])) {
                    $row['state'] = 'error'; $row['message'] = $this->_('The file repeats the same page and action.'); $errors++; $rows[] = $row; continue;
                }
                $seen[$key] = true;
                $normalized[] = [
                    'page_id'=>(int)$page->id,
                    'action'=>$action,
                    'scheduled_at'=>$whenUtc->format('Y-m-d\TH:i:s\Z'),
                    'timezone'=>$timezone,
                    'run_as_user_id'=>(int)$runAs->id,
                    'note'=>mb_substr(trim((string)($item['note'] ?? '')), 0, 500),
                ];
                $rows[] = $row;
            }
            $result = [
                'ok'=>$errors === 0 && $normalized !== [],
                'execute'=>$execute,
                'total'=>count($items),
                'importable'=>count($normalized),
                'skipped'=>$skipped,
                'warnings'=>$warnings,
                'errors'=>$errors,
                'imitation'=>$this->isImitationMode(),
                'rows'=>$rows,
                'jobs'=>$normalized,
                'job_ids'=>[],
            ];
            if (!$execute) return $result;
            if (!$result['ok']) throw new WireException($this->_('Resolve every import error before applying the file.'));
            foreach ($normalized as $job) {
                $page = $this->wire('pages')->get((int)$job['page_id']);
                $runAs = $this->wire('users')->get((int)$job['run_as_user_id']);
                $when = (new \DateTimeImmutable((string)$job['scheduled_at']))->setTimezone(new \DateTimeZone((string)$job['timezone']));
                $result['job_ids'][] = $this->scheduleAction($page, (string)$job['action'], $when, $runAs, (string)$job['note']);
            }
            unset($result['jobs']);
            return $result;
        });
    }

    public function ___executeTransfer(): string
    {
        $this->requireInterfaceAdmin();
        $this->assertTransferActor($this->wire('user'));
        $scope = (string)$this->wire('sanitizer')->option((string)$this->wire('input')->get('export'), ['scheduled','all']);
        if ($scope !== '') $this->sendJobsExport($scope);

        $preview = null;
        $result = null;
        if ($this->wire('input')->requestMethod('POST')) {
            $this->wire('session')->CSRF->validate();
            $action = (string)$this->wire('input')->post('transfer_action');
            if ($action === 'preview') {
                $json = $this->uploadedTransferJson();
                $preview = $this->importJobs($this->wire('user'), $json, false);
                if ($preview['ok']) {
                    $this->wire('session')->set(self::TRANSFER_SESSION_KEY, [
                        'user_id'=>(int)$this->wire('user')->id,
                        'created_at'=>time(),
                        'imitation'=>$this->isImitationMode(),
                        'json'=>$json,
                    ]);
                } else {
                    $this->wire('session')->remove(self::TRANSFER_SESSION_KEY);
                }
            } elseif ($action === 'apply') {
                if ((string)$this->wire('input')->post('confirm_import') !== '1') throw new WireException($this->_('Confirm the reviewed import before applying it.'));
                $stored = $this->wire('session')->get(self::TRANSFER_SESSION_KEY);
                if (!is_array($stored)
                    || (int)($stored['user_id'] ?? 0) !== (int)$this->wire('user')->id
                    || (int)($stored['created_at'] ?? 0) < time() - 900
                    || (bool)($stored['imitation'] ?? false) !== $this->isImitationMode()
                    || !is_string($stored['json'] ?? null)) {
                    throw new WireException($this->_('The import preview expired or the execution mode changed. Preview the file again.'));
                }
                $result = $this->importJobs($this->wire('user'), (string)$stored['json'], true);
                $this->wire('session')->remove(self::TRANSFER_SESSION_KEY);
                $this->message(sprintf($this->_n('%d job imported.', '%d jobs imported.', count($result['job_ids'])), count($result['job_ids'])));
            } else {
                throw new WireException($this->_('Unsupported transfer action.'));
            }
        }

        $this->configureAdminChrome($this->_('Relay Import / Export'), $this->_('Import / Export'), [[$this->processUrl() . 'interfaces/', $this->_('Interfaces')]]);
        $counts = $this->isImitationMode() ? $this->imitationCounts() : $this->store()->counts();
        $total = array_sum($counts);
        $scheduled = (int)($counts['scheduled'] ?? 0);
        $out = $this->interfaceNav('transfer') . $this->interfaceIntro($this->_('Move Relay jobs safely'), $this->_('Download a portable JSON document or preview a reviewed file before creating future scheduled actions.'), [
            $this->_('Export')=>[true,$this->_('Ready'),$this->_('Unavailable')],
            $this->_('Import')=>[true,$this->_('Preview first'),$this->_('Unavailable')],
            $this->_('Mode')=>[true, $this->isImitationMode() ? $this->_('Imitation') : $this->_('Real jobs'), $this->_('Unavailable')],
        ]);
        $out .= $this->renderTransferWorkspace($total, $scheduled, $preview, $result);
        $out .= $this->interfaceSafety($this->_('Data boundary'), $this->_('Import creates scheduled actions only after review'), $this->_('The portable file contains page paths, dates, editorial identities, and internal notes. Processing history is export-only. Secrets, lock tokens, errors, and page content are never included.'));
        return $this->interfaceWrap($out);
    }

    private function renderTransferWorkspace(int $total, int $scheduled, ?array $preview, ?array $result): string
    {
        $sanitizer = $this->wire('sanitizer');
        $token = $this->wire('session')->CSRF->getToken();
        $csrf = '<input type="hidden" name="' . $sanitizer->entities((string)$token['name']) . '" value="'
            . $sanitizer->entities((string)$token['value']) . '">';
        $base = $this->processUrl() . 'transfer/';
        $out = '<div class="RelayTransferGrid"><section class="RelayTransferPanel"><header><span><i class="fa fa-download" aria-hidden="true"></i></span><div><small>JSON</small><h2>'
            . $this->_('Export jobs') . '</h2><p>' . $this->_('Create a portable file without credentials, page content, worker locks, or delivery data.')
            . '</p></div></header><div class="RelayTransferStats"><span><small>' . $this->_('Jobs in this mode') . '</small><strong>' . $total
            . '</strong></span><span><small>' . $this->_('Scheduled') . '</small><strong>' . $scheduled . '</strong></span><span><small>'
            . $this->_('Maximum per file') . '</small><strong>' . self::TRANSFER_LIMIT . '</strong></span></div><div class="RelayTransferActions"><a class="uk-button uk-button-primary" href="'
            . $base . '?export=scheduled"><i class="fa fa-download" aria-hidden="true"></i> ' . $this->_('Export scheduled jobs')
            . '</a><a class="uk-button uk-button-default" href="' . $base . '?export=all"><i class="fa fa-archive" aria-hidden="true"></i> '
            . $this->_('Export complete history') . '</a></div><p class="RelayTransferNote">' . $this->_('History remains useful for audit and backup, but only future scheduled jobs are importable.') . '</p></section>';
        $out .= '<section class="RelayTransferPanel"><header><span><i class="fa fa-upload" aria-hidden="true"></i></span><div><small>'
            . $this->_('Preview first') . '</small><h2>' . $this->_('Import jobs') . '</h2><p>'
            . $this->_('Upload a Relay JSON file or paste its contents. Preview performs no writes.') . '</p></div></header><form method="post" enctype="multipart/form-data" class="RelayTransferForm">'
            . $csrf . '<input type="hidden" name="transfer_action" value="preview"><label class="RelayTransferFile"><span>' . $this->_('JSON file')
            . '</span><span class="RelayTransferFile__control"><span class="RelayTransferFile__button"><i class="fa fa-folder-open-o" aria-hidden="true"></i> '
            . $this->_('Choose file') . '</span><span class="RelayTransferFile__name" data-relay-file-name>' . $this->_('No file selected')
            . '</span></span><input class="RelayTransferFile__input" type="file" name="relay_import_file" accept="application/json,.json" data-relay-import-file data-empty-label="'
            . $this->wire('sanitizer')->entities($this->_('No file selected')) . '"></label><div class="RelayTransferOr"><span>'
            . $this->_('or paste JSON') . '</span></div><label><span>' . $this->_('JSON document')
            . '</span><textarea class="uk-textarea" name="relay_import_json" rows="8" maxlength="' . self::TRANSFER_MAX_BYTES . '" spellcheck="false"></textarea></label><button class="uk-button uk-button-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> '
            . $this->_('Preview import') . '</button></form></section></div>';

        if ($result !== null) {
            $out .= '<section class="RelayTransferResult" role="status"><span><i class="fa fa-check" aria-hidden="true"></i></span><div><small>'
                . ($result['imitation'] ? $this->_('Imitation import') : $this->_('Import complete')) . '</small><h2>'
                . sprintf($this->_n('%d job imported.', '%d jobs imported.', count($result['job_ids'])), count($result['job_ids']))
                . '</h2><p>' . ($result['imitation'] ? $this->_('The imported jobs exist only in this session; relay_jobs was not changed.') : $this->_('The imported jobs are now visible in the Relay calendar and stored in relay_jobs.'))
                . '</p></div></section>';
        }
        if ($preview === null) return $out;

        $summary = '<div class="RelayTransferPreview__summary"><span><small>' . $this->_('Rows') . '</small><strong>' . (int)$preview['total']
            . '</strong></span><span data-state="ready"><small>' . $this->_('Importable') . '</small><strong>' . (int)$preview['importable']
            . '</strong></span><span data-state="warning"><small>' . $this->_('Warnings') . '</small><strong>' . (int)$preview['warnings']
            . '</strong></span><span data-state="skipped"><small>' . $this->_('Skipped') . '</small><strong>' . (int)$preview['skipped']
            . '</strong></span><span data-state="error"><small>' . $this->_('Errors') . '</small><strong>' . (int)$preview['errors'] . '</strong></span></div>';
        $out .= '<section class="RelayTransferPreview"><header><div><small>' . $this->_('Import preview') . '</small><h2>'
            . ($preview['ok'] ? $this->_('Ready to apply') : $this->_('Review the reported problems')) . '</h2><p>'
            . ($preview['imitation'] ? $this->_('Imitation mode is active. Applying this preview changes session data only.') : $this->_('No jobs are created until you confirm and apply this preview.'))
            . '</p></div>' . $summary . '</header><div class="uk-overflow-auto"><table class="uk-table uk-table-divider uk-table-middle"><thead><tr><th>#</th><th>'
            . $this->_('Status') . '</th><th>' . $this->_('Page') . '</th><th>' . $this->_('Action') . '</th><th>' . $this->_('Date')
            . '</th><th>' . $this->_('Result') . '</th></tr></thead><tbody>';
        foreach ($preview['rows'] as $row) {
            $state = $sanitizer->name((string)$row['state']);
            $stateLabel = match ((string)$row['state']) {
                'ready' => $this->_('Ready'),
                'warning' => $this->_('Warnings'),
                'skipped' => $this->_('Skipped'),
                default => $this->_('Errors'),
            };
            $out .= '<tr><td>' . (int)$row['number'] . '</td><td><span class="RelayTransferState" data-state="' . $state . '">'
                . $sanitizer->entities($stateLabel) . '</span></td><td>' . $sanitizer->entities((string)$row['page'])
                . '</td><td>' . $sanitizer->entities((string)$row['action']) . '</td><td>' . $sanitizer->entities((string)$row['date'])
                . '</td><td>' . $sanitizer->entities((string)$row['message']) . '</td></tr>';
        }
        $out .= '</tbody></table></div>';
        if ($preview['ok']) {
            $out .= '<form method="post" class="RelayTransferApply">' . $csrf . '<input type="hidden" name="transfer_action" value="apply"><label><input class="uk-checkbox" type="checkbox" name="confirm_import" value="1" required><span><strong>'
                . $this->_('Apply the reviewed import') . '</strong><small>' . ($preview['imitation']
                    ? $this->_('Create session-only imitation jobs. No database rows or page states will change.')
                    : $this->_('Create real scheduled jobs. Existing pending actions for the same page and action may be superseded.'))
                . '</small></span></label><button class="uk-button uk-button-primary" type="submit"><i class="fa fa-check" aria-hidden="true"></i> '
                . sprintf($this->_n('Import %d job', 'Import %d jobs', (int)$preview['importable']), (int)$preview['importable']) . '</button></form>';
        }
        return $out . '</section>';
    }

    private function uploadedTransferJson(): string
    {
        $pasted = trim((string)$this->wire('input')->post('relay_import_json'));
        if ($pasted !== '') {
            if (strlen($pasted) > self::TRANSFER_MAX_BYTES) throw new WireException($this->_('Relay import files are limited to 1 MiB.'));
            return $pasted;
        }
        $file = $_FILES['relay_import_file'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new WireException($this->_('Choose a Relay JSON file or paste its contents.'));
        }
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || (int)($file['size'] ?? 0) < 1
            || (int)($file['size'] ?? 0) > self::TRANSFER_MAX_BYTES
            || !is_uploaded_file((string)($file['tmp_name'] ?? ''))
            || strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'json') {
            throw new WireException($this->_('The upload must be a valid JSON file no larger than 1 MiB.'));
        }
        $json = file_get_contents((string)$file['tmp_name']);
        if (!is_string($json) || trim($json) === '') throw new WireException($this->_('The uploaded JSON file is empty.'));
        return $json;
    }

    private function sendJobsExport(string $scope): never
    {
        $payload = $this->exportJobs($this->wire('user'), $scope);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $filename = 'relay-jobs-' . $scope . '-' . gmdate('Ymd-His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $json;
        exit;
    }

    private function assertTransferActor(User $actor): void
    {
        if (!$actor->id || (!$actor->isSuperuser() && (!$actor->hasPermission('relay-admin') || !$actor->hasPermission('relay-manage')))) {
            throw new WirePermissionException($this->_('Relay import and export access denied.'));
        }
    }
}
