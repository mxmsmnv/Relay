<?php

declare(strict_types=1);

namespace ProcessWire;

final class RelayStore
{
    public const TABLE = 'relay_jobs';

    public const PRESETS_TABLE = 'relay_presets';

    private Wire $wire;

    public function __construct(Wire $wire)
    {
        $this->wire = $wire;
    }

    public function install(): void
    {
        $table = self::TABLE;
        $sqlite = $this->db()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $sql = $sqlite
            ? "CREATE TABLE IF NOT EXISTS `$table` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `page_id` INTEGER NOT NULL,
                `action` TEXT NOT NULL,
                `scheduled_at` TEXT NOT NULL,
                `timezone` TEXT NOT NULL,
                `status` TEXT NOT NULL DEFAULT 'scheduled',
                `requested_by_user_id` INTEGER NOT NULL,
                `run_as_user_id` INTEGER NOT NULL,
                `executor` TEXT NOT NULL DEFAULT '',
                `attempts` INTEGER NOT NULL DEFAULT 0,
                `lock_token` TEXT DEFAULT NULL,
                `locked_at` TEXT DEFAULT NULL,
                `completed_at` TEXT DEFAULT NULL,
                `created_at` TEXT NOT NULL,
                `updated_at` TEXT NOT NULL,
                `note` TEXT NOT NULL DEFAULT '',
                `last_error` TEXT DEFAULT NULL
            )"
            : "CREATE TABLE IF NOT EXISTS `$table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `page_id` INT UNSIGNED NOT NULL,
                `action` VARCHAR(20) NOT NULL,
                `scheduled_at` DATETIME NOT NULL,
                `timezone` VARCHAR(64) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'scheduled',
                `requested_by_user_id` INT UNSIGNED NOT NULL,
                `run_as_user_id` INT UNSIGNED NOT NULL,
                `executor` VARCHAR(190) NOT NULL DEFAULT '',
                `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `lock_token` CHAR(36) DEFAULT NULL,
                `locked_at` DATETIME DEFAULT NULL,
                `completed_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `last_error` TEXT DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `due_jobs` (`status`, `scheduled_at`, `id`),
                KEY `page_jobs` (`page_id`, `status`, `scheduled_at`),
                KEY `locked_jobs` (`status`, `locked_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->db()->exec($sql);
        if ($sqlite) {
            $this->db()->exec("CREATE INDEX IF NOT EXISTS `due_jobs` ON `$table` (`status`, `scheduled_at`, `id`)");
            $this->db()->exec("CREATE INDEX IF NOT EXISTS `page_jobs` ON `$table` (`page_id`, `status`, `scheduled_at`)");
            $this->db()->exec("CREATE INDEX IF NOT EXISTS `locked_jobs` ON `$table` (`status`, `locked_at`)");
        }
        $this->installPresets();
    }

    private function installPresets(): void
    {
        $table = self::PRESETS_TABLE;
        $sql = $this->db()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "CREATE TABLE IF NOT EXISTS `$table` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `name` TEXT NOT NULL UNIQUE,
                `template` TEXT NOT NULL DEFAULT '',
                `action` TEXT NOT NULL DEFAULT 'publish',
                `start_time` TEXT NOT NULL DEFAULT '09:00',
                `frequency` TEXT NOT NULL DEFAULT 'week',
                `interval_value` INTEGER NOT NULL DEFAULT 1,
                `weekdays` TEXT NOT NULL DEFAULT '',
                `ends` TEXT NOT NULL DEFAULT 'never',
                `until_days` INTEGER NOT NULL DEFAULT 90,
                `occurrences` INTEGER NOT NULL DEFAULT 12,
                `window_minutes` INTEGER NOT NULL DEFAULT 10080,
                `note` TEXT NOT NULL DEFAULT '',
                `created_by_user_id` INTEGER NOT NULL DEFAULT 0,
                `created_at` TEXT NOT NULL,
                `updated_at` TEXT NOT NULL
            )"
            : "CREATE TABLE IF NOT EXISTS `$table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(80) NOT NULL,
                `template` VARCHAR(128) NOT NULL DEFAULT '',
                `action` VARCHAR(20) NOT NULL DEFAULT 'publish',
                `start_time` CHAR(5) NOT NULL DEFAULT '09:00',
                `frequency` VARCHAR(10) NOT NULL DEFAULT 'week',
                `interval_value` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `weekdays` VARCHAR(20) NOT NULL DEFAULT '',
                `ends` VARCHAR(10) NOT NULL DEFAULT 'never',
                `until_days` SMALLINT UNSIGNED NOT NULL DEFAULT 90,
                `occurrences` SMALLINT UNSIGNED NOT NULL DEFAULT 12,
                `window_minutes` INT UNSIGNED NOT NULL DEFAULT 10080,
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `created_by_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `preset_name` (`name`),
                KEY `preset_template` (`template`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->db()->exec($sql);
        if ($this->db()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $this->db()->exec('ALTER TABLE `' . $table . '` MODIFY `interval_value` SMALLINT UNSIGNED NOT NULL DEFAULT 1');
        }
        $this->seedDefaultPresets();
    }

    private function seedDefaultPresets(): void
    {
        if ((int)$this->db()->query('SELECT COUNT(*) FROM `' . self::PRESETS_TABLE . '`')->fetchColumn() > 0) {
            return;
        }

        foreach ([
            ['Every 15 minutes', 'minute', 15],
            ['Every 30 minutes', 'minute', 30],
            ['Every 69 minutes', 'minute', 69],
            ['Every 4 days', 'day', 4],
            ['Every week', 'week', 1],
            ['Every month', 'month', 1],
        ] as $preset) {
            $exists = $this->db()->prepare('SELECT COUNT(*) FROM `' . self::PRESETS_TABLE . '` WHERE LOWER(name)=LOWER(?)');
            $exists->execute([$preset[0]]);
            if ((int)$exists->fetchColumn() > 0) continue;
            $now = gmdate('Y-m-d H:i:s');
            $statement = $this->db()->prepare(
                'INSERT INTO `' . self::PRESETS_TABLE . '`
                (name, template, action, start_time, frequency, interval_value, weekdays, ends,
                 until_days, occurrences, window_minutes, note, created_by_user_id, created_at, updated_at)
                VALUES (:name, :template, :action, :start_time, :frequency, :interval_value, :weekdays, :ends,
                 :until_days, :occurrences, :window_minutes, :note, :created_by, :created_at, :updated_at)'
            );
            $statement->execute([
                ':name'=>$preset[0], ':template'=>'', ':action'=>'publish', ':start_time'=>'09:00',
                ':frequency'=>$preset[1], ':interval_value'=>$preset[2], ':weekdays'=>'', ':ends'=>'never',
                ':until_days'=>90, ':occurrences'=>12, ':window_minutes'=>10080, ':note'=>'',
                ':created_by'=>0, ':created_at'=>$now, ':updated_at'=>$now,
            ]);
        }
    }

    public function uninstall(): void
    {
        $this->db()->exec('DROP TABLE IF EXISTS `' . self::PRESETS_TABLE . '`');
        $this->db()->exec('DROP TABLE IF EXISTS `' . self::TABLE . '`');
    }

    /** @return list<array<string,mixed>> */
    public function presets(int $limit = 50): array
    {
        $limit = max(1, min(50, $limit));
        $rows = $this->db()->query(
            "SELECT * FROM `" . self::PRESETS_TABLE . "` ORDER BY
             CASE frequency WHEN 'minute' THEN 1 WHEN 'day' THEN 2 WHEN 'week' THEN 3 WHEN 'month' THEN 4 WHEN 'year' THEN 5 ELSE 6 END,
             interval_value ASC, name ASC, id ASC LIMIT " . $limit
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['interval'] = (int)$row['interval_value'];
            $row['weekdays'] = array_values(array_filter(array_map('intval', explode(',', (string)$row['weekdays'])), static fn(int $day): bool => $day >= 1 && $day <= 7));
            $row['until_days'] = (int)$row['until_days'];
            $row['occurrences'] = (int)$row['occurrences'];
            $row['window_minutes'] = (int)$row['window_minutes'];
            $row['created_by_user_id'] = (int)$row['created_by_user_id'];
            unset($row['interval_value']);
        }
        unset($row);
        return $rows;
    }

    /** @param array<string,mixed> $preset */
    public function savePreset(array $preset, int $createdByUserId): int
    {
        $existing = $this->db()->prepare('SELECT id FROM `' . self::PRESETS_TABLE . '` WHERE LOWER(name)=LOWER(:name) LIMIT 1');
        $existing->execute([':name'=>(string)$preset['name']]);
        $id = (int)$existing->fetchColumn();
        $params = [
            ':name'=>(string)$preset['name'], ':template'=>(string)$preset['template'], ':action'=>(string)$preset['action'],
            ':start_time'=>(string)$preset['start_time'], ':frequency'=>(string)$preset['frequency'], ':interval_value'=>(int)$preset['interval'],
            ':weekdays'=>implode(',', (array)$preset['weekdays']), ':ends'=>(string)$preset['ends'], ':until_days'=>(int)$preset['until_days'],
            ':occurrences'=>(int)$preset['occurrences'], ':window_minutes'=>(int)$preset['window_minutes'], ':note'=>(string)$preset['note'],
            ':created_by'=>max(0, $createdByUserId), ':updated_at'=>gmdate('Y-m-d H:i:s'),
        ];
        if ($id > 0) {
            $params[':id'] = $id;
            $this->db()->prepare(
                'UPDATE `' . self::PRESETS_TABLE . '` SET name=:name, template=:template, action=:action, start_time=:start_time,
                 frequency=:frequency, interval_value=:interval_value, weekdays=:weekdays, ends=:ends, until_days=:until_days,
                 occurrences=:occurrences, window_minutes=:window_minutes, note=:note, created_by_user_id=:created_by,
                 updated_at=:updated_at WHERE id=:id'
            )->execute($params);
            return $id;
        }
        if ((int)$this->db()->query('SELECT COUNT(*) FROM `' . self::PRESETS_TABLE . '`')->fetchColumn() >= 50) {
            throw new \RuntimeException('Relay supports up to 50 quick presets.');
        }
        $params[':created_at'] = $params[':updated_at'];
        $this->db()->prepare(
            'INSERT INTO `' . self::PRESETS_TABLE . '`
             (name, template, action, start_time, frequency, interval_value, weekdays, ends, until_days,
              occurrences, window_minutes, note, created_by_user_id, created_at, updated_at)
             VALUES (:name, :template, :action, :start_time, :frequency, :interval_value, :weekdays, :ends, :until_days,
              :occurrences, :window_minutes, :note, :created_by, :created_at, :updated_at)'
        )->execute($params);
        return (int)$this->db()->lastInsertId();
    }

    public function deletePreset(int $id): bool
    {
        $statement = $this->db()->prepare('DELETE FROM `' . self::PRESETS_TABLE . '` WHERE id=:id');
        $statement->execute([':id'=>max(0, $id)]);
        return $statement->rowCount() === 1;
    }

    public function schedule(
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

        $now = gmdate('Y-m-d H:i:s');
        $db = $this->db();
        $db->beginTransaction();

        try {
            $cancel = $db->prepare(
                "UPDATE `" . self::TABLE . "`
                 SET status='superseded', updated_at=:now
                 WHERE page_id=:page_id AND action=:action AND status='scheduled'"
            );
            $cancel->execute([':now' => $now, ':page_id' => $pageId, ':action' => $action]);

            $insert = $db->prepare(
                "INSERT INTO `" . self::TABLE . "`
                (page_id, action, scheduled_at, timezone, status, requested_by_user_id,
                 run_as_user_id, created_at, updated_at, note)
                VALUES
                (:page_id, :action, :scheduled_at, :timezone, 'scheduled', :requested_by,
                 :run_as, :created_at, :updated_at, :note)"
            );
            $insert->execute([
                ':page_id' => $pageId,
                ':action' => $action,
                ':scheduled_at' => $scheduledAtUtc->format('Y-m-d H:i:s'),
                ':timezone' => $timezone,
                ':requested_by' => $requestedBy,
                ':run_as' => $runAs,
                ':created_at' => $now,
                ':updated_at' => $now,
                ':note' => mb_substr(trim($note), 0, 500),
            ]);
            $id = (int) $db->lastInsertId();
            $db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function scheduleWindow(
        int $pageId,
        \DateTimeImmutable $publishAtUtc,
        \DateTimeImmutable $unpublishAtUtc,
        string $timezone,
        int $requestedBy,
        int $runAs,
        string $note = ''
    ): array {
        if ($unpublishAtUtc <= $publishAtUtc) {
            throw new \InvalidArgumentException('The unpublish time must be after the publish time.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $db = $this->db();
        $db->beginTransaction();
        try {
            $ids = [];
            foreach (['publish' => $publishAtUtc, 'unpublish' => $unpublishAtUtc] as $action => $scheduledAtUtc) {
                $cancel = $db->prepare(
                    "UPDATE `" . self::TABLE . "` SET status='superseded', updated_at=:now
                     WHERE page_id=:page_id AND action=:action AND status='scheduled'"
                );
                $cancel->execute([':now' => $now, ':page_id' => $pageId, ':action' => $action]);
                $insert = $db->prepare(
                    "INSERT INTO `" . self::TABLE . "`
                    (page_id, action, scheduled_at, timezone, status, requested_by_user_id,
                     run_as_user_id, created_at, updated_at, note)
                    VALUES (:page_id, :action, :scheduled_at, :timezone, 'scheduled', :requested_by,
                     :run_as, :created_at, :updated_at, :note)"
                );
                $insert->execute([
                    ':page_id' => $pageId,
                    ':action' => $action,
                    ':scheduled_at' => $scheduledAtUtc->format('Y-m-d H:i:s'),
                    ':timezone' => $timezone,
                    ':requested_by' => $requestedBy,
                    ':run_as' => $runAs,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                    ':note' => mb_substr(trim($note), 0, 500),
                ]);
                $ids[$action] = (int) $db->lastInsertId();
            }
            $db->commit();
            return $ids;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function reschedule(
        int $id,
        \DateTimeImmutable $scheduledAtUtc,
        string $timezone,
        int $runAs,
        string $note = ''
    ): bool {
        $stmt = $this->db()->prepare(
            "UPDATE `" . self::TABLE . "`
             SET scheduled_at=:scheduled_at, timezone=:timezone, run_as_user_id=:run_as,
                 note=:note, updated_at=:now
             WHERE id=:id AND status='scheduled'"
        );
        $stmt->execute([
            ':scheduled_at' => $scheduledAtUtc->format('Y-m-d H:i:s'),
            ':timezone' => $timezone,
            ':run_as' => $runAs,
            ':note' => mb_substr(trim($note), 0, 500),
            ':now' => gmdate('Y-m-d H:i:s'),
            ':id' => $id,
        ]);
        if ($stmt->rowCount() === 1) {
            return true;
        }
        $job = $this->get($id);
        return $job !== null && $job['status'] === 'scheduled';
    }

    public function cancel(int $id): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE `" . self::TABLE . "`
             SET status='cancelled', updated_at=:now
             WHERE id=:id AND status='scheduled'"
        );
        $stmt->execute([':now' => gmdate('Y-m-d H:i:s'), ':id' => $id]);
        return $stmt->rowCount() === 1;
    }

    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM `' . self::TABLE . '` WHERE id=:id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function forPage(int $pageId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db()->prepare(
            "SELECT * FROM `" . self::TABLE . "`
             WHERE page_id=:page_id
             ORDER BY scheduled_at DESC, id DESC LIMIT $limit"
        );
        $stmt->execute([':page_id' => $pageId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function nextScheduledForPage(int $pageId): ?array
    {
        $stmt = $this->db()->prepare(
            "SELECT * FROM `" . self::TABLE . "`
             WHERE page_id=:page_id AND status='scheduled'
             ORDER BY scheduled_at ASC, id ASC LIMIT 1"
        );
        $stmt->execute([':page_id' => $pageId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function between(
        \DateTimeImmutable $fromUtc,
        \DateTimeImmutable $toUtc,
        int $limit = 1000,
        ?int $pageId = null,
        ?string $status = null,
        ?string $action = null,
        ?int $templateId = null
    ): array
    {
        $limit = max(1, min(5000, $limit));
        $pageClause = $pageId !== null && $pageId > 0 ? ' AND jobs.page_id=:page_id' : '';
        $statusClause = $status !== null && $status !== '' ? ' AND jobs.status=:status' : '';
        $actionClause = $action !== null && $action !== '' ? ' AND jobs.action=:action' : '';
        $templateClause = $templateId !== null && $templateId > 0
            ? ' AND EXISTS (SELECT 1 FROM `pages` AS pages WHERE pages.id=jobs.page_id AND pages.templates_id=:template_id)'
            : '';
        $stmt = $this->db()->prepare(
            "SELECT jobs.* FROM `" . self::TABLE . "` AS jobs
             WHERE jobs.scheduled_at>=:from_date AND jobs.scheduled_at<:to_date$pageClause$statusClause$actionClause$templateClause
             ORDER BY jobs.scheduled_at ASC, jobs.id ASC LIMIT $limit"
        );
        $params = [
            ':from_date' => $fromUtc->format('Y-m-d H:i:s'),
            ':to_date' => $toUtc->format('Y-m-d H:i:s'),
        ];
        if ($pageClause !== '') {
            $params[':page_id'] = $pageId;
        }
        if ($statusClause !== '') {
            $params[':status'] = $status;
        }
        if ($actionClause !== '') {
            $params[':action'] = $action;
        }
        if ($templateClause !== '') {
            $params[':template_id'] = $templateId;
        }
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<int> */
    public function templateIdsBetween(
        \DateTimeImmutable $fromUtc,
        \DateTimeImmutable $toUtc,
        ?int $pageId = null,
        ?string $status = null,
        ?string $action = null
    ): array {
        $pageClause = $pageId !== null && $pageId > 0 ? ' AND jobs.page_id=:page_id' : '';
        $statusClause = $status !== null && $status !== '' ? ' AND jobs.status=:status' : '';
        $actionClause = $action !== null && $action !== '' ? ' AND jobs.action=:action' : '';
        $stmt = $this->db()->prepare(
            "SELECT DISTINCT pages.templates_id FROM `" . self::TABLE . "` AS jobs
             INNER JOIN `pages` AS pages ON pages.id=jobs.page_id
             WHERE jobs.scheduled_at>=:from_date AND jobs.scheduled_at<:to_date$pageClause$statusClause$actionClause
             ORDER BY pages.templates_id ASC LIMIT 500"
        );
        $params = [':from_date'=>$fromUtc->format('Y-m-d H:i:s'), ':to_date'=>$toUtc->format('Y-m-d H:i:s')];
        if ($pageClause !== '') $params[':page_id'] = $pageId;
        if ($statusClause !== '') $params[':status'] = $status;
        if ($actionClause !== '') $params[':action'] = $action;
        $stmt->execute($params);
        return array_values(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function counts(): array
    {
        $stmt = $this->db()->query(
            "SELECT status, COUNT(*) AS total FROM `" . self::TABLE . "` GROUP BY status"
        );
        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }
        return $counts;
    }

    public function claimDue(int $limit, int $staleMinutes): array
    {
        $db = $this->db();
        $now = gmdate('Y-m-d H:i:s');
        $stale = gmdate('Y-m-d H:i:s', time() - max(1, $staleMinutes) * 60);
        $recover = $db->prepare(
            "UPDATE `" . self::TABLE . "`
             SET status='scheduled', lock_token=NULL, locked_at=NULL, updated_at=:now,
                 last_error='Recovered stale worker lease'
             WHERE status='processing' AND locked_at<:stale"
        );
        $recover->execute([':now' => $now, ':stale' => $stale]);

        $limit = max(1, min(500, $limit));
        $select = $db->prepare(
            "SELECT id FROM `" . self::TABLE . "`
             WHERE status='scheduled' AND scheduled_at<=:now
             ORDER BY scheduled_at ASC, id ASC LIMIT $limit"
        );
        $select->execute([':now' => $now]);
        $claimed = [];

        foreach ($select->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $token = $this->uuid();
            $claim = $db->prepare(
                "UPDATE `" . self::TABLE . "`
                 SET status='processing', lock_token=:token, locked_at=:now,
                     attempts=attempts+1, updated_at=:now
                 WHERE id=:id AND status='scheduled' AND scheduled_at<=:now"
            );
            $claim->execute([':token' => $token, ':now' => $now, ':id' => (int) $id]);
            if ($claim->rowCount() === 1) {
                $row = $this->get((int) $id);
                if ($row) {
                    $claimed[] = $row;
                }
            }
        }

        return $claimed;
    }

    public function complete(int $id, string $token, string $executor): void
    {
        $stmt = $this->db()->prepare(
            "UPDATE `" . self::TABLE . "`
             SET status='completed', executor=:executor, completed_at=:now,
                 updated_at=:now, lock_token=NULL, locked_at=NULL, last_error=NULL
             WHERE id=:id AND status='processing' AND lock_token=:token"
        );
        $stmt->execute([
            ':executor' => mb_substr($executor, 0, 190),
            ':now' => gmdate('Y-m-d H:i:s'),
            ':id' => $id,
            ':token' => $token,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new WireException('Relay job lease was lost before completion.');
        }
    }

    public function fail(int $id, string $token, string $executor, string $error, int $maxAttempts): void
    {
        $job = $this->get($id);
        $status = $job && (int) $job['attempts'] < max(1, $maxAttempts) ? 'scheduled' : 'failed';
        $stmt = $this->db()->prepare(
            "UPDATE `" . self::TABLE . "`
             SET status=:status, executor=:executor, updated_at=:now,
                 lock_token=NULL, locked_at=NULL, last_error=:error
             WHERE id=:id AND status='processing' AND lock_token=:token"
        );
        $stmt->execute([
            ':status' => $status,
            ':executor' => mb_substr($executor, 0, 190),
            ':now' => gmdate('Y-m-d H:i:s'),
            ':error' => mb_substr($error, 0, 64000),
            ':id' => $id,
            ':token' => $token,
        ]);
    }

    private function db(): WireDatabasePDO
    {
        return $this->wire->wire('database');
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
