#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit(1);
}

$options = getopt('', ['root::', 'limit::', 'json', 'help']);
if (isset($options['help'])) {
    echo "Usage: php site/modules/Relay/bin/relay.php [--root=/path/to/site] [--limit=50] [--json]\n";
    exit(0);
}

$root = rtrim((string) ($options['root'] ?? getcwd()), DIRECTORY_SEPARATOR);
$bootstrap = $root . '/wire/core/ProcessWire.php';
if ($root === '' || !is_file($root . '/index.php') || !is_file($bootstrap)) {
    fwrite(STDERR, "ProcessWire root was not found. Run from the site root or pass --root.\n");
    exit(2);
}

chdir($root);
require_once $bootstrap;
$config = \ProcessWire\ProcessWire::buildConfig($root);
$processWire = new \ProcessWire\ProcessWire($config);

$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : null;
$json = isset($options['json']);
$modules = $processWire->wire('modules');
$module = $modules ? $modules->getModule('Relay', ['noPermissionCheck' => true]) : null;
if (!$module instanceof ProcessWire\Relay) {
    fwrite(STDERR, "Relay module is not installed.\n");
    exit(2);
}

$result = $module->runDue($limit, 'cli:' . php_uname('n') . ':' . getmypid());
if ($json) {
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo "Claimed: {$result['claimed']}; completed: {$result['completed']}; failed: {$result['failed']}\n";
}
exit($result['failed'] > 0 ? 1 : 0);
